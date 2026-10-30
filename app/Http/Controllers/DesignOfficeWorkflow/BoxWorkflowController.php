<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxStatus;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\SubmitInspection;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\HandOverBoxRequest;
use App\Http\Requests\DesignOfficeWorkflow\SubmitInspectionRequest;
use App\Models\AuditBox;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What the box screen of the phone does in the design office workflow (JSON): read where the box stands (polled to
 * learn the decision), start it, hand it over and submit it. The person is always the signed-in user; refusals answer
 * with their status and a stable reason (see WorkflowException).
 */
class BoxWorkflowController extends Controller
{
    public function show(Request $request, AuditBox $box, BoxStatus $status): JsonResponse
    {
        Gate::authorize('viewWorkflow', $box);

        return response()->json($status->for($box, $this->user($request)));
    }

    public function claim(Request $request, AuditBox $box, BoxTeam $team, BoxStatus $status): JsonResponse
    {
        Gate::authorize('claim', $box);
        $result = $team->claim($this->user($request), $box);

        return response()->json([...$result, 'status' => $status->for($box->refresh(), $this->user($request))]);
    }

    public function handOver(HandOverBoxRequest $request, AuditBox $box, BoxTeam $team, BoxStatus $status): JsonResponse
    {
        Gate::authorize('handOver', $box);
        $toUserId = $request->validated('to_user_id');
        $result = $team->handOver($this->user($request), $box, $toUserId === null ? null : (int) $toUserId);

        return response()->json([...$result, 'status' => $status->for($box->refresh(), $this->user($request))]);
    }

    public function submit(SubmitInspectionRequest $request, AuditBox $box, SubmitInspection $submit, BoxStatus $status): JsonResponse
    {
        Gate::authorize('submit', $box);
        $result = $submit->handle($this->user($request), $box, (string) $request->validated('operation_id'), $request->justifications());

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
