<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureIsAllowed
{
    /**
     * Usage: ->middleware('feature:absences'). The user must be able to open the feature: its "Gérer les accès" switch
     * and the agency rules of its menu entry. Also re-applied to every Livewire update of the page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Gate::allows('open-feature', Feature::from($feature)), 403);

        return $next($request);
    }
}
