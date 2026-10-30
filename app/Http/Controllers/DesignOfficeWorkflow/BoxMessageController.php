<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxMessages;
use App\DesignOfficeWorkflow\BoxStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\PostBoxMessageRequest;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The internal chat of a box (JSON for the box screen): write a message — possibly prepared offline, sent again with
 * the same operation id — and resolve a blocking one. Each answer carries where the box stands, messages included.
 */
class BoxMessageController extends Controller
{
    public function store(PostBoxMessageRequest $request, AuditBox $box, BoxMessages $messages, BoxStatus $status): JsonResponse
    {
        Gate::authorize('postMessage', $box);
        $photoId = $request->validated('photo_id');
        $result = $messages->post($this->user($request), $box, (string) $request->validated('operation_id'), (string) $request->validated('body'), $request->kind(), $photoId === null ? null : (int) $photoId);

        return response()->json([
            'duplicate' => $result['duplicate'],
            'message_id' => $result['message']->id,
            'status' => $status->for($box->refresh(), $this->user($request)),
        ], $result['duplicate'] ? 200 : 201);
    }

    public function resolve(Request $request, AuditBoxMessage $message, BoxMessages $messages, BoxStatus $status): JsonResponse
    {
        Gate::authorize('resolve', $message);
        $messages->resolve($this->user($request), $message);

        return response()->json(['status' => $status->for($message->box()->firstOrFail(), $this->user($request))]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
