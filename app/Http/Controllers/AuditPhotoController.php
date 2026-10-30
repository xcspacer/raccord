<?php

namespace App\Http\Controllers;

use App\Audit\AuditOperationException;
use App\Audit\AuditPhotoStore;
use App\Audit\BoxName;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Models\Audit;
use App\Models\AuditPhoto;
use App\Models\User;
use App\Support\FileResponse;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Photos of an audit: received from the phones and shown to the people who work on that audit.
 */
class AuditPhotoController extends Controller
{
    public function store(Request $request, string $cabinet, AuditPhotoStore $photos): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->first();
        $access = $audit === null ? AccessResponse::denyAsNotFound() : Gate::forUser($user)->inspect('work', $audit);

        if ($audit === null || $access->denied()) {
            $hidden = $access->status() === 404;

            return response()->json(['ok' => false, 'error' => $hidden ? 'audit_not_found' : 'forbidden'], $hidden ? 404 : 403);
        }

        $data = $request->validate([
            'operation_id' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file', 'max:12288'],
            'box' => ['nullable', 'string', 'max:60'], 'point' => ['nullable', 'integer'], 'folder' => ['nullable', 'string', 'max:24'], 'subfolder' => ['nullable', 'string', 'max:16'],
            'captured_at' => ['nullable', 'string', 'max:40'], 'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'], 'imported' => ['nullable', 'boolean'],
            'geo_missing_reason' => ['nullable', 'string', 'max:20'], 'client_created_at' => ['nullable', 'string', 'max:40'], 'queued' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $photos->add($user, $audit, $data['operation_id'], $request->file('file'), ['imported' => $request->boolean('imported'), 'queued' => $request->boolean('queued')] + $data);
        } catch (AuditOperationException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->reason], $exception->status);
        } catch (WorkflowException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->reason, 'message' => $exception->translated(), 'context' => $exception->context], $exception->status);
        }

        return response()->json(['ok' => true] + $result);
    }

    public function show(AuditPhoto $photo, string $variant): Response
    {
        Gate::authorize('view', $photo);

        $file = $photo->file;
        $thumb = $variant === 'thumb' && $file->thumbnail_path !== null;

        return FileResponse::make($file->disk, $thumb ? $file->thumbnail_path : $file->path, null, [
            'Content-Type' => $thumb ? 'image/webp' : 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
