<?php

namespace App\ClientPortal;

use App\Audit\BoxProgress;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditPresenceState;
use App\Enums\AuditSupport;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditPhoto;
use App\Models\AuditPresence;
use App\Support\GeoDistance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the client portal shows of an audit: progress, counts by status, support and box family, production per
 * day, anomalies, crews on site right now and recent photos. Only audit data leaves here: people appear by their
 * first name, never by id.
 *
 * @phpstan-type Bucket array{total: int, done: int, ok: int, anomaly: int, not_installed: int, partial: int, todo: int}
 * @phpstan-type Live array{id: int, box: string, state: string, name: string, since: CarbonImmutable}
 * @phpstan-type Anomaly array{id: int, box: string, kind: string, support: string, reason: string, at: CarbonImmutable|null}
 * @phpstan-type Activity array{at: CarbonImmutable, box: string, folder: string, name: string}
 * @phpstan-type Overview array{audit: Audit, cabinet: string, sro: string, total: int, done: int, percent: int, by_status: array<string, int>, by_support: array<string, Bucket>, by_kind: array<string, Bucket>, per_day: array<string, int>, anomalies: list<Anomaly>, live: list<Live>, activity: list<Activity>, last_activity: CarbonImmutable|null, finished: bool, validated: bool}
 */
class PortalOverview
{
    public const array STATUSES = ['ok', 'anomaly', 'not_installed', 'partial', 'todo'];

    public const int LIVE_MINUTES = 20;

    public const int PHOTO_LIVE_MINUTES = 30;

    public const int NEAR_METRES = 150;

    public const int MAX_ACCURACY = 150;

    /** @var array<int, list<int>> Boxes to repair, per audit id. */
    private array $toRepair = [];

    public function __construct(private BoxProgress $progress) {}

    /**
     * Loads what the overview reads for several audits at once.
     *
     * @param  EloquentCollection<int, Audit>  $audits
     */
    public function load(EloquentCollection $audits): void
    {
        $audits->load([
            'boxes.photos:id,audit_box_id,folder,subfolder,captured_at,latitude,longitude,user_name,is_imported',
            'boxes.cables',
            'presences.user:id,name',
        ]);
    }

    /**
     * @return Overview
     */
    public function of(Audit $audit, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $boxes = $audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved())->values();
        $mode = $audit->effectiveMode();
        $byStatus = array_fill_keys(self::STATUSES, 0);
        $bySupport = ['aerial' => $this->bucket(), 'underground' => $this->bucket(), 'unknown' => $this->bucket()];
        $byKind = ['bpe' => $this->bucket(), 'pbo' => $this->bucket(), 'jd' => $this->bucket()];
        $perDay = [];
        $anomalies = [];
        $percentSum = 0;

        foreach ($boxes as $box) {
            $status = $this->status($box);
            $final = in_array($status, ['ok', 'anomaly', 'not_installed'], true);
            $support = $this->support($box);
            $kind = $box->kind->value;

            $byStatus[$status]++;

            foreach ([&$bySupport[$support], &$byKind[$kind]] as &$bucket) {
                $bucket['total']++;
                $bucket[$status]++;
                $bucket['done'] += $final ? 1 : 0;
            }
            unset($bucket);

            $percentSum += $this->progress->percent($box, $mode);
            $doneAt = $this->doneAt($box);

            if ($final && $doneAt !== null) {
                $day = $doneAt->toDateString();
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            }

            if ($status === 'anomaly') {
                $anomalies[] = ['id' => $box->id, 'box' => $box->name, 'kind' => $kind, 'support' => $support, 'reason' => (string) $box->reason, 'at' => $doneAt];
            }
        }

        ksort($perDay);
        $total = $boxes->count();
        $activity = $this->activity($boxes);

