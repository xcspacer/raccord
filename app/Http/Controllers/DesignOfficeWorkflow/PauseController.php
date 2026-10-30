<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\Pauses;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\DeclarePauseRequest;
use App\Models\AuditBox;
use App\Models\AuditPause;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pauses declared from the box screen of the phone (JSON): start one, end it. Left out of the production time.
 */
class PauseController extends Controller
{
    public function start(DeclarePauseRequest $request, AuditBox $box, Pauses $pauses): JsonResponse
    {
        Gate::authorize('declarePause', $box);
        $pause = $pauses->start($this->user($request), $box, $request->validated('reason'));

        return response()->json($this->payload($pause), 201);
    }

    public function end(Request $request, AuditBox $box, Pauses $pauses): JsonResponse
    {
        Gate::authorize('declarePause', $box);

        return response()->json($this->payload($pauses->end($this->user($request), $box)));
    }

    /**
     * @return array{id: int, box_id: int|null, started_at: string, ended_at: string|null}
     */
    private function payload(AuditPause $pause): array
    {
        return ['id' => $pause->id, 'box_id' => $pause->audit_box_id, 'started_at' => $pause->started_at->toIso8601String(), 'ended_at' => $pause->ended_at?->toIso8601String()];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
