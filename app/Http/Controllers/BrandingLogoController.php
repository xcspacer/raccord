<?php

namespace App\Http\Controllers;

use App\Settings\CompanySettings;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BrandingLogoController extends Controller
{
    /**
     * The company logo is the only private file served without authentication (login screen, notices).
     */
    public function __invoke(CompanySettings $company): Response
    {
        abort_if($company->logo_path === null || ! Storage::disk('local')->exists($company->logo_path), 404);

        return FileResponse::make('local', $company->logo_path, headers: [
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ], disposition: 'inline');
    }
}
