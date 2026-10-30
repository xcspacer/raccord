<?php

namespace App\Http\Controllers;

use App\Models\UserDocument;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a personal document (BTP card, driving licence) to its owner and to managers only (UserDocumentPolicy).
 */
class UserDocumentController extends Controller
{
    public function __invoke(UserDocument $document): Response
    {
        Gate::authorize('view', $document);

        $file = $document->file;

        return FileResponse::make($file->disk, $file->path, $document->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
