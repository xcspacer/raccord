<?php

namespace App\Http\Controllers\ClientPortal;

use App\Audit\AuditTree;
use App\ClientPortal\PortalPhotoVisibility;
use App\ClientPortal\PortalSession;
use App\DesignOfficeWorkflow\Dossier\DossierArchive;
use App\Enums\AuditDossierStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditDossierVersion;
use App\Models\AuditPhoto;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Settings\CompanySettings;
use App\Support\FileResponse;
use App\Support\ZipDownload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\ZipStream;

/**
 * Files of the client portal: photos, PDFs and folder ZIPs, only of the audits the signed-in account may follow, and
 * on a design office audit only of the boxes the design office accepted (PortalPhotoVisibility): anything else does
 * not exist for the client (404).
 */
class PortalFileController extends Controller
{
    public const int MAX_ITEMS = 400;

    public const int MAX_ENTRIES = 6000;

    public function __construct(private PortalPhotoVisibility $visibility) {}

    public function photo(Request $request, Client $portalClient, AuditPhoto $photo, string $variant, PortalSession $portal): Response
    {
        $this->visible($portal->account($portalClient->id), $photo->audit_id);
        $box = $photo->audit_box_id === null ? null : AuditBox::query()->with('audit')->find($photo->audit_box_id);
        abort_if($box !== null && $box->isRemoved(), 404);
        abort_if($box !== null ? ! $this->visibility->box($box->audit, $box) : ! $this->visibility->controlPoints($photo->audit), 404);

        $file = $photo->file;
        $thumb = $variant === 'thumb' && $file->thumbnail_path !== null;
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return FileResponse::make($file->disk, $thumb ? $file->thumbnail_path : $file->path, $photo->id.'.'.($thumb ? 'webp' : 'jpg'), [
            'Content-Type' => $thumb ? 'image/webp' : 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400',
        ], $disposition);
    }

    public function pdf(Request $request, Client $portalClient, string $type, int $id, PortalSession $portal): Response
    {
        $unit = $this->unit($type, $id);
        $this->visible($portal->account($portalClient->id), $unit->audit_id);
        $this->photosShown($unit);

        $stored = $unit->pdfFile;
        abort_if($stored === null, 404);

        return FileResponse::make($stored->disk, $stored->path, $unit->pdf_name ?: $unit->name.'.pdf', [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    /**
     * The full (or changes) ZIP of a published version of the official dossier of a design office audit: any version,
     * never one being prepared or failed.
     */
    public function dossier(Client $portalClient, AuditDossierVersion $version, string $archive, PortalSession $portal, DossierArchive $archives): Response
    {
        $this->visible($portal->account($portalClient->id), $version->audit_id);
        abort_unless($version->status === AuditDossierStatus::Published, 404);

        return ($archive === 'full' ? $archives->full($version) : $archives->changes($version)) ?? abort(404);
    }

    /**
     * Folders of boxes or control points (« box:<id> », « point:<id> »); one forbidden item refuses the whole ZIP.
     */
    public function zip(Request $request, Client $portalClient, PortalSession $portal, AuditTree $tree, CompanySettings $company, ZipDownload $zips): Response
    {
        $items = $request->validate([
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['string', 'regex:/^(box|point):\d+$/'],
        ])['items'];

        $account = $portal->account($portalClient->id);
        $entries = [];
        $folders = [];

        foreach (array_unique($items) as $item) {
            [$type, $id] = explode(':', $item);
            $unit = $this->unit($type, (int) $id);
            $this->visible($account, $unit->audit_id);
            $this->photosShown($unit);
            $unit->load(['audit', 'pdfFile', 'photos.file']);

            if ($unit instanceof AuditBox) {
                $folders[] = $tree->boxFolder($unit->audit, $unit);
                array_push($entries, ...$tree->boxEntries($unit->audit, $unit));
            } else {
                $folders[] = $tree->pointFolder($unit->audit, $unit);
                array_push($entries, ...$tree->pointEntries($unit->audit, $unit));
            }
        }

        if (count($entries) > self::MAX_ENTRIES) {
            return response()->view('errors.notice', ['status' => 'zip_too_large'], 422);
        }

        $name = count($folders) === 1
            ? basename($folders[0]).'.zip'
            : __('client_portal.zip_name', ['company' => $company->displayName(), 'date' => now()->setTimezone(config('platform.timezone'))->toDateString()]).'.zip';

        return $zips->stream('client_portal', $name, function (ZipStream $zip) use ($entries): void {
            foreach ($entries as $entry) {
                ZipDownload::addStoredFile($zip, $entry['path'], $entry['file']);
            }
        }, count($entries), array_sum(array_map(fn (array $entry) => $entry['file']->size_bytes, $entries)));
    }

    private function unit(string $type, int $id): AuditBox|AuditControlPoint
    {
        $unit = $type === 'box' ? AuditBox::query()->whereNull('removed_at')->find($id) : AuditControlPoint::query()->find($id);
        abort_if(! $unit instanceof Model, 404);

        return $unit;
    }

    /**
     * The photos (and the PDF holding them) of a box the design office has not accepted do not exist for the client.
     */
    private function photosShown(AuditBox|AuditControlPoint $unit): void
    {
        $unit->loadMissing('audit');
        abort_if($unit instanceof AuditBox ? ! $this->visibility->box($unit->audit, $unit) : ! $this->visibility->controlPoints($unit->audit), 404);
    }

    /**
     * Anything outside the account's audits does not exist for it.
     */
    private function visible(?ClientAccount $account, int $auditId): void
    {
        abort_if($account === null || ! $account->visibleAudits()->whereKey($auditId)->exists(), 404);
    }
}
