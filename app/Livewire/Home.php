<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\User;
use App\Models\WorkSite;
use App\Navigation\MainMenu;
use App\Navigation\MenuGroup;
use App\Navigation\MenuItem;
use App\Support\EffectiveAgency;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Home page (Accueil). On phones the "Fonctions" menu is part of the page; on wide screens it is the
 * fixed side menu. Managers also get the capacity box, the overbooking alert and the work site map.
 * The design office has no home page: it lands on the audits, or on the first of its tools it may use.
 */
class Home extends Component
{
    /**
     * Pages the design office lands on, in order of preference.
     */
    private const array DESIGN_OFFICE_LANDINGS = ['audits_full', 'audits_optical', 'expense_reports', 'fibre_code', 'otdr_acceptance', 'media_library', 'client_control'];

    public function mount(MainMenu $menu): mixed
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasRole(Role::DesignOffice->value)) {
            return null;
        }

        $items = collect($menu->for($user))->flatMap(fn (MenuGroup $group) => $group->items)->keyBy(fn (MenuItem $item) => $item->key);
        $landing = collect(self::DESIGN_OFFICE_LANDINGS)->first(fn (string $key) => $items->has($key));

        return $landing === null ? null : $this->redirectRoute($items[$landing]->route, navigate: true);
    }

    public function render(MainMenu $menu, EffectiveAgency $agency): View
    {
        $user = auth()->user();
        $isManager = $user?->can('viewAny', WorkSite::class) === true;

        return view('livewire.home', [
            'menuGroups' => $menu->for($user),
            'switchableAgencies' => $agency->canSwitch($user) ? $agency->agencies() : null,
            'currentAgencyId' => $agency->for($user)?->id,
            'showPlanningPanels' => $isManager && Route::has('map.work-sites'),
            'mapLabels' => __('map'),
        ])->layout('components.layouts.app', ['title' => __('app.home'), 'page' => 'home']);
    }
}
