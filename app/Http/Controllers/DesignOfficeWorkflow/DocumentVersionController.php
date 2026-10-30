<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\Enums\AuditDocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\AuditDocumentVersion;
use App\Support\FileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versioned documents of a PM (design office workflow).
 *
 * GET design-office/audits/{audit}/documents                → {current: {synoptic, map}, versions}
 *     `current` holds the version in force of each kind (null when none), for whoever works on the PM; `versions`
 *     (every version, newest first) only for the design office and the supervisors, null for a technician.
 * GET design-office/documents/{version}/download             → the file (a technician: current versions only).
 */
class DocumentVersionController extends Controller
{
    public function index(Request $request, Audit $audit): JsonResponse
    {
        Gate::authorize('viewCurrent', [AuditDocumentVersion::class, $audit]);
        $versions = AuditDocumentVersion::query()->where('audit_id', $audit->id)->with('file')->orderByDesc('version_number')->orderByDesc('id')->get();
        $current = $versions->where('is_current', true);

        return response()->json([
            'current' => [
                'synoptic' => $this->version($current->first(fn (AuditDocumentVersion $version) => $version->kind === AuditDocumentKind::Synoptic)),
                'map' => $this->version($current->first(fn (AuditDocumentVersion $version) => $version->kind === AuditDocumentKind::Map)),
            ],
            'versions' => Gate::inspect('viewAny', [AuditDocumentVersion::class, $audit])->allowed()
                ? $versions->map(fn (AuditDocumentVersion $version) => $this->version($version))->values()->all()
                : null,
        ]);
    }

    public function download(AuditDocumentVersion $version): Response
    {
        Gate::authorize('view', $version);
        $file = $version->file;

        return FileResponse::make($file->disk, $file->path, $version->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=0']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function version(?AuditDocumentVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        return [
            'id' => $version->id,
            'kind' => $version->kind->value,
            'number' => $version->version_number,
            'name' => $version->original_name,
            'is_current' => $version->is_current,
            'published_at' => $version->published_at->toIso8601String(),
            'size' => $version->file->size_bytes,
            'url' => route('design-office.documents.download', $version),
        ];
    }
}
