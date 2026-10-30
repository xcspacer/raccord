<?php

namespace App\Http\Controllers;

use App\Audit\AuditScreenConfig;
use App\Audit\BoxName;
use App\Models\Audit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The page of a control point for the technician on site: like the box page, a shell filled by the phone.
 */
class AuditPointScreenController extends Controller
{
    public function __invoke(Request $request, string $cabinet, AuditScreenConfig $screen): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        Gate::forUser($user)->authorize('work', $audit);

        return view('audit.point-screen', ['cabinet' => $audit->cabinet_key, 'config' => $screen->for($audit, $user)]);
    }
}
