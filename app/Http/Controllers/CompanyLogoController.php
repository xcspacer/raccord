<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo of a company, shown on the companies page (the route is reserved to administrators).
 */
class CompanyLogoController extends Controller
{
    public function __invoke(Company $company): Response
    {
        $file = $company->logo;
        abort_if($file === null || ! Storage::disk($file->disk)->exists($file->path), 404);

        return FileResponse::make($file->disk, $file->path, 'logo.'.$file->extension, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }
}
