<?php

namespace App\Livewire\WorkSites;

use App\Enums\PlanningMode;
use App\Enums\WorkSiteStatus;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\CapacityCalculator;
use App\Support\EffectiveAgency;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Liste » (agencies planning in detail) / « Production » (agencies planning day by day): the agency's
 * work sites. Creating and editing happen in the work site window.
 */
class WorkSiteIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    /**
     * The list is for managers; someone who may only create work sites (the design office with « Nouveau
     * chantier ») only gets the work site window.
     */
    public function mount(): void
    {
        $this->authorize(Route::currentRouteName() === 'work-sites.create' && Gate::denies('viewAny', WorkSite::class) ? 'create' : 'viewAny', WorkSite::class);
    }

    public function openEditor(?int $id = null): void
    {
        $this->dispatch('work-site-open', id: $id)->to(WorkSiteEditor::class);
    }

    public function delete(int $id): void
    {
        $site = $this->scopedWorkSites()->findOrFail($id);
        $this->authorize('delete', $site);

        DB::transaction(function () use ($site): void {
            $site->tasks()->delete();
            $site->delete();
        });

        $this->dispatch('toast', message: __('work_sites.deleted'));
    }

    #[On('work-site-saved')]
    public function refreshList(): void
    {
        // Re-render the list with the saved work site.
    }

    public function render(CapacityCalculator $capacity): View
    {
        $agency = $this->effectiveAgency();
        $search = trim($this->search);

        $canList = Gate::allows('viewAny', WorkSite::class);
        $sites = ! $canList ? collect() : $this->scopedWorkSites()
            ->with(['client', 'agency'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('city', 'like', '%'.$search.'%')
                ->orWhereHas('client', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))))
            ->when(WorkSiteStatus::tryFrom($this->status), fn (Builder $query, WorkSiteStatus $status) => $query->where('status', $status->value))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $creating = Route::currentRouteName() === 'work-sites.create';

        return view('livewire.work-sites.work-site-index', [
            'sites' => $sites,
            'canList' => $canList,
            'overbooked' => $agency !== null ? $capacity->overbookedDays($agency) : [],
            'capacity' => $capacity,
            'statuses' => WorkSiteStatus::cases(),
            'canCreate' => auth()->user()?->can('create', WorkSite::class) === true && $agency !== null,
            'openOnLoad' => $creating,
        ])->layout('components.layouts.app', [
            'title' => $creating ? __('work_sites.create_title') : __($agency?->planning_mode === PlanningMode::Daily ? 'work_sites.title_production' : 'work_sites.title'),
            'page' => 'work-sites',
        ]);
    }

    /**
     * @return Builder<WorkSite>
     */
    private function scopedWorkSites(): Builder
    {
        return WorkSite::query()->when($this->effectiveAgency(), fn (Builder $query, Agency $agency) => $query->where('agency_id', $agency->id));
    }

    private function effectiveAgency(): ?Agency
    {
        $user = auth()->user();

        return $user instanceof User ? app(EffectiveAgency::class)->for($user) : null;
    }
}
