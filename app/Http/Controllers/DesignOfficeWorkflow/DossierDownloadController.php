<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\Dossier\DossierArchive;
use App\Http\Controllers\Controller;
use App\Models\AuditDossierVersion;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads of a published version of an official dossier: the full ZIP, or the ZIP of the changes since the previous
 * version. Both are streamed from the stored files.
 */
class DossierDownloadController extends Controller
{
    public function full(AuditDossierVersion $dossierVersion, DossierArchive $archive): Response
    {
        Gate::authorize('download', $dossierVersion);

        return $archive->full($dossierVersion);
    }

    public function changes(AuditDossierVersion $dossierVersion, DossierArchive $archive): Response
    {
        Gate::authorize('download', $dossierVersion);

        return $archive->changes($dossierVersion) ?? abort(404);
    }
}
