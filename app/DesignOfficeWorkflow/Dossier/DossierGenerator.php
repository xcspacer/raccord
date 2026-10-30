<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Actions\Files\StoreUploadedFile;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditConformity;
use App\Enums\AuditDossierFileRole;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditPoleSection;
use App\Enums\AuditRequestState;
use App\Models\AuditBox;
use App\Models\AuditDossierFile;
use App\Models\AuditDossierVersion;
use App\Models\AuditOccurrence;
use App\Models\AuditPhoto;
use App\Models\AuditValidationRequest;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Writes a pending version of the official dossier and publishes it at once. The version is taken under a row lock
 * (a second run finds it no longer pending and does nothing), the automatic checks run first, then the report, the
 * repairs workbook and the KMZ are written and every file gets its path in the ZIP; the files, the MANIFEST and, for
 * a later version, the changes since the previous published version are recorded and the version is published in one
 * transaction — never partially. Photos and the synoptic are not copied: the version points at their stored content,
 * and the ZIPs are streamed at download. Any failure leaves the version « failed » with a stable error key, to be
 * relaunched by a supervisor; the design office's validations are never touched.
 *
 * Runs outside web requests only (job GenerateDossierVersion, run by `design-office:generate-dossiers`).
 *
 * @phpstan-type Check array{code: string, ok: bool, blocking: bool, count: int|null}
 * @phpstan-type Entry array{path: string, role: AuditDossierFileRole, file: StoredFile, generated?: bool}
 */
class DossierGenerator
{
    public const string MANIFEST = 'MANIFEST.json';

    public const string CHANGES_MANIFEST = 'MANIFEST_MODIFICATIONS.json';

    public function __construct(
        private DossierReport $report,
        private DossierWorkbook $workbook,
        private DossierMap $map,
        private DossierDiskSpace $disk,
        private DossierLanguage $language,
        private StoreUploadedFile $files,
        private WorkflowJournal $journal,
    ) {}

    /**
     * Generates the version when it is pending; the status it ends in (null when it does not exist).
     */
    public function generate(int $versionId): ?AuditDossierStatus
    {
        $version = $this->start($versionId);

        if ($version === null) {
            return AuditDossierVersion::query()->find($versionId)?->status;
        }

        try {
            $this->inLanguage((string) ($version->locale ?? DossierLanguage::DEFAULT), fn () => $this->build($version));
        } catch (DossierChecksFailed $failed) {
            $this->fail($version, 'checks_failed', $failed->checks);
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($version, 'unexpected');
        }

        return $version->refresh()->status;
    }

    private function start(int $versionId): ?AuditDossierVersion
    {
        return DB::transaction(function () use ($versionId): ?AuditDossierVersion {
            $version = AuditDossierVersion::query()->lockForUpdate()->find($versionId);

            if ($version === null || $version->status !== AuditDossierStatus::Pending) {
                return null;
            }

            $version->forceFill([
                'status' => AuditDossierStatus::Generating, 'attempts' => $version->attempts + 1, 'started_at' => now(), 'error' => null, 'checks' => null,
                'locale' => $version->locale ?? $this->language->for($version->audit()->firstOrFail()),
            ])->save();

            return $version;
        });
    }

