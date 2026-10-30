<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\ImpactedBoxes;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\OccurrenceView;
use App\Enums\AuditConformity;
use App\Enums\AuditOccurrencePhotoRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\CorrectOccurrenceRequest;
use App\Http\Requests\DesignOfficeWorkflow\DecideImpactedBoxRequest;
use App\Http\Requests\DesignOfficeWorkflow\HoldOccurrenceRequest;
use App\Http\Requests\DesignOfficeWorkflow\ImpactedBoxResultRequest;
use App\Http\Requests\DesignOfficeWorkflow\LinkOccurrencePhotoRequest;
use App\Http\Requests\DesignOfficeWorkflow\ProposeImpactedBoxRequest;
use App\Http\Requests\DesignOfficeWorkflow\StoreOccurrenceRequest;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Occurrences on the phone's box screen in the design office workflow (JSON): read those of a box with the anomaly
 * types to choose from, declare one, link photos, put an optical one on hold, correct one, propose an impacted box,
 * and — for the design office and the supervisors — confirm or exclude an impacted box and give the result on a box.
 * The person is always the signed-in user; refusals answer with their status and a stable reason (WorkflowException).
 */
class OccurrenceController extends Controller
{
    public function index(AuditBox $box, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('viewWorkflow', $box);

        return response()->json($view->forBox($box));
    }

    public function store(StoreOccurrenceRequest $request, AuditBox $box, OccurrenceLifecycle $lifecycle, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('createOccurrence', $box);
        $operationId = $request->validated('operation_id');
        $occurrence = $lifecycle->create(
            $this->user($request),
            $box,
            (int) $request->validated('anomaly_type_id'),
            (string) $request->validated('description'),
            array_map(intval(...), (array) $request->validated('before_photo_ids', [])),
            $request->validated('location_note'),
            $operationId === null ? null : (string) $operationId,
        );

        return response()->json(['occurrence' => $view->one($occurrence)], $occurrence->wasRecentlyCreated ? 201 : 200);
    }

    public function linkPhoto(LinkOccurrencePhotoRequest $request, AuditOccurrence $occurrence, OccurrenceLifecycle $lifecycle, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('update', $occurrence);
        $lifecycle->linkPhoto($this->user($request), $occurrence, (int) $request->validated('photo_id'), AuditOccurrencePhotoRole::from((string) $request->validated('role')));

        return $this->occurrence($occurrence, $view);
    }

    public function hold(HoldOccurrenceRequest $request, AuditOccurrence $occurrence, OccurrenceLifecycle $lifecycle, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('update', $occurrence);
        $lifecycle->putOnHold($this->user($request), $occurrence, (string) $request->validated('justification'));

        return $this->occurrence($occurrence, $view);
    }

    public function correct(CorrectOccurrenceRequest $request, AuditOccurrence $occurrence, OccurrenceLifecycle $lifecycle, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('update', $occurrence);
        $lifecycle->correct(
            $this->user($request),
            $occurrence,
            (string) $request->validated('action'),
            array_map(intval(...), (array) $request->validated('after_photo_ids', [])),
            $request->validated('checks'),
            $request->validated('observations'),
        );

        return $this->occurrence($occurrence, $view);
    }

    public function proposeBox(ProposeImpactedBoxRequest $request, AuditOccurrence $occurrence, ImpactedBoxes $boxes, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('proposeImpactedBox', $occurrence);
        $box = AuditBox::query()->where('audit_id', $occurrence->audit_id)->findOrFail((int) $request->validated('box_id'));
        $result = $boxes->propose($this->user($request), $occurrence, $box, (string) $request->validated('justification'));

        return response()->json([...$result, 'occurrence' => $view->one($occurrence->refresh())]);
    }

    public function decideBox(DecideImpactedBoxRequest $request, AuditOccurrence $occurrence, AuditBox $box, ImpactedBoxes $boxes, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('decideImpactedBox', $occurrence);
        $boxes->decide($this->user($request), $occurrence, $box, $request->boolean('confirm'), $request->validated('reason'));

        return $this->occurrence($occurrence, $view);
    }

    public function result(ImpactedBoxResultRequest $request, AuditOccurrence $occurrence, AuditBox $box, ImpactedBoxes $boxes, OccurrenceView $view): JsonResponse
    {
        Gate::authorize('setImpactedResult', $occurrence);
        $boxes->setResult($this->user($request), $occurrence, $box, AuditConformity::from((string) $request->validated('conformity')), $request->validated('note'));

        return $this->occurrence($occurrence, $view);
    }

    private function occurrence(AuditOccurrence $occurrence, OccurrenceView $view): JsonResponse
    {
        return response()->json(['occurrence' => $view->one($occurrence->refresh())]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
