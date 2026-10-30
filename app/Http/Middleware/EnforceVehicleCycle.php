<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Enums\Role;
use App\Models\User;
use App\TechnicianSpace\VehicleCycle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A technician whose monthly vehicle photos are overdue can only reach « Mon espace » (to add them) and sign out.
 * Without « Mon espace » the photos cannot be added, so the technician is never blocked.
 */
class EnforceVehicleCycle
{
    /** Routes that stay reachable while blocked. */
    private const array ALLOWED = ['technician-space', 'logout', 'locale.update', 'geo.reverse', 'vehicle-images.show', 'web-manifest', 'branding.logo', 'day-punch'];

    public function __construct(private VehicleCycle $cycle) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->isMethod('GET') && ! $request->expectsJson() && ! $request->routeIs(...self::ALLOWED)
            && $user instanceof User && $user->hasRole(Role::Technician->value) && Gate::forUser($user)->allows('use-feature', Feature::TechnicianSpace)
            && $this->cycle->isBlocked($user)) {
            return redirect()->route('technician-space', ['tab' => 'vehicle']);
        }

        return $next($request);
    }
}
