<?php

namespace App\Http\Controllers;

use App\Audit\AuditSnapshot;
use App\Audit\BoxName;
use App\Models\Audit;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The audit in one JSON document for the phones; answers « unchanged » when the phone already has the revision.
 */
class AuditSnapshotController extends Controller
{
    public function __invoke(Request $request, string $cabinet, AuditSnapshot $snapshot): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->first();
        $access = $audit === null ? Response::denyAsNotFound() : Gate::forUser($user)->inspect('work', $audit);

        if ($audit === null || $access->status() === 404) {
            return response()->json(['ok' => false, 'error' => 'audit_not_found'], 404);
        }

        $access->authorize();

        if ($request->query('revision') !== null && (int) $request->query('revision') === $audit->revision) {
            return response()->json(['ok' => true, 'unchanged' => true, 'revision' => $audit->revision])->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['ok' => true] + $snapshot->for($audit, $user))->header('Cache-Control', 'private, no-store');
    }
}
