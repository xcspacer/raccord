<?php

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PortalLogoController extends Controller
{
    /**
     * A client company's logo is public: it is shown on its portal sign-in screen.
     */
    public function __invoke(Client $portalClient): Response
    {
        abort_if($portalClient->logo_path === null || ! Storage::disk('local')->exists($portalClient->logo_path), 404);

        return FileResponse::make('local', $portalClient->logo_path, headers: [
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ], disposition: 'inline');
    }
}
