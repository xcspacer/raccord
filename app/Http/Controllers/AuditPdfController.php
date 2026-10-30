<?php

namespace App\Http\Controllers;

use App\Audit\BoxName;
use App\Models\Audit;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\FileResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The current PDF of a box or of a control point, for the people working on the audit.
 */
class AuditPdfController extends Controller
{
    public function __invoke(Request $request, string $cabinet, string $type, int $id): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        Gate::forUser($user)->authorize('work', $audit);

        $unit = $type === 'box' ? $audit->boxes()->whereKey($id)->firstOrFail() : $audit->controlPoints()->whereKey($id)->firstOrFail();
        $stored = $unit->pdf_file_id === null ? null : StoredFile::query()->find($unit->pdf_file_id);
        abort_if($stored === null, 404);

        return FileResponse::make($stored->disk, $stored->path, $unit->pdf_name ?: $unit->name.'.pdf', ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], 'inline');
    }
}
