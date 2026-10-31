<?php

namespace App\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Actions\Files\UploadRules;
use App\DesignOfficeWorkflow\FieldDataGuard;
use App\DesignOfficeWorkflow\FieldPhotoRules;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditFolder;
use App\Enums\AuditGeoMissingReason;
use App\Enums\AuditMode;
use App\Enums\AuditPoleSection;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditPhoto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Photos of an audit: where a photo may go (the folders asked for the box, with their sub-folders), receiving one
 * from a phone (once per operation identifier) and the rules to delete or move them.
 */
class AuditPhotoStore
{
    public const string POINT_FOLDER = 'point';

    /**
     * How far back the time given by the phone is trusted for a photo taken on site, in days: the same window as the
     * other operations done offline.
     */
    public const int CAPTURE_TRUSTED_DAYS = AuditOperationLog::CLIENT_TIME_TRUSTED_DAYS;

    public function __construct(private StoreUploadedFile $files, private BoxFolders $folders, private AuditOperationLog $log, private FieldPhotoRules $designOffice) {}

    /**
     * Receives a photo. A technician cannot add photos to a validated audit and their photo reopens a finished one;
     * only a manager imports photos taken elsewhere. A photo taken without a position records the absence of signal the
     * phone declared (it is still received). An audit of the design office workflow adds its own rules
     * (FieldPhotoRules): who sends, the location or a declared absence of signal, no photo while the box is reviewed
     * or once the PM is closed, and the same content in the same folder is received once.
     *
     * @param  array{box?: string|null, point?: int|string|null, folder?: string|null, subfolder?: string|null, captured_at?: string|null, latitude?: mixed, longitude?: mixed, geo_missing_reason?: string|null, imported?: bool, client_created_at?: string|null, queued?: bool}  $meta
     * @return array{duplicate: bool, revision: int, photo: int|null}
     *
     * @throws AuditOperationException
     * @throws WorkflowException
     */
    public function add(User $actor, Audit $audit, string $operationId, UploadedFile $upload, array $meta): array
    {
        $manager = $actor->can('manage', Audit::class);

        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $operationId) !== 1) {
            throw new AuditOperationException('operation_id_required');
        }

        if (! empty($meta['imported']) && ! $manager) {
            throw new AuditOperationException('forbidden', 403);
        }

        return DB::transaction(function () use ($actor, $audit, $operationId, $upload, $meta, $manager): array {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $hash = $this->log->hash($audit, 'photo', $meta + ['file' => hash_file('sha256', (string) $upload->getRealPath())]);

            if ($this->log->alreadyApplied($actor, $operationId, $hash)) {
                return ['duplicate' => true, 'revision' => $audit->revision, 'photo' => null];
            }

            if (! $manager && $audit->isValidated()) {
                throw new AuditOperationException('validated', 409);
            }

            [$box, $point, $folder, $subfolder] = $this->target($audit, $meta);
            $imported = ! empty($meta['imported']);
            $position = $imported ? null : $this->position($meta['latitude'] ?? null, $meta['longitude'] ?? null);
            $geo = $audit->usesDesignOfficeWorkflow()
                ? $this->designOffice->admit($actor, $audit, $box, $imported, $position, $meta['geo_missing_reason'] ?? null, $meta['captured_at'] ?? null)
                : $this->declaredMissingPosition($imported, $position, $meta['geo_missing_reason'] ?? null);

            try {
                $stored = UploadRules::isPhoto($upload) ? $this->files->handleImage($upload) : throw new InvalidArgumentException('Not a photo');
            } catch (InvalidArgumentException) {
                throw new AuditOperationException('invalid_image', 415);
            }

            $timing = $this->log->timing($actor, $meta['client_created_at'] ?? null, ($meta['queued'] ?? false) === true);
            $receivedAt = $timing['received_at'];

            if ($audit->usesDesignOfficeWorkflow() && ($same = $this->designOffice->duplicateOf($audit, $box, $point?->id, $folder, $subfolder, $stored)) !== null) {
                $this->files->release($stored);
                $this->log->record($actor, $audit, $operationId, 'photo', $hash, $timing);

                return ['duplicate' => true, 'revision' => $audit->revision, 'photo' => $same->id];
            }

            $photo = AuditPhoto::query()->create([
                'audit_id' => $audit->id, 'audit_box_id' => $box?->id, 'audit_control_point_id' => $point?->id, 'folder' => $folder, 'subfolder' => $subfolder,
                'stored_file_id' => $stored->id, 'captured_at' => $this->capturedAt($meta['captured_at'] ?? null, $receivedAt, $imported),
                'latitude' => $position[0] ?? null, 'longitude' => $position[1] ?? null,
                'user_id' => $actor->id, 'user_name' => $actor->name, 'is_imported' => $imported, 'received_at' => $receivedAt,
                'is_geo_missing' => $geo['is_geo_missing'], 'geo_missing_reason' => $geo['geo_missing_reason'],
            ]);

            if ($audit->usesDesignOfficeWorkflow()) {
                $this->designOffice->recordReceived($actor, $photo, $stored);
            }

            if (! $manager && $audit->isFinished()) {
                $audit->update(['finished_by' => null, 'finished_by_name' => null, 'finished_at' => null]);
            }

            $this->log->record($actor, $audit, $operationId, 'photo', $hash, $timing);
            $audit->increment('revision');

            return ['duplicate' => false, 'revision' => $audit->revision, 'photo' => $photo->id];
        });
    }

    /**
     * Outside the design office workflow: a photo without a position is marked with the absence of signal the phone
     * declared; anything else (an imported photo, an unknown reason) is not marked.
     *
     * @param  array{string, string}|null  $position
     * @return array{is_geo_missing: bool, geo_missing_reason: AuditGeoMissingReason|null}
     */
    private function declaredMissingPosition(bool $imported, ?array $position, mixed $declaredReason): array
    {
        $reason = ! $imported && $position === null && is_string($declaredReason) ? AuditGeoMissingReason::tryFrom($declaredReason) : null;

        return in_array($reason, AuditGeoMissingReason::declarable(), true)
            ? ['is_geo_missing' => true, 'geo_missing_reason' => $reason]
            : ['is_geo_missing' => false, 'geo_missing_reason' => null];
    }

    /**
     * Deletes a photo and gives its file back. A manager always may; a technician only their own photos and only
     * while the audit is neither finished nor validated.
     *
     * @throws AuditOperationException
     */
    public function delete(User $actor, Audit $audit, int $photoId, bool $manager): void
    {
        $photo = AuditPhoto::query()->where('audit_id', $audit->id)->whereKey($photoId)->first();

        if ($photo === null) {
            return;
        }

        if (! $manager && ($photo->user_id !== $actor->id || $audit->isFinished() || $audit->isValidated())) {
            throw new AuditOperationException('forbidden', 403);
        }

        app(FieldDataGuard::class)->ensurePhotosChangeable($audit, [$photo->id]);

        $file = $photo->file;
        $photo->delete();
        $this->files->release($file);
    }

    /**
     * Moves photos of a box to another folder of the same box.
     *
     * @param  list<int>  $ids
     *
     * @throws AuditOperationException
     */
    public function move(Audit $audit, AuditBox $box, string $folder, ?string $subfolder, array $ids): void
    {
        if ($ids === [] || count($ids) > 500) {
            throw new AuditOperationException('no_photos');
        }

        [$folder, $subfolder] = $this->boxFolder($audit, $box, $folder, $subfolder);
        app(FieldDataGuard::class)->ensurePhotosChangeable($audit, AuditPhoto::query()->where('audit_box_id', $box->id)->whereIn('id', $ids)->pluck('id')->all());

        AuditPhoto::query()->where('audit_box_id', $box->id)->whereIn('id', $ids)->update(['folder' => $folder, 'subfolder' => $subfolder]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{AuditBox|null, AuditControlPoint|null, string, string}
     */
    private function target(Audit $audit, array $meta): array
    {
        if (isset($meta['point']) && $meta['point'] !== '') {
            $point = $audit->controlPoints()->whereKey((int) $meta['point'])->first() ?? throw new AuditOperationException('point_not_found', 404);
            $subfolder = strtoupper((string) ($meta['subfolder'] ?? ''));
            $allowed = array_map('strtoupper', (array) $point->subfolders);

            if (($allowed !== [] && ! in_array($subfolder, $allowed, true)) || ($allowed === [] && $subfolder !== '')) {
                throw new AuditOperationException('bad_subfolder');
            }

            return [null, $point, self::POINT_FOLDER, strtolower($subfolder)];
        }

        $box = $audit->boxes()->where('name', BoxName::normalise((string) ($meta['box'] ?? '')))->first() ?? throw new AuditOperationException('box_not_found', 404);
        [$folder, $subfolder] = $this->boxFolder($audit, $box, (string) ($meta['folder'] ?? ''), $meta['subfolder'] ?? null);

        return [$box, null, $folder, $subfolder];
    }

    /**
     * The folder and sub-folder a photo of the box may take: the folders asked for it (plus the « before works » and
     * « paper recheck » ones), a pole section for the pole folder, a cable code for the labels and lashing of the
     * optical mode, nothing otherwise.
     *
     * @return array{string, string}
     *
     * @throws AuditOperationException
     */
    private function boxFolder(Audit $audit, AuditBox $box, string $folder, ?string $subfolder): array
    {
        $mode = $audit->effectiveMode();
        $kind = AuditFolder::tryFrom($folder);
        $allowed = [...$this->folders->for($box, $mode, true), AuditFolder::Before, AuditFolder::Recheck];

        if ($kind === null || ! in_array($kind, $allowed, true)) {
            throw new AuditOperationException('bad_folder');
        }

        $subfolder = trim((string) $subfolder);

        if ($kind === AuditFolder::Pole) {
            $section = AuditPoleSection::tryFrom(strtolower($subfolder)) ?? throw new AuditOperationException('bad_subfolder');

            return [$kind->value, $section->value];
        }

        if ($mode === AuditMode::Optical && in_array($kind, [AuditFolder::Labels, AuditFolder::Lashing], true)) {
            return [$kind->value, preg_match('/^[A-Z0-9]{3,12}$/', $subfolder) === 1 ? $subfolder : ''];
        }

        return [$kind->value, ''];
    }

    /**
     * @return array{string, string}|null
     */
    private function position(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if (abs($lat) > 90 || abs($lng) > 180 || (abs($lat) < 1e-9 && abs($lng) < 1e-9)) {
            return null;
        }

        return [number_format($lat, 7, '.', ''), number_format($lng, 7, '.', '')];
    }

    /**
     * When the photo was taken, as the phone says (it may have been sent much later from offline). The time of the
     * server stays the reference: a time in the future is brought back to the reception, and for a photo taken on
     * site a time older than 7 days before the reception is not trusted and the reception time is kept. Photos imported
     * by a manager keep the date of their file.
     */
    private function capturedAt(?string $value, CarbonImmutable $receivedAt, bool $imported): CarbonImmutable
    {
        try {
            $at = $value === null || $value === '' ? null : CarbonImmutable::parse($value);
        } catch (\Throwable) {
            $at = null;
        }

        if ($at === null || $at->isAfter($receivedAt)) {
            return $receivedAt;
        }

        if (! $imported && $at->isBefore($receivedAt->subDays(self::CAPTURE_TRUSTED_DAYS))) {
            return $receivedAt;
        }

        return $at;
    }
}
