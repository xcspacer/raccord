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
 * The page of a box for the technician on site. It holds no data of the audit: the phone fills it from what it keeps
 * locally, so the same page, once loaded, serves every box with or without a network.
 */
class AuditBoxScreenController extends Controller
{
    public function __invoke(Request $request, string $cabinet, AuditScreenConfig $screen): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        Gate::forUser($user)->authorize('work', $audit);

        return view('audit.box-screen', ['cabinet' => $audit->cabinet_key, 'config' => $screen->for($audit, $user)]);
    }
}
