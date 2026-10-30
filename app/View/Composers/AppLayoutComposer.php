<?php

namespace App\View\Composers;

use App\Enums\Role;
use App\Navigation\MainMenu;
use App\Support\EffectiveAgency;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Data shared by the signed-in application layout: the "Fonctions" menu, the agency switcher and
 * the current user's role.
 */
class AppLayoutComposer
{
    public function __construct(
        private Request $request,
        private MainMenu $menu,
        private EffectiveAgency $agency,
    ) {}

    public function compose(View $view): void
    {
        $user = $this->request->user();

        if ($user === null) {
            return;
        }

        $view->with([
            'currentUser' => $user,
            'currentRole' => Role::tryFrom((string) $user->getRoleNames()->first()),
            'menuGroups' => $this->menu->for($user),
            'switchableAgencies' => $this->agency->canSwitch($user) ? $this->agency->agencies() : null,
            'currentAgencyId' => $this->agency->for($user)?->id,
        ]);
    }
}
