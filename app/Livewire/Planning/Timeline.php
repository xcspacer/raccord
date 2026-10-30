<?php

namespace App\Livewire\Planning;

use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\WorkSiteStatus;
use App\Livewire\WorkSites\WorkSiteEditor;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\CapacityCalculator;
use App\Planning\TimelineBuilder;
use App\Support\EffectiveAgency;
use App\Support\Today;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Planning »: the timeline of an agency that plans in detail, with its crew capacity, the overbooking
 * alert and the technicians' absences.
 */
class Timeline extends Component
{
    private const string ABSENCES_FILTER = 'absences';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', WorkSite::class);

        $agency = $this->agency();

        if ($agency?->planning_mode !== PlanningMode::Detailed) {
            $this->redirectRoute('planning.tasks', navigate: true);

            return;
        }

    }

    public function updatedStatus(): void
    {
        if ($this->status === self::ABSENCES_FILTER && Route::has('absences.index')) {
            $this->status = '';
            $this->redirectRoute('absences.index', navigate: true);
        }
    }

    public function openEditor(?int $id = null): void
    {
        $this->dispatch('work-site-open', id: $id)->to(WorkSiteEditor::class);
    }

    #[On('work-site-saved')]
    public function refreshTimeline(): void
    {
        // Re-render with the saved work site.
    }

    public function render(CapacityCalculator $calculator, TimelineBuilder $builder): View
    {
        $agency = $this->agency();
        abort_if($agency === null, 404);

        $today = Today::date();
        $search = trim($this->search);

        $sites = WorkSite::query()
            ->where('agency_id', $agency->id)
            ->with('client')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('city', 'like', '%'.$search.'%')
                ->orWhereHas('client', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))))
            ->when(WorkSiteStatus::tryFrom($this->status), fn (Builder $query, WorkSiteStatus $status) => $query->where('status', $status->value))
            ->get();

        $technicians = User::query()
            ->where(fn (Builder $query) => $query->where('is_plannable', true)->orWhereHas('roles', fn (Builder $query) => $query->where('name', 'technician')))
            ->where('is_hidden_from_planning', false)
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>', $today))
            ->where(fn (Builder $query) => $query->where('agency_id', $agency->id)->orWhere('all_agencies', true))
            ->get();

        $overbooked = $calculator->overbookedDays($agency);
        $user = auth()->user();

        return view('livewire.planning.timeline', [
            'agency' => $agency,
            'timeline' => $builder->build($sites, $overbooked, $agency->daily_crew_capacity, $today, $technicians),
            'statuses' => [WorkSiteStatus::Forecast, WorkSiteStatus::Confirmed],
            'canCreate' => $user?->can('create', WorkSite::class) === true,
            'canSeeAbsences' => $user?->can('use-feature', Feature::Absences) === true && Route::has('absences.index'),
        ])->layout('components.layouts.app', ['title' => __('planning.title'), 'page' => 'planning']);
    }

    private function agency(): ?Agency
    {
        $user = auth()->user();

        return $user instanceof User ? app(EffectiveAgency::class)->for($user) : null;
    }
}
