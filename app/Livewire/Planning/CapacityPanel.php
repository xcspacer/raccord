<?php

namespace App\Livewire\Planning;

use App\Enums\PlanningMode;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\CapacityCalculator;
use App\Support\EffectiveAgency;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * « Équipes disponibles » box and the overbooking alert of the active agency (agencies that plan in detail,
 * for managers). Only the administrator changes the capacity. On the planning pages the box sits in the page header
 * and the alert above the content: each place renders one part.
 */
class CapacityPanel extends Component
{
    public string $capacity = '';

    /** Shown in the side menu (Accueil) instead of above a page. */
    public bool $inMenu = false;

    /**
     * Part rendered here: both, only the box (page header) or only the alert (page content).
     *
     * @var 'all'|'box'|'alert'
     */
    #[Locked]
    public string $part = 'all';

    public function mount(): void
    {
        $this->capacity = (string) $this->agency()?->daily_crew_capacity;
    }

    public function updatedCapacity(): void
    {
        $this->authorize('manage-platform');

        $agency = $this->agency();
        abort_if($agency === null, 404);

        $value = max(1, min(255, (int) $this->capacity));
        $agency->update(['daily_crew_capacity' => $value]);
        $this->capacity = (string) $value;
        $this->dispatch('capacity-changed');
    }

    #[On('work-site-saved'), On('capacity-changed')]
    public function refreshPanel(): void
    {
        $this->capacity = (string) $this->agency()?->daily_crew_capacity;
    }

    public function render(CapacityCalculator $calculator): View
    {
        $agency = $this->agency();
        $user = auth()->user();
        $visible = $agency !== null && $agency->planning_mode === PlanningMode::Detailed && $user?->can('viewAny', WorkSite::class) === true;

        return view('livewire.planning.capacity-panel', [
            'visible' => $visible,
            'agency' => $agency,
            'ranges' => $visible ? $calculator->overbookedRanges($agency) : [],
            'canEdit' => $user?->can('manage-platform') === true,
            'showBox' => $this->part !== 'alert',
            'showAlert' => $this->part !== 'box',
        ]);
    }

    private function agency(): ?Agency
    {
        $user = auth()->user();

        return $user instanceof User ? app(EffectiveAgency::class)->for($user) : null;
    }
}
