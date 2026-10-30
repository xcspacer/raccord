<?php

namespace App\Audit;

use App\Enums\AuditFolder;
use App\Enums\AuditPoleSection;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditPhoto;
use App\Models\StoredFile;

/**
 * The folder tree users see and download for an audit, built from the database (files are stored flat):
 * « <audit folder>/<family> <cabinet>/<box>/<photo folder>[/<section>]/<photo> » and the box PDF at the box root;
 * control points live under « <audit folder>/<audit folder>/<point> ».
 *
 * @phpstan-type Entry array{path: string, file: StoredFile, photo?: AuditPhoto}
 */
class AuditTree
{
    public const int SEGMENT_LENGTH = 90;

    /**
     * Folder of a box, relative to the root of the tree.
     */
    public function boxFolder(Audit $audit, AuditBox $box): string
    {
        return $this->join([$this->auditFolder($audit), $this->segment($box->kind->label().' '.$audit->cabinet_key), $this->segment($box->name)]);
    }

    public function pointFolder(Audit $audit, AuditControlPoint $point): string
    {
        return $this->join([$this->auditFolder($audit), $this->auditFolder($audit), $this->segment($point->name)]);
    }

    /**
     * Every file of a box: its photos and its PDF. Photos must be loaded with their file.
     *
     * @return list<Entry>
     */
    public function boxEntries(Audit $audit, AuditBox $box): array
    {
        $root = $this->boxFolder($audit, $box);
        $entries = $this->photoEntries($box->photos->all(), fn (AuditPhoto $photo) => $this->join([$root, $this->photoFolder($photo)]));

        return $this->withPdf($entries, $root, $box->pdfFile, $box->pdf_name ?: $box->name.'.pdf');
    }

    /**
     * @return list<Entry>
     */
    public function pointEntries(Audit $audit, AuditControlPoint $point): array
    {
        $root = $this->pointFolder($audit, $point);
        $entries = $this->photoEntries($point->photos->all(), fn (AuditPhoto $photo) => $this->join([$root, $this->segment($photo->subfolder)]));

        return $this->withPdf($entries, $root, $point->pdfFile, $point->pdf_name ?: $point->name.'.pdf');
    }

    /**
     * Name of a photo's folder as shown to people (the pole photos sit in one sub-folder per section).
     */
    public function photoFolder(AuditPhoto $photo): string
    {
        $folder = AuditFolder::tryFrom($photo->folder);

        if ($folder === AuditFolder::Pole) {
            return $this->join([$this->segment($folder->label()), $this->segment(AuditPoleSection::tryFrom($photo->subfolder)?->label() ?? $photo->subfolder)]);
        }

        return $this->segment($folder?->label() ?? $photo->folder);
    }

    /**
     * Characters forbidden in file names are replaced, and each segment is kept short.
     */
    public function segment(string $name): string
    {
        $clean = trim((string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', '_', $name), " .\t");

        return mb_substr($clean === '' ? '_' : $clean, 0, self::SEGMENT_LENGTH);
    }

    private function auditFolder(Audit $audit): string
    {
        return $this->segment($audit->folder ?? $audit->cabinet_key);
    }

    /**
     * Photos with their path; a name already taken in the same folder gets the photo's id in front of it.
     *
     * @param  list<AuditPhoto>  $photos
     * @param  callable(AuditPhoto): string  $folderOf
     * @return list<Entry>
     */
    private function photoEntries(array $photos, callable $folderOf): array
    {
        $entries = [];
        $taken = [];

        foreach ($photos as $photo) {
            $folder = $folderOf($photo);
            $name = $this->photoName($photo);

            if (isset($taken[mb_strtolower($folder.'/'.$name)])) {
                $name = $this->segment($photo->id.'_'.$name);
            }

            $taken[mb_strtolower($folder.'/'.$name)] = true;
            $entries[] = ['path' => $this->join([$folder, $name]), 'file' => $photo->file, 'photo' => $photo];
        }

        return $entries;
    }

    /**
     * The name a photo was already known by (imported photos), otherwise « <reception time UTC>_<id>.jpg ».
     */
    private function photoName(AuditPhoto $photo): string
    {
        if ($photo->file_name !== null && $photo->file_name !== '') {
            return $this->segment($photo->file_name);
        }

        return $photo->received_at->copy()->utc()->format('Ymd-His').'_'.$photo->id.'.jpg';
    }

    /**
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private function withPdf(array $entries, string $root, ?StoredFile $pdf, string $name): array
    {
        if ($pdf !== null) {
            $entries[] = ['path' => $this->join([$root, $this->segment($name)]), 'file' => $pdf];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $segments  already safe segments (or paths of safe segments)
     */
    private function join(array $segments): string
    {
        return implode('/', array_filter($segments, fn (string $segment) => $segment !== '' && $segment !== '_'));
    }
}
