<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxStatus;
use App\DesignOfficeWorkflow\RepairPlanning;
use App\DesignOfficeWorkflow\SubmitRepairs;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\SubmitRepairsRequest;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What the phone does in phase 2 of the design office workflow (JSON). The person is always the signed-in user;
 * refusals answer with their status and a stable reason (see WorkflowException).
 *
 * - `GET design-office/interventions` (design-office.interventions.index): the open repair interventions the
 *   technician belongs to — `interventions[]` with `id`, `status`, `planned_on` (Y-m-d), `note`, `is_responsible`,
 *   `responsible {id, name}`, `members[] {id, name}`, `box {id, name, repair_state}`, `cabinet`, `repairs[]`
 *   (`id`, `reference`, `nature`, `state`, `type`, `description`) still to repair or waiting for the decision.
 * - `POST design-office/boxes/{box}/submit-repairs` (design-office.boxes.submit-repairs): `operation_id` (required,
 *   `[A-Za-z0-9_-]{1,64}`, a resent one answers the first submission with `duplicate: true` and 200),
 *   `attach_recheck` (optional boolean: attach the saved recheck of the box). Answers 201 with `request_id`,
 *   `deadline_at` and the box `status` (as `GET design-office/boxes/{box}`). Refusals: 403 `not_responsible`, 409
 *   `no_intervention` / `blocked_by_pending` / `audit_closed`, 422 `nothing_to_submit` / `no_recheck`.
 * - `GET design-office/boxes/{box}` (BoxWorkflowController) also carries `repairs`: the open intervention of the box
 *   and the verdict of each repair of the latest repairs decision.
 */
class RepairWorkflowController extends Controller
{
    public function interventions(Request $request, RepairPlanning $planning): JsonResponse
    {
        Gate::authorize('viewOwn', AuditIntervention::class);
        $user = $this->user($request);

        return response()->json([
            'interventions' => $planning->interventionsOf($user)->map(fn (AuditIntervention $intervention) => [
                'id' => $intervention->id,
                'status' => $intervention->status->value,
                'status_label' => $intervention->status->label(),
                'planned_on' => $intervention->planned_on->toDateString(),
                'note' => $intervention->note,
                'is_responsible' => $intervention->box->responsible_id === $user->id,
                'responsible' => ['id' => $intervention->responsible->id, 'name' => $intervention->responsible->name],
                'members' => $intervention->members->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])->values()->all(),
                'cabinet' => $intervention->audit->cabinet_key,
                'box' => ['id' => $intervention->box->id, 'name' => $intervention->box->name, 'repair_state' => $intervention->box->repair_state->value],
                'repairs' => $planning->openRepairs($intervention->box)->map(fn (AuditOccurrence $occurrence) => [
                    'id' => $occurrence->id,
                    'reference' => $occurrence->reference,
                    'nature' => $occurrence->nature->value,
                    'state' => $occurrence->state->value,
                    'type' => $occurrence->type->displayLabel(),
                    'description' => $occurrence->description,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function submit(SubmitRepairsRequest $request, AuditBox $box, SubmitRepairs $submit, BoxStatus $status): JsonResponse
    {
        Gate::authorize('submitRepairs', $box);
        $result = $submit->handle($this->user($request), $box, (string) $request->validated('operation_id'), $request->boolean('attach_recheck'));

        return response()->json([
            'duplicate' => $result['duplicate'],
            'request_id' => $result['request']->id,
            'deadline_at' => $result['request']->deadline_at->toIso8601String(),
            'status' => $status->for($box->refresh(), $this->user($request)),
        ], $result['duplicate'] ? 200 : 201);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
