<?php

namespace App\Http\Controllers\ClientPortal;

use App\ClientPortal\ClientRemarks;
use App\Http\Controllers\Controller;
use App\Models\ClientRemark;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientNoteAcknowledgeController extends Controller
{
    /**
     * « Prendre en compte » from the box and control point screens (online only).
     */
    public function __invoke(Request $request, ClientRemark $remark, ClientRemarks $remarks): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $remarks->acknowledge($user, $remark->load('audit'));

        return response()->json(['ok' => true]);
    }
}