    private function build(AuditDossierVersion $version): void
    {
        $audit = $version->audit()->firstOrFail();
        $contents = DossierContents::of($audit);
        $checks = $this->checks($version, $contents);

        if (collect($checks)->contains(fn (array $check) => $check['blocking'] && ! $check['ok'])) {
            throw new DossierChecksFailed($checks);
        }

        $cabinet = $this->safe($audit->cabinet_key);
        [$photos, $photoPaths] = $this->photoEntries($contents);
        $generated = [
            [__('design_office_dossier.files.report', ['cabinet' => $cabinet]), AuditDossierFileRole::Report, $this->report->render($contents, $version), 'application/pdf', 'pdf'],
            [__('design_office_dossier.files.repairs_workbook', ['cabinet' => $cabinet]), AuditDossierFileRole::RepairsWorkbook, $this->workbook->build($contents, $photoPaths), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
            [__('design_office_dossier.files.map', ['cabinet' => $cabinet]), AuditDossierFileRole::Map, $this->map->build($contents), 'application/vnd.google-earth.kmz', 'kmz'],
        ];

        $previous = AuditDossierVersion::query()->where('audit_id', $audit->id)->where('status', AuditDossierStatus::Published)
            ->where('version_number', '<', $version->version_number)->orderByDesc('version_number')->with('files.file')->first();

        DB::transaction(function () use ($version, $contents, $checks, $cabinet, $photos, $generated, $previous): void {
            $locked = AuditDossierVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ($locked->status !== AuditDossierStatus::Generating) {
                throw new RuntimeException('The dossier version is no longer being generated.');
            }

            /** @var list<Entry> $entries */
            $entries = [];

            foreach ($generated as [$path, $role, $bytes, $mime, $extension]) {
                $entries[] = ['path' => $path, 'role' => $role, 'file' => $this->files->handleContents($bytes, $mime, $extension), 'generated' => true];
            }

            if ($contents->synoptic !== null) {
                $extension = strtolower(pathinfo($contents->synoptic->original_name, PATHINFO_EXTENSION) ?: $contents->synoptic->file->extension);
                $entries[] = ['path' => __('design_office_dossier.files.synoptic', ['cabinet' => $cabinet]).'.'.$extension, 'role' => AuditDossierFileRole::Synoptic, 'file' => $contents->synoptic->file];
            }

            $entries = [...$entries, ...$photos];
            $listed = array_map(fn (array $entry) => ['path' => $entry['path'], 'size' => $entry['file']->size_bytes, 'sha256' => $entry['file']->sha256], $entries);
            $manifest = $this->manifest($locked, $contents, $listed);
            $entries[] = ['path' => self::MANIFEST, 'role' => AuditDossierFileRole::Manifest, 'file' => $this->files->handleContents($manifest, 'application/json', 'json'), 'generated' => true];
            $changes = null;

            if ($previous !== null) {
                $changes = $this->changes($previous, $listed);
                $entries[] = ['path' => self::CHANGES_MANIFEST, 'role' => AuditDossierFileRole::ChangesManifest, 'file' => $this->files->handleContents($this->changesManifest($locked, $changes), 'application/json', 'json'), 'generated' => true];
            }

            $this->record($locked, $entries);
            $locked->forceFill(['status' => AuditDossierStatus::Published, 'checks' => $checks, 'changes' => $changes, 'published_at' => now(), 'error' => null])->save();
            $this->journal->record('dossier_published', $locked, null, [
                'cabinet' => $contents->audit->cabinet_key, 'version' => $locked->version_number, 'files' => count($entries),
                'manifest_sha256' => hash('sha256', $manifest), 'origin' => 'scheduler',
            ]);
        });
    }

    /**
     * @return list<Check>
     */
    private function checks(AuditDossierVersion $version, DossierContents $contents): array
    {
        $audit = $contents->audit;
        $boxIds = $contents->boxes->pluck('id');
        $openRequests = AuditValidationRequest::query()->whereIn('audit_box_id', $boxIds)->whereIn('state', AuditRequestState::open())->count();
        $openOccurrences = $contents->occurrences->reject(fn (AuditOccurrence $occurrence) => $occurrence->state->isClosed())->count();
        $nonConforming = $contents->boxes->reject(fn (AuditBox $box) => $box->status === AuditBoxStatus::NotInstalled
            || in_array($box->conformity, [AuditConformity::Conforming, AuditConformity::ConformingWithReserve], true))->count();
        $missingFiles = $contents->photos->reject(fn (AuditPhoto $photo) => $photo->file !== null && Storage::disk($photo->file->disk)->exists($photo->file->path))->count();
        $withoutPosition = $boxIds->reject(fn (int $id) => isset($contents->positions[$id]))->count();

        return [
            $this->check('audit_closed', $audit->isClosed(), true),
            $this->check('open_requests', $openRequests === 0, true, $openRequests),
            $this->check('open_occurrences', $openOccurrences === 0, true, $openOccurrences),
            $this->check('boxes_conforming', $nonConforming === 0, true, $nonConforming),
            $this->check('photos', $missingFiles === 0, true, $missingFiles),
            $this->check('disk_space', ! $this->disk->isLow(), true),
            $this->check('synoptic', $contents->synoptic !== null, false),
            $this->check('positions', $withoutPosition === 0, false, $withoutPosition),
        ];
    }

    /**
     * @return Check
     */
    private function check(string $code, bool $ok, bool $blocking, ?int $count = null): array
    {
        return ['code' => $code, 'ok' => $ok, 'blocking' => $blocking, 'count' => $count];
    }

    /**
     * Photos of each box (Photos/box/folder/section) and of each occurrence (Occurrences/reference/role), with the
     * path of each photo in the box tree.
     *
     * @return array{0: list<Entry>, 1: array<int, string>}
     */
    private function photoEntries(DossierContents $contents): array
    {
        $boxes = $contents->boxes->keyBy('id');
        $entries = [];
        $paths = [];
        $used = [];

        foreach ($contents->photos as $photo) {
            $folder = AuditFolder::tryFrom($photo->folder)?->label() ?? $photo->folder;
            $section = $photo->subfolder === '' ? null : (AuditPoleSection::tryFrom($photo->subfolder)?->label() ?? $photo->subfolder);
            $directory = implode('/', array_map($this->safe(...), array_filter([__('design_office_dossier.files.photos'), $boxes[$photo->audit_box_id]->name, $folder, $section])));
            $path = $this->unique($directory, $photo, $used);
            $paths[$photo->id] = $path;
            $entries[] = ['path' => $path, 'role' => AuditDossierFileRole::BoxPhoto, 'file' => $photo->file];
        }

        $included = $contents->photos->keyBy('id');

        foreach ($contents->occurrences as $occurrence) {
            foreach ($occurrence->photoLinks as $link) {
                $photo = $included->get($link->audit_photo_id);

                if ($photo === null) {
                    continue;
                }

                $role = __('design_office_dossier.files.roles.'.$link->role->value);
                $directory = implode('/', array_map($this->safe(...), [__('design_office_dossier.files.occurrences'), $occurrence->reference, $role]));
                $entries[] = ['path' => $this->unique($directory, $photo, $used), 'role' => AuditDossierFileRole::OccurrencePhoto, 'file' => $photo->file];
            }
        }

        return [$entries, $paths];
    }

    /**
     * The photo's own name (or its date), made unique in its folder with its number when needed.
     *
     * @param  array<string, true>  $used
     */
    private function unique(string $directory, AuditPhoto $photo, array &$used): string
    {
        $extension = $photo->file->extension;
        $name = $photo->file_name !== null && $photo->file_name !== ''
            ? pathinfo($photo->file_name, PATHINFO_FILENAME)
            : $photo->captured_at->timezone((string) config('platform.timezone'))->format('Ymd_His');
        $path = $directory.'/'.$this->safe($name).'.'.$extension;

        if (isset($used[$path])) {
            $path = $directory.'/'.$this->safe($name).'_'.$photo->id.'.'.$extension;
        }

        $used[$path] = true;

        return $path;
    }

    /**
     * @param  list<array{path: string, size: int, sha256: string}>  $listed
     */
    private function manifest(AuditDossierVersion $version, DossierContents $contents, array $listed): string
    {
        return (string) json_encode([
            'cabinet' => $contents->audit->cabinet_key,
            'sro' => $contents->audit->sro,
            'version' => $version->version_number,
            'kind' => $version->kind->value,
            'reason' => $version->reason,
            'language' => $version->locale,
            'generated_at' => now()->toIso8601String(),
            'authorized_by' => $version->authorizer?->name,
            'summary' => ['boxes' => $contents->boxes->count(), 'occurrences' => $contents->occurrences->count(), 'reserves' => $contents->reserves->count(), 'photos' => $contents->photos->count()],
            'files' => $listed,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Files added, replaced (same path, other content) and removed since the previous published version.
     *
     * @param  list<array{path: string, size: int, sha256: string}>  $listed
     * @return array{previous: int, added: list<string>, replaced: list<string>, removed: list<string>}
     */
    private function changes(AuditDossierVersion $previous, array $listed): array
    {
        $before = $previous->files->filter(fn (AuditDossierFile $file) => $file->role->isListed())->mapWithKeys(fn (AuditDossierFile $file) => [$file->path => $file->file->sha256])->all();
        $now = collect($listed)->mapWithKeys(fn (array $file) => [$file['path'] => $file['sha256']])->all();

        return [
            'previous' => $previous->version_number,
            'added' => array_map('strval', array_keys(array_diff_key($now, $before))),
            'replaced' => array_map('strval', array_keys(array_filter($now, fn (string $hash, string|int $path) => isset($before[$path]) && $before[$path] !== $hash, ARRAY_FILTER_USE_BOTH))),
            'removed' => array_map('strval', array_keys(array_diff_key($before, $now))),
        ];
    }

    /**
     * @param  array{previous: int, added: list<string>, replaced: list<string>, removed: list<string>}  $changes
     */
    private function changesManifest(AuditDossierVersion $version, array $changes): string
    {
        return (string) json_encode([
            'previous_version' => $changes['previous'],
            'version' => $version->version_number,
            'generated_at' => now()->toIso8601String(),
            'reason' => $version->reason,
            'added' => $changes['added'],
            'replaced' => $changes['replaced'],
            'removed' => $changes['removed'],
            'manifest' => self::MANIFEST,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Records the files of the version and holds their content: each one counts as a use of the stored file, so
     * removing a photo later never removes it from a published dossier.
     *
     * @param  list<Entry>  $entries
     */
    private function record(AuditDossierVersion $version, array $entries): void
    {
        foreach (array_chunk($entries, 500) as $chunk) {
            AuditDossierFile::query()->insert(array_map(fn (array $entry) => [
                'audit_dossier_version_id' => $version->id, 'path' => $entry['path'], 'role' => $entry['role']->value, 'stored_file_id' => $entry['file']->id,
            ], $chunk));
        }

        $uses = collect($entries)->reject(fn (array $entry) => $entry['generated'] ?? false)->countBy(fn (array $entry) => $entry['file']->id);

        foreach ($uses->groupBy(fn (int $count) => $count, true) as $count => $files) {
            StoredFile::query()->whereIn('id', $files->keys()->all())->increment('reference_count', (int) $count);
        }
    }

    /**
     * @param  list<Check>|null  $checks
     */
    private function fail(AuditDossierVersion $version, string $error, ?array $checks = null): void
    {
        DB::transaction(function () use ($version, $error, $checks): void {
            $locked = AuditDossierVersion::query()->lockForUpdate()->find($version->id);

            if ($locked === null || $locked->status !== AuditDossierStatus::Generating) {
                return;
            }

            $locked->forceFill(['status' => AuditDossierStatus::Failed, 'error' => $error, 'checks' => $checks])->save();
            $this->journal->record('dossier_failed', $locked, null, [
                'version' => $locked->version_number, 'error' => $error,
                'checks' => collect($checks ?? [])->reject(fn (array $check) => $check['ok'])->pluck('code')->values()->all(), 'origin' => 'scheduler',
            ]);
        });
    }

    /**
     * A name usable in a ZIP path on every system.
     */
    private function safe(string $name): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $name), ' .-');

        return $clean === '' ? '_' : mb_substr($clean, 0, 100);
    }

    /**
     * @param  callable(): void  $work
     */
    private function inLanguage(string $locale, callable $work): void
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $work();
        } finally {
            app()->setLocale($previous);
        }
    }
}
