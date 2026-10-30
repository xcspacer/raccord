<?php

namespace Tests\Feature\TechnicianSpace;

use App\Enums\Feature;
use App\Enums\Role;
use App\Enums\VehiclePhotoSlot;
use App\Livewire\Admin\PlatformSettings;
use App\Livewire\TechnicianSpace\Space;
use App\Livewire\TechnicianSpace\VehicleInspection;
use App\Models\RoleFeatureOverride;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\VehicleDamage;
use App\Models\VehiclePhoto;
use App\Settings\VehicleSettings;
use App\Support\FeatureAccess;
use App\TechnicianSpace\VehicleCycle;
use Carbon\CarbonImmutable;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class VehicleInspectionTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed([RoleSeeder::class, PublicHolidaySeeder::class]);
        $this->technician = User::factory()->withRole(Role::Technician)->create();
    }

    private function enableCycle(?string $month = '2026-11'): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));

        $settings = app(VehicleSettings::class);
        $settings->cycle_start_month = $month;
        $settings->save();
    }

    private function addPhotos(int $count, string $month = '2026-11'): void
    {
        $unique = hash('sha256', uniqid('', true));
        $file = StoredFile::query()->create(['sha256' => $unique, 'path' => 'files/'.$unique.'.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);

        foreach (array_slice(VehiclePhotoSlot::cases(), 0, $count) as $slot) {
            VehiclePhoto::query()->create(['user_id' => $this->technician->id, 'slot' => $slot, 'stored_file_id' => $file->id, 'month' => $month, 'taken_at' => now()]);
        }
    }

    public function test_a_photo_is_stored_downscaled_without_metadata_and_replaces_the_previous_one(): void
    {
        $component = Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->set('photo', UploadedFile::fake()->image('a.jpg', 3200, 2400))
            ->call('storePhoto', 'front', ['lat' => 43.93, 'lng' => 2.14])
            ->assertHasNoErrors()->assertDispatched('toast');

        $first = VehiclePhoto::query()->sole();
        $this->assertSame(now(config('platform.timezone'))->format('Y-m'), $first->month);
        $this->assertSame('43.930000', $first->latitude);

        $file = StoredFile::query()->findOrFail($first->stored_file_id);
        $this->assertSame(2560, $file->width);
        $this->assertSame('image/jpeg', $file->mime_type);
        Storage::disk('local')->assertExists([$file->path, $file->thumbnail_path]);
        $this->assertStringNotContainsString('Exif', (string) Storage::disk('local')->get($file->path));

        $component->set('photo', UploadedFile::fake()->image('b.jpg', 800, 600))->call('storePhoto', 'front', []);

        $this->assertSame(2, VehiclePhoto::query()->count());
        $this->assertSame(1, VehiclePhoto::query()->whereNull('archived_at')->count());
        $this->assertSame(1, VehiclePhoto::query()->whereNotNull('archived_at')->count());
    }

    public function test_invalid_slots_files_and_positions_are_refused(): void
    {
        $component = Livewire::actingAs($this->technician)->test(VehicleInspection::class);

        $component->set('photo', UploadedFile::fake()->image('a.jpg'))->call('storePhoto', 'roof', [])->assertNotFound();

        Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->set('photo', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->call('storePhoto', 'front', [])->assertHasErrors('photo');
        Livewire::actingAs($this->technician)->test(VehicleInspection::class)->set('photo', UploadedFile::fake()->createWithContent('photo.jpg', '<html><script>alert(1)</script></html>'))->call('storePhoto', 'front', [])->assertHasErrors('photo');
        Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->set('photo', UploadedFile::fake()->image('a.jpg'))->call('storePhoto', 'front', ['lat' => 500])->assertHasErrors('lat');

        $this->assertDatabaseCount('vehicle_photos', 0);
    }

    public function test_a_photo_can_be_removed_and_stays_in_the_history(): void
    {
        $this->addPhotos(1);

        Livewire::actingAs($this->technician)->test(VehicleInspection::class)->call('removePhoto', 'front');

        $this->assertNotNull(VehiclePhoto::query()->sole()->archived_at);
    }

    public function test_damages_need_something_and_are_listed_and_removable_by_their_owner_only(): void
    {
        Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->call('addDamage')->assertHasErrors('damageDetails')
            ->set('damageType', 'scratch')->set('damageDetails', 'Left door')->set('damagePhoto', UploadedFile::fake()->image('d.jpg'))
            ->call('addDamage')->assertHasNoErrors()->assertSee('Left door');

        $damage = VehicleDamage::query()->sole();
        $this->assertNotNull($damage->stored_file_id);
        $this->assertSame(now(config('platform.timezone'))->toDateString(), $damage->occurred_on->toDateString());

        Livewire::actingAs(User::factory()->withRole(Role::Technician)->create())->test(VehicleInspection::class)->call('removeDamage', $damage->id);
        $this->assertDatabaseCount('vehicle_damages', 1);

        Livewire::actingAs($this->technician)->test(VehicleInspection::class)->call('removeDamage', $damage->id);
        $this->assertDatabaseCount('vehicle_damages', 0);
    }

    public function test_images_are_private_to_the_owner_and_managers(): void
    {
        Livewire::actingAs($this->technician)->test(VehicleInspection::class)->set('photo', UploadedFile::fake()->image('a.jpg'))->call('storePhoto', 'front', []);
        $photo = VehiclePhoto::query()->sole();
        $url = route('vehicle-images.show', ['type' => 'photo', 'id' => $photo->id, 'variant' => 'thumb']);

        $this->actingAs($this->technician)->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($url)->assertNotFound();
    }

    public function test_the_cycle_is_off_until_a_start_month_is_set(): void
    {
        $this->assertSame('inactive', app(VehicleCycle::class)->state($this->technician)['status']);

        $this->enableCycle('2026-12');
        $this->assertSame('inactive', app(VehicleCycle::class)->state($this->technician)['status']);
    }

    public function test_the_deadline_is_three_days_after_the_first_working_day(): void
    {
        $this->enableCycle();
        $cycle = app(VehicleCycle::class);

        // 2026-11-01 is a Sunday: the first working day is Monday 2 (3 is Tuesday, deadline Thursday 5).
        $this->assertSame(['status' => 'due', 'days' => 1], $cycle->state($this->technician, CarbonImmutable::parse('2026-11-04', 'Europe/Paris')));
        $this->assertSame('due', $cycle->state($this->technician, CarbonImmutable::parse('2026-11-05', 'Europe/Paris'))['status']);
        $this->assertSame(['status' => 'blocked', 'days' => 1], $cycle->state($this->technician, CarbonImmutable::parse('2026-11-06', 'Europe/Paris')));

        $this->addPhotos(4);
        $this->assertSame('blocked', $cycle->state($this->technician, CarbonImmutable::parse('2026-11-06', 'Europe/Paris'))['status']);

        $this->addPhotos(5, '2026-10');
        $this->addPhotos(5);
        $this->assertSame('done', $cycle->state($this->technician, CarbonImmutable::parse('2026-11-06', 'Europe/Paris'))['status']);
    }

    public function test_a_blocked_technician_can_only_reach_the_vehicle_page(): void
    {
        $this->enableCycle();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Europe/Paris'));

        $this->actingAs($this->technician)->get(route('home'))->assertRedirect(route('technician-space', ['tab' => 'vehicle']));
        $this->actingAs($this->technician)->get(route('planning.tasks'))->assertRedirect(route('technician-space', ['tab' => 'vehicle']));
        $this->actingAs($this->technician)->get(route('technician-space', ['tab' => 'vehicle']))->assertOk();

        Livewire::actingAs($this->technician)->test(Space::class)->assertSet('tab', 'vehicle')->call('setTab', 'daily-return')->assertSet('tab', 'vehicle');

        $this->addPhotos(5);
        $this->actingAs($this->technician)->get(route('home'))->assertOk();
    }

    public function test_a_technician_without_my_space_is_never_blocked_by_the_vehicle_photos(): void
    {
        $this->enableCycle();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Europe/Paris'));
        RoleFeatureOverride::query()->create(['feature' => Feature::TechnicianSpace, 'role_id' => RoleModel::findByName(Role::Technician->value)->id, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();

        $this->actingAs($this->technician)->get(route('home'))->assertOk();
        $this->actingAs($this->technician)->get(route('planning.tasks'))->assertOk();
        $this->actingAs($this->technician)->get(route('technician-space'))->assertForbidden();
    }

    public function test_the_start_month_is_set_from_the_platform_settings_by_administrators(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();

        Livewire::actingAs($admin)->test(PlatformSettings::class)->set('vehicleCycleStart', '2026-12')->call('save')->assertHasNoErrors();
        $this->assertSame('2026-12', app(VehicleSettings::class)->cycle_start_month);

        Livewire::actingAs($admin)->test(PlatformSettings::class)->set('vehicleCycleStart', 'soon')->call('save')->assertHasErrors('vehicleCycleStart');
        Livewire::actingAs($admin)->test(PlatformSettings::class)->set('vehicleCycleStart', '')->call('save');
        $this->assertNull(app(VehicleSettings::class)->cycle_start_month);
    }

    public function test_the_five_slots_are_listed(): void
    {
        Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->assertSee(__('enums.vehicle_photo_slots.front'))->assertSee(__('enums.vehicle_photo_slots.dashboard'))->assertSee(__('vehicle_inspection.no_photo'));
    }
}
