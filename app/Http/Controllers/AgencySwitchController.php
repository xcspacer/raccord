<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Support\EffectiveAgency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AgencySwitchController extends Controller
{
    /**
     * Agency switcher (administrators and users working for every agency).
     */
    public function __invoke(Request $request, Agency $agency, EffectiveAgency $effectiveAgency): RedirectResponse
    {
        Gate::authorize('switch-agency');

        $effectiveAgency->switchTo($request->user(), $agency);

        return back();
    }
}