        return [
            'audit' => $audit,
            'cabinet' => $audit->cabinet_key,
            'sro' => (string) $audit->sro,
            'total' => $total,
            'done' => $byStatus['ok'] + $byStatus['anomaly'] + $byStatus['not_installed'],
            'percent' => $total > 0 ? (int) round($percentSum / $total) : 0,
            'by_status' => $byStatus,
            'by_support' => $bySupport,
            'by_kind' => $byKind,
            'per_day' => $perDay,
            'anomalies' => $anomalies,
            'live' => $this->live($audit, $boxes, $now),
            'activity' => array_slice($activity, 0, 12),
            'last_activity' => $activity[0]['at'] ?? ($audit->updated_at === null ? null : CarbonImmutable::parse($audit->updated_at)),
            'finished' => $audit->finished_at !== null,
            'validated' => $audit->isValidated(),
        ];
    }

    /**
     * The status the client sees: a box sent back by the office is « to redo », a box without status « to do ». On a
     * design office audit a box is « to redo » while an occurrence confirmed on it is to repair (decision 2: « to redo »
     * is a repair of phase 2).
     */
    public function status(AuditBox $box): string
    {
        if ($box->isReturned() || in_array($box->id, $this->boxesToRepair($box->audit_id), true)) {
            return 'partial';
        }

        return match ($box->status) {
            AuditBoxStatus::Ok => 'ok',
            AuditBoxStatus::Anomaly => 'anomaly',
            AuditBoxStatus::NotInstalled => 'not_installed',
            AuditBoxStatus::Partial => 'partial',
            default => 'todo',
        };
    }

    /**
     * Boxes of the audit with an occurrence confirmed on them still to repair (read once per audit).
     *
     * @return list<int>
     */
    private function boxesToRepair(int $auditId): array
    {
        return $this->toRepair[$auditId] ??= AuditOccurrenceBox::query()->whereNotNull('confirmed_at')->whereNull('excluded_at')
            ->whereHas('occurrence', fn (Builder $occurrences) => $occurrences->where('audit_id', $auditId)->whereIn('state', [AuditOccurrenceState::ToRepair, AuditOccurrenceState::Refused]))
            ->distinct()->pluck('audit_box_id')->map(fn (mixed $id) => (int) $id)->values()->all();
    }

    public function support(AuditBox $box): string
    {
        return match ($box->support) {
            AuditSupport::Pole, AuditSupport::Facade => 'aerial',
            AuditSupport::Chamber => 'underground',
            default => 'unknown',
        };
    }

    /**
     * When the box was finished: its status time, else the time its PDF was written.
     */
    public function doneAt(AuditBox $box): ?CarbonImmutable
    {
        $at = $box->status_at ?? $box->pdf_generated_at;

        return $at === null ? null : CarbonImmutable::parse($at)->setTimezone(config('platform.timezone'));
    }

    public static function firstName(?string $name): string
    {
        return Str::ucfirst(Str::lower((string) Str::of((string) $name)->trim()->explode(' ')->first()));
    }

    /**
     * First name and initial of the last name (« Jane D. »): how a staff member who answers the client is named (D15).
     * A name whose last word is not a name (an anonymised « Former employee #12 ») is shown whole.
     */
    public static function shortName(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $initial = count($words) > 1 ? mb_substr((string) end($words), 0, 1) : '';

        if ($initial !== '' && preg_match('/^\p{L}$/u', $initial) !== 1) {
            return trim((string) $name);
        }

        return self::firstName($name).($initial !== '' ? ' '.mb_strtoupper($initial).'.' : '');
    }

    /**
     * Photos taken on site (not imported), newest first.
     *
     * @param  Collection<int, AuditBox>  $boxes
     * @return list<Activity>
     */
    private function activity(Collection $boxes): array
    {
        return $boxes
            ->flatMap(fn (AuditBox $box) => $box->photos->reject(fn (AuditPhoto $photo) => $photo->is_imported)->map(fn (AuditPhoto $photo) => [
                'at' => CarbonImmutable::parse($photo->captured_at),
                'box' => $box->name,
                'folder' => AuditFolder::tryFrom($photo->folder)?->label() ?? $photo->folder,
                'name' => self::firstName($photo->user_name),
            ]))
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    /**
     * Crews really on site: a fresh « on the box » presence whose GPS is near the box, else a geolocated photo near
     * a box not yet compliant in the last half hour. « On the way » and positions without GPS are never shown.
     *
     * @param  Collection<int, AuditBox>  $boxes
     * @return list<Live>
     */
    private function live(Audit $audit, Collection $boxes, CarbonImmutable $now): array
    {
        $byId = $boxes->keyBy('id');
        $live = [];

        foreach ($audit->presences as $presence) {
            /** @var AuditPresence $presence */
            $box = $byId->get($presence->audit_box_id);
            $position = $box === null ? null : $this->position($box);
            $latitude = $presence->getAttribute('latitude');
            $longitude = $presence->getAttribute('longitude');
            $accuracy = $presence->getAttribute('accuracy');

            if ($presence->state !== AuditPresenceState::OnBox || $presence->seen_at->lt($now->subMinutes(self::LIVE_MINUTES))
                || $box === null || $position === null || $latitude === null || $longitude === null
                || ($accuracy !== null && (int) $accuracy > self::MAX_ACCURACY)
                || GeoDistance::metres((float) $latitude, (float) $longitude, $position[0], $position[1]) > self::NEAR_METRES) {
                continue;
            }

            $live[$box->name] = ['id' => $box->id, 'box' => $box->name, 'state' => 'on_box', 'name' => self::firstName($presence->user?->name), 'since' => CarbonImmutable::parse($presence->since)];
        }

        foreach ($boxes as $box) {
            $position = $this->position($box);

            if (isset($live[$box->name]) || $position === null || $this->status($box) === 'ok') {
                continue;
            }

            $photo = $box->photos
                ->filter(fn (AuditPhoto $photo) => ! $photo->is_imported && $photo->latitude !== null && $photo->longitude !== null
                    && $photo->captured_at->gte($now->subMinutes(self::PHOTO_LIVE_MINUTES))
                    && GeoDistance::metres((float) $photo->latitude, (float) $photo->longitude, $position[0], $position[1]) <= self::NEAR_METRES)
                ->sortByDesc('captured_at')
                ->first();

            if ($photo instanceof AuditPhoto) {
                $live[$box->name] = ['id' => $box->id, 'box' => $box->name, 'state' => 'photo', 'name' => self::firstName($photo->user_name), 'since' => CarbonImmutable::parse($photo->captured_at)];
            }
        }

        return array_values($live);
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function position(AuditBox $box): ?array
    {
        $latitude = $box->confirmed_latitude ?? $box->latitude;
        $longitude = $box->confirmed_longitude ?? $box->longitude;

        return $latitude === null || $longitude === null ? null : [(float) $latitude, (float) $longitude];
    }

    /**
     * @return Bucket
     */
    private function bucket(): array
    {
        return ['total' => 0, 'done' => 0, 'ok' => 0, 'anomaly' => 0, 'not_installed' => 0, 'partial' => 0, 'todo' => 0];
    }
}
