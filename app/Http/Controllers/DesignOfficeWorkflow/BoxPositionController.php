<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxPositions;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\ProposePositionRequest;
use App\Models\AuditBox;
use App\Models\AuditBoxPosition;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Positions of a box for the phone (JSON, design office workflow).
 *
 * GET  design-office/boxes/{box}/positions          → {official, map_origin, field, proposals, navigation}
 *      `official` is the point « J'y vais » leads to, `navigation` its links per app (config audit.navigation, the
 *      choice made last time is remembered on the phone as for the other « J'y vais »). A technician sees their own
 *      proposals, the design office and the supervisors every one.
 * POST design-office/boxes/{box}/positions/propose  {latitude, longitude, accuracy?} → 201 {duplicate, position}
 *      (200 with duplicate=true when the same point already waits). Refusals: WorkflowException JSON
 *      ({reason, message, context}) — 409 audit_closed / box_removed, 422 position_invalid.
 */
class BoxPositionController extends Controller
{
    public function show(Request $request, AuditBox $box, BoxPositions $positions): JsonResponse
    {
        Gate::authorize('viewWorkflow', $box);
        $user = $this->user($request);
        $summary = $positions->summary($box);
        $office = WorkflowRoles::isReviewer($user) || WorkflowRoles::isSupervisor($user);

        return response()->json([
            'official' => $this->position($summary['official']),
            'map_origin' => $this->position($summary['map_origin']),
            'field' => $this->position($summary['field']),
            'proposals' => array_values(array_map(fn (AuditBoxPosition $position) => $this->position($position), array_filter(
                $summary['proposals'],
                fn (AuditBoxPosition $position) => $office ? $position->isPending() : $position->recorded_by === $user->id,
            ))),
            'navigation' => $summary['official'] === null ? null : BoxPositions::navigationLinks($summary['official']),
        ]);
    }

    public function propose(ProposePositionRequest $request, AuditBox $box, BoxPositions $positions): JsonResponse
    {
        Gate::authorize('proposePosition', $box);
        $result = $positions->propose($this->user($request), $box, $request->validated('latitude'), $request->validated('longitude'), $request->validated('accuracy'));

        return response()->json(['duplicate' => $result['duplicate'], 'position' => $this->position($result['position'])], $result['duplicate'] ? 200 : 201);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function position(?AuditBoxPosition $position): ?array
    {
        if ($position === null) {
            return null;
        }

        return [
            'id' => $position->id,
            'source' => $position->source->value,
            'latitude' => (float) $position->latitude,
            'longitude' => (float) $position->longitude,
            'accuracy' => $position->accuracy,
            'at' => $position->created_at->toIso8601String(),
            'state' => match (true) {
                $position->approved_at !== null => 'approved',
                $position->rejected_at !== null => 'rejected',
                default => 'pending',
            },
            'rejection_reason' => $position->rejection_reason,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
