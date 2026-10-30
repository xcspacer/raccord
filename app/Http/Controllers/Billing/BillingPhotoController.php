<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingEntryPhoto;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class BillingPhotoController extends Controller
{
    /**
     * A photo of an entry: for the person who sent it, the entry's technician and partners, supervisors and
     * administrators (BillingEntryPhotoPolicy).
     */
    public function __invoke(BillingEntryPhoto $photo, string $variant): Response
    {
        Gate::authorize('view', $photo);

        $file = $photo->file;
        $thumb = $variant === 'thumb' && $file->thumbnail_path !== null;

        return FileResponse::make($file->disk, $thumb ? $file->thumbnail_path : $file->path, null, [
            'Content-Type' => $thumb ? 'image/webp' : 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
