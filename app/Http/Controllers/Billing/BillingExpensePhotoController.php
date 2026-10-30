<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingExpensePhoto;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class BillingExpensePhotoController extends Controller
{
    /**
     * A receipt photo: for whoever may see the expense (BillingExpensePolicy).
     */
    public function __invoke(BillingExpensePhoto $photo, string $variant): Response
    {
        Gate::authorize('view', $photo->expense()->withTrashed()->firstOrFail());

        $file = $photo->file;
        $thumb = $variant === 'thumb' && $file->thumbnail_path !== null;

        return FileResponse::make($file->disk, $thumb ? $file->thumbnail_path : $file->path, null, [
            'Content-Type' => $thumb ? 'image/webp' : 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
