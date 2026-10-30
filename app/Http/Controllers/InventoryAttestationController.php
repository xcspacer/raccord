<?php

namespace App\Http\Controllers;

use App\Models\InventoryAttestation;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF of a certificate of handover, for managers and for the person it was made for (InventoryAttestationPolicy).
 */
class InventoryAttestationController extends Controller
{
    public function __invoke(InventoryAttestation $attestation): Response
    {
        Gate::authorize('view', $attestation);

        $file = $attestation->file;

        return FileResponse::make($file->disk, $file->path, $attestation->reference.'.pdf', [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
