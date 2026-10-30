<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\Audit\BoxName;
use App\DesignOfficeWorkflow\SynopticCorrections;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\ProposeSynopticCorrectionRequest;
use App\Models\Audit;
use App\Models\AuditSynopticCorrection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Corrections of the synoptic proposed from the phone (JSON, design office workflow).
 *
 * GET  design-office/audits/{audit}/synoptic-corrections → {corrections: [{id, box, text, photo_id, state, state_label,
 *      decision_note, version, at}]} — a technician sees their own, the design office and the supervisors all of them.
 * POST design-office/audits/{audit}/synoptic-corrections {operation_id, text, box?, photo?} → 201 {duplicate, correction}
 *      (200 with duplicate=true for a resent operation). `box` is the box name, `photo` the id of a photo of the PM.
 *      Refusals: WorkflowException JSON — 409 audit_closed, 404 box_not_in_audit, 422 correction_text_required /
 *      photo_not_in_audit / operation_id_required, 409 operation_id_reused.
 */
class SynopticCorrectionController extends Controller
{
    public function index(Request $request, Audit $audit): JsonResponse
    {
        Gate::authorize('viewAny', [AuditSynopticCorrection::class, $audit]);
        $user = $this->user($request);
        $office = WorkflowRoles::isReviewer($user) || WorkflowRoles::isSupervisor($user);

        $corrections = AuditSynopticCorrection::query()->where('audit_id', $audit->id)
            ->when(! $office, fn ($query) => $query->where('proposed_by', $user->id))
            ->with(['box:id,name', 'documentVersion:id,version_number'])->latest('id')->limit(200)->get();

        return response()->json(['corrections' => $corrections->map(fn (AuditSynopticCorrection $correction) => $this->correction($correction))->values()->all()]);
    }

    public function store(ProposeSynopticCorrectionRequest $request, Audit $audit, SynopticCorrections $corrections): JsonResponse
    {
        Gate::authorize('create', [AuditSynopticCorrection::class, $audit]);
        $name = trim((string) $request->validated('box'));
        $box = $name === '' ? null : ($audit->boxes()->where('name', BoxName::normalise($name))->first() ?? throw new WorkflowException('box_not_in_audit', 404));
        $photo = $request->validated('photo');

        $result = $corrections->propose($this->user($request), $audit, $box, (string) $request->validated('operation_id'), (string) $request->validated('text'), $photo === null ? null : (int) $photo);

        return response()->json(['duplicate' => $result['duplicate'], 'correction' => $this->correction($result['correction']->load(['box:id,name', 'documentVersion:id,version_number']))], $result['duplicate'] ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function correction(AuditSynopticCorrection $correction): array
    {
        return [
            'id' => $correction->id,
            'box' => $correction->box?->name,
            'text' => $correction->body,
            'photo_id' => $correction->audit_photo_id,
            'state' => $correction->state->value,
            'state_label' => $correction->state->label(),
            'decision_note' => $correction->decision_note,
            'version' => $correction->documentVersion?->version_number,
            'at' => $correction->created_at->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
