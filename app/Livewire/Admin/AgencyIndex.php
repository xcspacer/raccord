<?php

namespace App\Livewire\Admin;

use App\Actions\Planning\ResolveClient;
use App\Enums\PlanningMode;
use App\Models\Agency;
use App\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Agences »: the agencies of the company and how each one plans (crews and capacity over several
 * days, or one-day work sites with named technicians).
 */
class AgencyIndex extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $mode = 'detailed';

    public string $capacity = '4';

    public string $defaultClient = '';

    public function mount(): void
    {
        $this->authorize('manage-platform');
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-platform');

        $agency = Agency::query()->with('defaultClient')->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $agency->id;
        $this->name = $agency->name;
        $this->mode = $agency->planning_mode->value;
        $this->capacity = (string) $agency->daily_crew_capacity;
        $this->defaultClient = (string) $agency->defaultClient?->name;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(ResolveClient $clients): void
    {
        $this->authorize('manage-platform');

        $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('agencies', 'name')->ignore($this->editingId)->whereNull('deleted_at')],
            'mode' => ['required', Rule::enum(PlanningMode::class)],
            'capacity' => ['required', 'integer', 'between:1,255'],
            'defaultClient' => ['nullable', 'string', 'max:160'],
        ]);

        $mode = PlanningMode::from($this->mode);
        $agency = $this->editingId === null ? null : Agency::query()->findOrFail($this->editingId);

        if ($agency !== null && $agency->planning_mode !== $mode && $agency->workSites()->exists()) {
            throw ValidationException::withMessages(['mode' => __('agencies.mode_locked')]);
        }

        $attributes = [
            'name' => trim($this->name),
            'planning_mode' => $mode,
            'daily_crew_capacity' => (int) $this->capacity,
            'default_client_id' => trim($this->defaultClient) === '' ? null : $clients->handle($this->defaultClient)->id,
        ];

        if ($agency === null) {
            Agency::query()->create($attributes + ['code' => $this->uniqueCode(trim($this->name)), 'sort_order' => ((int) Agency::query()->max('sort_order')) + 1]);
        } else {
            $agency->update($attributes);
        }

        $this->resetForm();
        $this->dispatch('toast', message: __('agencies.saved'));
    }

    public function move(int $id, int $direction): void
    {
        $this->authorize('manage-platform');

        $agencies = Agency::query()->orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $agencies->search(fn (Agency $agency) => $agency->id === $id);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target === null || ! isset($agencies[$target])) {
            return;
        }

        $reordered = $agencies->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $agency) {
            $agency->update(['sort_order' => $position + 1]);
        }
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-platform');

        $agency = Agency::query()->findOrFail($id);

        if (Agency::query()->count() <= 1) {
            $this->addError('delete', __('agencies.last_agency'));

            return;
        }

        if ($agency->users()->exists() || $agency->workSites()->exists()) {
            $this->addError('delete', __('agencies.in_use', ['name' => $agency->name]));

            return;
        }

        $agency->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->dispatch('toast', message: __('agencies.deleted'));
    }

    public function render(): View
    {
        return view('livewire.admin.agency-index', [
            'agencies' => Agency::query()->with('defaultClient')->withCount(['users', 'workSites'])->orderBy('sort_order')->orderBy('id')->get(),
            'modes' => PlanningMode::cases(),
            'clients' => Client::query()->where('is_active', true)->orderBy('name')->pluck('name'),
        ])->layout('components.layouts.app', ['title' => __('agencies.title'), 'page' => 'agencies']);
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'agency', 28, '');
        $code = $base;

        for ($suffix = 2; Agency::withTrashed()->where('code', $code)->exists(); $suffix++) {
            $code = $base.'-'.$suffix;
        }

        return $code;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'mode', 'capacity', 'defaultClient');
        $this->resetValidation();
    }
}
