<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Enums\AuditDossierFileRole;
use App\Models\AuditDossierFile;
use App\Models\AuditDossierVersion;
use App\Support\ZipDownload;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\ZipStream;

/**
 * The ZIPs of a published dossier version, streamed at download from the stored files (nothing is copied, nothing is
 * written on the disk): the full one (every file and the MANIFEST) and, for a later version, the one with the changes
 * only (files added or replaced since the previous version, the MANIFEST and MANIFEST_MODIFICATIONS).
 */
class DossierArchive
{
    public function __construct(private ZipDownload $zips) {}

    /**
     * @param  string|null  $fileName  name of the download (the cabinet and the version by default)
     */
    public function full(AuditDossierVersion $version, ?string $fileName = null): Response
    {
        $files = $version->files()->where('role', '!=', AuditDossierFileRole::ChangesManifest)->with('file')->orderBy('id')->get();

        return $this->stream($files, $fileName ?? $this->name($version).'_V'.$version->version_number.'.zip');
    }

    /**
     * Null for a first version (it has no changes).
     *
     * @param  string|null  $fileName  name of the download (the cabinet and both versions by default)
     */
    public function changes(AuditDossierVersion $version, ?string $fileName = null): ?Response
    {
        if ($version->changes === null) {
            return null;
        }

        $paths = [...$version->changes['added'], ...$version->changes['replaced']];
        $files = $version->files()->with('file')->orderBy('id')->get()
            ->filter(fn (AuditDossierFile $file) => ! $file->role->isListed() || in_array($file->path, $paths, true))->values();

        return $this->stream($files, $fileName ?? $this->name($version).'_V'.$version->changes['previous'].'-V'.$version->version_number.'.zip');
    }

    /**
     * Size of the full ZIP's content.
     */
    public static function fullSize(AuditDossierVersion $version): int
    {
        return (int) $version->files()->where('role', '!=', AuditDossierFileRole::ChangesManifest)
            ->join('stored_files', 'stored_files.id', '=', 'audit_dossier_files.stored_file_id')->sum('stored_files.size_bytes');
    }

    /**
     * @param  Collection<int, AuditDossierFile>  $files
     */
    private function stream(Collection $files, string $name): Response
    {
        return $this->zips->stream('design_office_dossier', $name, function (ZipStream $zip) use ($files): void {
            foreach ($files as $file) {
                ZipDownload::addStoredFile($zip, $file->path, $file->file);
            }
        }, $files->count(), (int) $files->sum(fn (AuditDossierFile $file) => $file->file->size_bytes));
    }

    private function name(AuditDossierVersion $version): string
    {
        return trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', (string) $version->audit?->cabinet_key), ' .-') ?: 'dossier';
    }
}
