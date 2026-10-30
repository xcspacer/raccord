<?php

namespace App\DesignOfficeWorkflow;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\Import\KmlReader;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditSynopticCorrectionState;
use App\Models\Audit;
use App\Models\AuditDocumentVersion;
use App\Models\AuditSynopticCorrection;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Versioned documents of a PM in the design office workflow: the synoptic and the map file (KMZ/KML), published by the
 * design office or a supervisor. Each publication is a new version (the earlier ones stay archived, never replaced);
 * publishing the same content as the current version changes nothing. A map file adds the « map origin » position of
 * every box it names (BoxPositions::importMap) and lists the names that match no box. A synoptic may consolidate the
 * corrections proposed by the technicians: they are marked included in it. Once the PM is closed, a document only
 * changes through a new version of the official dossier.
 */
class DocumentVersions
{
    /** Most map placemarks names kept in the summary of a version. */
    private const int UNMATCHED_KEPT = 100;

    public function __construct(
        private WorkflowGuard $guard,
        private WorkflowJournal $journal,
        private StoreUploadedFile $files,
        private KmlReader $reader,
        private BoxPositions $positions,
    ) {}

    /**
     * @param  list<int>  $correctionIds  Open synoptic corrections the new synoptic includes.
     * @return array{version: AuditDocumentVersion, duplicate: bool}
     *
     * @throws WorkflowException
     */
    public function publish(User $actor, Audit $audit, AuditDocumentKind $kind, UploadedFile $file, array $correctionIds = []): array
    {
        $placemarks = $kind === AuditDocumentKind::Map ? $this->placemarks((string) file_get_contents((string) $file->getRealPath())) : null;
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());

        return DB::transaction(function () use ($actor, $audit, $kind, $file, $hash, $placemarks, $correctionIds): array {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->guard->ensureOpen($audit);
            $current = $this->current($audit, $kind);

            if ($current !== null && $current->file->sha256 === $hash) {
                return ['version' => $current, 'duplicate' => true];
            }

            return ['version' => $this->record($actor, $audit, $kind, $this->files->handle($file), $this->name($file->getClientOriginalName()), $placemarks, $correctionIds), 'duplicate' => false];
        });
    }

    /**
     * The synoptic or map file given when the PM is configured becomes a version too (the file is already stored).
     *
     * @param  array<string, array{float, float}>|null  $placemarks  Boxes of the map file already read.
     *
     * @throws WorkflowException
     */
    public function publishStored(User $actor, Audit $audit, AuditDocumentKind $kind, StoredFile $stored, string $name, ?array $placemarks = null): AuditDocumentVersion
    {
        return DB::transaction(function () use ($actor, $audit, $kind, $stored, $name, $placemarks): AuditDocumentVersion {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->guard->ensureOpen($audit);
            $current = $this->current($audit, $kind);

            if ($current !== null && $current->stored_file_id === $stored->id) {
                return $current;
            }

            $stored->increment('reference_count');

            return $this->record($actor, $audit, $kind, $stored, $this->name($name), $kind === AuditDocumentKind::Map ? ($placemarks ?? []) : null, []);
        });
    }

    public function current(Audit $audit, AuditDocumentKind $kind): ?AuditDocumentVersion
    {
        return AuditDocumentVersion::query()->where('audit_id', $audit->id)->where('kind', $kind->value)->where('is_current', true)->with('file')->first();
    }

    /**
     * @param  array<string, array{float, float}>|null  $placemarks
     * @param  list<int>  $correctionIds
     */
    private function record(User $actor, Audit $audit, AuditDocumentKind $kind, StoredFile $stored, string $name, ?array $placemarks, array $correctionIds): AuditDocumentVersion
    {
        $number = (int) AuditDocumentVersion::query()->where('audit_id', $audit->id)->where('kind', $kind->value)->max('version_number') + 1;

        AuditDocumentVersion::query()->where('audit_id', $audit->id)->where('kind', $kind->value)->where('is_current', true)->get()
            ->each(fn (AuditDocumentVersion $previous) => $previous->forceFill(['is_current' => false])->save());

        $version = AuditDocumentVersion::query()->create([
            'audit_id' => $audit->id, 'kind' => $kind, 'version_number' => $number, 'stored_file_id' => $stored->id, 'original_name' => $name,
            'is_current' => true, 'published_by' => $actor->id, 'published_at' => now(),
        ]);

        $summary = match ($kind) {
            AuditDocumentKind::Map => $this->mapSummary($audit, $version, $placemarks ?? [], $actor),
            AuditDocumentKind::Synoptic => ['corrections' => $this->include($audit, $version, $correctionIds, $actor)],
        };
        $version->forceFill(['summary' => $summary])->save();

        $this->journal->record('document_published', $version, $actor, [
            'kind' => $kind->value, 'version' => $number, 'sha256' => $stored->sha256, 'name' => $name,
            'matched' => $summary['matched'] ?? null, 'unmatched' => isset($summary['unmatched']) ? count($summary['unmatched']) : null, 'corrections' => $summary['corrections'] ?? [],
        ]);

        return $version;
    }

    /**
     * @param  array<string, array{float, float}>  $placemarks
     * @return array{placemarks: int, matched: int, unmatched: list<string>}
     */
    private function mapSummary(Audit $audit, AuditDocumentVersion $version, array $placemarks, User $actor): array
    {
        $result = $this->positions->importMap($audit, $version, $placemarks, $actor);

        return ['placemarks' => count($placemarks), 'matched' => $result['matched'], 'unmatched' => array_slice($result['unmatched'], 0, self::UNMATCHED_KEPT)];
    }

    /**
     * @param  list<int>  $correctionIds
     * @return list<int> Ids of the corrections now included.
     */
    private function include(Audit $audit, AuditDocumentVersion $version, array $correctionIds, User $actor): array
    {
        if ($correctionIds === []) {
            return [];
        }

        $corrections = AuditSynopticCorrection::query()->where('audit_id', $audit->id)->where('state', AuditSynopticCorrectionState::Open->value)
            ->whereIn('id', $correctionIds)->lockForUpdate()->get();

        foreach ($corrections as $correction) {
            $correction->forceFill([
                'state' => AuditSynopticCorrectionState::Included, 'audit_document_version_id' => $version->id, 'decided_by' => $actor->id, 'decided_at' => now(),
            ])->save();
        }

        return $corrections->pluck('id')->map(fn (mixed $id) => (int) $id)->values()->all();
    }

    /**
     * Boxes named in a map file.
     *
     * @return array<string, array{float, float}>
     *
     * @throws WorkflowException
     */
    private function placemarks(string $contents): array
    {
        $map = $this->reader->read($contents);

        if ($map === null || $map['boxes'] === []) {
            throw new WorkflowException('map_without_points');
        }

        return $map['boxes'];
    }

    private function name(string $name): string
    {
        return mb_substr(Str::of($name)->replaceMatches('/[\\\\\/\x00-\x1f]/', '')->toString(), 0, 200) ?: 'document';
    }
}
