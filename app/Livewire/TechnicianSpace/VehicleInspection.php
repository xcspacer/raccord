<?php

namespace App\Livewire\TechnicianSpace;

use App\Actions\Files\StoreUploadedFile;
use App\Actions\Files\UploadRules;
use App\Enums\VehicleDamageType;
use App\Enums\VehiclePhotoSlot;
use App\Jobs\CompareVehiclePhotos;
use App\Models\User;
use App\Models\VehicleDamage;
use App\Models\VehiclePhoto;
use App\TechnicianSpace\VehicleCycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Monthly vehicle inspection of the signed-in technician: the five photos taken with the in-app camera and
 * the damages noted on the vehicle.
 */
class VehicleInspection extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $photo = null;

    public ?TemporaryUploadedFile $damagePhoto = null;

    public string $damageType = '';

    public string $damageDetails = '';

    /**
     * @param  array{lat?: float|null, lng?: float|null, accuracy?: float|null}  $geo
     */
    public function storePhoto(string $slot, array $geo, StoreUploadedFile $files): void
    {
        $this->authorize('create', VehiclePhoto::class);
        $slot = VehiclePhotoSlot::tryFrom($slot);
        abort_if($slot === null, 404);

        $this->validate(['photo' => ['required', ...UploadRules::photo(15360)]]);
        $geo = validator($geo, ['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180']])->validate();

        try {
            $stored = $files->handleImage($this->photo);
        } catch (InvalidArgumentException) {
            $this->addError('photo', __('vehicle_inspection.invalid_image'));

            return;
        }

        $user = $this->user();
        $now = CarbonImmutable::now();

        $replaced = null;

        $created = DB::transaction(function () use ($user, $slot, $stored, $geo, $now, &$replaced): VehiclePhoto {
            $replaced = VehiclePhoto::query()->where('user_id', $user->id)->where('slot', $slot->value)->whereNull('archived_at')->latest('id')->first();
            VehiclePhoto::query()->where('user_id', $user->id)->where('slot', $slot->value)->whereNull('archived_at')->update(['archived_at' => $now]);

            return VehiclePhoto::query()->create([
                'user_id' => $user->id, 'slot' => $slot, 'stored_file_id' => $stored->id,
                'month' => $now->setTimezone(config('platform.timezone'))->format('Y-m'), 'taken_at' => $now,
                'latitude' => $geo['lat'] ?? null, 'longitude' => $geo['lng'] ?? null,
            ]);
        });

        if ($replaced !== null && $replaced->stored_file_id !== $created->stored_file_id) {
            CompareVehiclePhotos::dispatchAfterResponse($user->id, $replaced->id, $created->id);
        }

        $this->photo = null;
        $this->dispatch('toast', message: __('vehicle_inspection.photo_saved'));
        $this->dispatch('vehicle-cycle-changed');
    }

    public function removePhoto(string $slot): void
    {
        $slot = VehiclePhotoSlot::tryFrom($slot);
        abort_if($slot === null, 404);

        VehiclePhoto::query()->where('user_id', $this->user()->id)->where('slot', $slot->value)->whereNull('archived_at')->update(['archived_at' => now()]);
        $this->dispatch('vehicle-cycle-changed');
    }

    /**
     * The damage photo was just taken: nothing to do but show it in the form.
     *
     * @param  array<string, mixed>  $geo
     */
    public function damagePhotoReady(array $geo): void
    {
        $this->validate(['damagePhoto' => ['required', ...UploadRules::photo(15360)]]);
    }

    public function addDamage(StoreUploadedFile $files): void
    {
        $this->authorize('create', VehicleDamage::class);
        $this->validate([
            'damageType' => ['nullable', Rule::enum(VehicleDamageType::class)],
            'damageDetails' => ['nullable', 'string', 'max:500'],
            'damagePhoto' => ['nullable', ...UploadRules::photo(15360)],
        ]);

        if ($this->damagePhoto === null && $this->damageType === '' && trim($this->damageDetails) === '') {
            $this->addError('damageDetails', __('vehicle_inspection.damage_required'));

            return;
        }

        try {
            $stored = $this->damagePhoto !== null ? $files->handleImage($this->damagePhoto) : null;
        } catch (InvalidArgumentException) {
            $this->addError('damagePhoto', __('vehicle_inspection.invalid_image'));

            return;
        }

        VehicleDamage::query()->create([
            'user_id' => $this->user()->id,
            'stored_file_id' => $stored?->id,
            'type' => $this->damageType === '' ? null : $this->damageType,
            'details' => trim($this->damageDetails) ?: null,
            'occurred_on' => CarbonImmutable::now(config('platform.timezone'))->toDateString(),
        ]);

        $this->reset('damagePhoto', 'damageType', 'damageDetails');
        $this->dispatch('toast', message: __('vehicle_inspection.damage_saved'));
    }

    public function removeDamage(int $id): void
    {
        VehicleDamage::query()->where('user_id', $this->user()->id)->whereKey($id)->delete();
    }

    public function render(): View
    {
        $user = $this->user();
        $month = CarbonImmutable::now(config('platform.timezone'))->format('Y-m');

        return view('livewire.technician-space.vehicle-inspection', [
            'photos' => VehiclePhoto::query()->where('user_id', $user->id)->whereNull('archived_at')->get()->keyBy(fn (VehiclePhoto $photo) => $photo->slot->value),
            'alerts' => VehiclePhoto::query()->where('user_id', $user->id)->whereNotNull('archived_at')->where('archived_at', '>=', now()->subDays(30))
                ->where('comparison->damage_detected', true)->latest('archived_at')->get(),
            'damages' => VehicleDamage::query()->where('user_id', $user->id)->latest('occurred_on')->latest('id')->get(),
            'photoSlots' => VehiclePhotoSlot::cases(),
            'damageTypes' => VehicleDamageType::cases(),
            'currentMonth' => $month,
            'cycle' => app(VehicleCycle::class)->state($user),
            'camera' => [
                'labels' => __('vehicle_inspection.camera') + ['reverse_url' => route('geo.reverse')],
                'name' => $user->name,
                'timeZone' => config('platform.timezone'),
                'allowWithoutLocation' => false,
            ],
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
