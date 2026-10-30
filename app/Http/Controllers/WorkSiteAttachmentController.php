<?php

namespace App\Http\Controllers;

use App\Actions\Files\UploadRules;
use App\Models\WorkSiteAttachment;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a work site file (KMZ, synoptic…) to managers and to the people working on that work site.
 * Only PDF and images open in the browser; everything else is downloaded.
 */
class WorkSiteAttachmentController extends Controller
{
    public function __invoke(WorkSiteAttachment $attachment): Response
    {
        Gate::authorize('view', $attachment);

        $file = $attachment->file;
        $inline = in_array($file->extension, UploadRules::INLINE, true);

        return FileResponse::make($file->disk, $file->path, $attachment->original_name, [
            'Content-Type' => $inline ? $file->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
