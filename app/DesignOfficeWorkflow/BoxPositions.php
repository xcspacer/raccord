<?php

namespace App\DesignOfficeWorkflow;

use App\Audit\Import\KmlReader;
use App\Enums\AuditPositionSource;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxPosition;
use App\Models\AuditDocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The positions of a box in the design office workflow, never overwritten: the one read from each map file (by box
 * name), the one taken on site, the corrections proposed from the field, and the official one, approved by the design
 * office or a supervisor from any of them. « J'y vais » always leads to the official one.
 */
class BoxPositions
{
    /** Shortest reason for rejecting a proposed correction. */
    public const int MIN_REJECTION_REASON = 3;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * Positions of the map file: one « map origin » position per box found by name, added only when it differs from the
     * box's previous one. Names that match no box are returned so the design office can check them.
     *
     * @param  array<string, array{float, float}>  $placemarks  Name => latitude, longitude.
     * @return array{matched: int, unmatched: list<string>}
     */
    public function importMap(Audit $audit, AuditDocumentVersion $version, array $placemarks, ?User $actor): array
    {
        $boxes = $audit->boxes()->whereNull('removed_at')->get(['id', 'name']);
        $matched = 0;
        $unmatched = [];

        foreach ($placemarks as $name => [$latitude, $longitude]) {
            $point = $this->point($latitude, $longitude);
            $box = $boxes->first(fn (AuditBox $candidate) => KmlReader::sameBox($candidate->name, (string) $name));

            if ($box === null || $point === null) {
                $unmatched[] = (string) $name;

                continue;
            }

            $matched++;
            $last = AuditBoxPosition::query()->where('audit_box_id', $box->id)->where('source', AuditPositionSource::MapOrigin->value)->latest('id')->first();

            if ($last === null || ! $this->same($last, $point)) {
                AuditBoxPosition::query()->create([
                    'audit_box_id' => $box->id, 'source' => AuditPositionSource::MapOrigin, 'latitude' => $point[0], 'longitude' => $point[1],
                    'audit_document_version_id' => $version->id, 'recorded_by' => $actor?->id,
                ]);
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * The position taken on site when a technician confirms where the box is (« box_pos » operation of the phone).
     * Nothing is recorded once the PM is closed.
     */
    public function recordField(User $actor, AuditBox $box, string $latitude, string $longitude, ?int $accuracy): void
    {
        $box->loadMissing('audit');

        if (! $box->audit->usesDesignOfficeWorkflow() || $box->audit->isClosed()) {
            return;
        }

        $position = AuditBoxPosition::query()->create([
            'audit_box_id' => $box->id, 'source' => AuditPositionSource::Field, 'latitude' => $latitude, 'longitude' => $longitude,
            'accuracy' => $accuracy, 'recorded_by' => $actor->id,
        ]);
        $this->journal->record('position_recorded', $position, $actor, ['box' => $box->name, 'source' => AuditPositionSource::Field->value]);
    }

    /**
     * A technician proposes a corrected position (a point moved on the map). Sending the same point again while it waits
     * answers the waiting proposal.
     *
     * @return array{position: AuditBoxPosition, duplicate: bool}
     *
     * @throws WorkflowException
     */
    public function propose(User $technician, AuditBox $box, mixed $latitude, mixed $longitude, mixed $accuracy = null): array
    {
        $point = $this->point($latitude, $longitude) ?? throw new WorkflowException('position_invalid');

        return DB::transaction(function () use ($technician, $box, $point, $accuracy): array {
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
            $this->guard->ensureOpen($box->audit);
            $this->guard->ensureBoxInService($box);

            $waiting = AuditBoxPosition::query()->where('audit_box_id', $box->id)->where('source', AuditPositionSource::Proposed->value)
                ->where('recorded_by', $technician->id)->whereNull('approved_at')->whereNull('rejected_at')->get()
                ->first(fn (AuditBoxPosition $position) => $this->same($position, $point));

            if ($waiting !== null) {
                return ['position' => $waiting, 'duplicate' => true];
            }

            $position = AuditBoxPosition::query()->create([
                'audit_box_id' => $box->id, 'source' => AuditPositionSource::Proposed, 'latitude' => $point[0], 'longitude' => $point[1],
                'accuracy' => is_numeric($accuracy) ? (int) round(min(9999, max(0, (float) $accuracy))) : null, 'recorded_by' => $technician->id,
            ]);
            $this->journal->record('position_proposed', $position, $technician, ['box' => $box->name, 'latitude' => $point[0], 'longitude' => $point[1]]);

            return ['position' => $position, 'duplicate' => false];
        });
    }

    /**
     * The design office (or a supervisor) makes a position of the box the official one: a new official position is
     * added with its coordinates; the earlier ones stay. A proposal is approved once.
     *
     * @throws WorkflowException
     */
    public function approve(User $reviewer, AuditBoxPosition $position): AuditBoxPosition
    {
        return DB::transaction(function () use ($reviewer, $position): AuditBoxPosition {
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($position->audit_box_id);
            $position = AuditBoxPosition::query()->lockForUpdate()->findOrFail($position->id);
            $this->guard->ensureOpen($box->audit);

            if ($position->source === AuditPositionSource::Official) {
                throw new WorkflowException('position_already_official', 409);
            }

            if ($position->rejected_at !== null || ($position->source === AuditPositionSource::Proposed && $position->approved_at !== null)) {
                throw new WorkflowException('position_already_decided', 409);
            }

            $position->forceFill(['approved_by' => $reviewer->id, 'approved_at' => now()])->save();
            $official = AuditBoxPosition::query()->create([
                'audit_box_id' => $box->id, 'source' => AuditPositionSource::Official, 'latitude' => $position->latitude, 'longitude' => $position->longitude,
                'accuracy' => $position->accuracy, 'audit_document_version_id' => $position->audit_document_version_id, 'recorded_by' => $position->recorded_by,
                'approved_by' => $reviewer->id, 'approved_at' => now(),
            ]);
            $this->journal->record('position_approved', $official, $reviewer, [
                'box' => $box->name, 'from' => $position->source->value, 'from_id' => $position->id, 'latitude' => $official->latitude, 'longitude' => $official->longitude,
            ]);

            return $official;
        });
    }

    /**
     * @throws WorkflowException
     */
    public function reject(User $reviewer, AuditBoxPosition $position, string $reason): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REJECTION_REASON) {
            throw new WorkflowException('reason_required');
        }

        DB::transaction(function () use ($reviewer, $position, $reason): void {
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($position->audit_box_id);
            $position = AuditBoxPosition::query()->lockForUpdate()->findOrFail($position->id);
            $this->guard->ensureOpen($box->audit);

            if (! $position->isPending()) {
                throw new WorkflowException('position_already_decided', 409);
            }

            $position->forceFill(['rejected_by' => $reviewer->id, 'rejected_at' => now(), 'rejection_reason' => mb_substr($reason, 0, 500)])->save();
            $this->journal->record('position_rejected', $position, $reviewer, ['box' => $box->name, 'reason' => $reason]);
        });
    }

    /**
     * What is known of the box's position: the latest of each source, the corrections waiting for a decision and the
     * official point « J'y vais » leads to.
     *
     * @return array{official: AuditBoxPosition|null, map_origin: AuditBoxPosition|null, field: AuditBoxPosition|null, pending: list<AuditBoxPosition>, proposals: list<AuditBoxPosition>}
     */
    public function summary(AuditBox $box): array
    {
        $positions = AuditBoxPosition::query()->where('audit_box_id', $box->id)->with(['recorder:id,name', 'approver:id,name'])->orderByDesc('id')->get();
        $latest = fn (AuditPositionSource $source) => $positions->first(fn (AuditBoxPosition $position) => $position->source === $source);
        $proposals = $positions->filter(fn (AuditBoxPosition $position) => $position->source === AuditPositionSource::Proposed)->values();

        return [
            'official' => $latest(AuditPositionSource::Official),
            'map_origin' => $latest(AuditPositionSource::MapOrigin),
            'field' => $latest(AuditPositionSource::Field),
            'pending' => $proposals->filter(fn (AuditBoxPosition $position) => $position->isPending())->values()->all(),
            'proposals' => $proposals->all(),
        ];
    }

    /**
     * Links of the navigation apps to a position.
     *
     * @return array<string, string>
     */
    public static function navigationLinks(AuditBoxPosition $position): array
    {
        return collect((array) config('audit.navigation'))
            ->map(fn (string $template) => strtr($template, [':lat' => (string) (float) $position->latitude, ':lng' => (string) (float) $position->longitude]))
            ->all();
    }

    /**
     * @return array{string, string}|null Latitude and longitude with seven decimals, or null when not usable.
     */
    private function point(mixed $latitude, mixed $longitude): ?array
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
     * @param  array{string, string}  $point
     */
    private function same(AuditBoxPosition $position, array $point): bool
    {
        return abs((float) $position->latitude - (float) $point[0]) < 1e-7 && abs((float) $position->longitude - (float) $point[1]) < 1e-7;
    }
}
