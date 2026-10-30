<?php

namespace App\DesignOfficeWorkflow;

use App\Audit\AuditOperationException;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditConformity;
use App\Enums\AuditOccurrenceState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status of a box of the standard workflow (OK, anomaly, not installed, not finished) on an audit that uses the
 * design office workflow, where it follows the workflow instead of being typed: « OK » once the box is conforming
 * (with or without an accepted reserve), « anomaly » as soon as an occurrence counts on the box (its references are the
 * reason), « not installed » stays a field statement that takes the box out of the count, and a box « to be done
 * again » is a repair of phase 2 (the « send back » of the standard workflow does not apply). Lists, exports and the
 * client portal keep reading the status as before.
 */
class LegacyBoxStatus
{
    public function __construct(private OccurrenceRules $occurrences) {}

    /**
     * Check a status typed on a box of a design office audit (the audit operation « box_status »).
     *
     * @throws AuditOperationException
     */
    public function ensureTypable(AuditBox $box, ?AuditBoxStatus $status): void
    {
        if ($status === AuditBoxStatus::Ok && ! in_array($box->conformity, [AuditConformity::Conforming, AuditConformity::ConformingWithReserve], true)) {
            throw new AuditOperationException('status_follows_decision', 409);
        }

        if ($status === AuditBoxStatus::Anomaly && ! $this->openOn($box)->exists()) {
            throw new AuditOperationException('occurrence_required', 409);
        }
    }

    /**
     * An occurrence now counts on the box: it shows as « anomaly », its open references as the reason.
     */
    public function occurrenceConfirmed(?User $actor, AuditBox $box, AuditOccurrence $occurrence): void
    {
        if ($box->status === AuditBoxStatus::NotInstalled) {
            return;
        }

        $references = $this->openOn($box)->orderBy('id')->pluck('reference')->push($occurrence->reference)->unique()->implode(', ');
        $this->apply($actor, $box, AuditBoxStatus::Anomaly, mb_substr($references, 0, 1000));
    }

    /**
     * The box became conforming (with or without reserve): it shows as « OK ».
     */
    public function conformityReached(?User $actor, AuditBox $box): void
    {
        if ($box->status === AuditBoxStatus::NotInstalled || $box->status === AuditBoxStatus::Ok) {
            return;
        }

        if (in_array($box->conformity, [AuditConformity::Conforming, AuditConformity::ConformingWithReserve], true)) {
            $this->apply($actor, $box, AuditBoxStatus::Ok, null);
        }
    }

    /**
     * Boxes that count in the conformity and closure of a design office audit: in service and installed.
     *
     * @param  Builder<AuditBox>  $boxes
     * @return Builder<AuditBox>
     */
    public static function counted(Builder $boxes): Builder
    {
        return $boxes->whereNull('removed_at')->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', AuditBoxStatus::NotInstalled->value));
    }

    /**
     * @return Builder<AuditOccurrence>
     */
    private function openOn(AuditBox $box): Builder
    {
        return $this->occurrences->confirmedOn($box)->whereNotIn('state', [AuditOccurrenceState::Validated, AuditOccurrenceState::ReserveAccepted]);
    }

    private function apply(?User $actor, AuditBox $box, AuditBoxStatus $status, ?string $reason): void
    {
        $box->forceFill([
            'status' => $status, 'reason' => $reason, 'status_at' => now(),
            'status_by' => $actor?->id, 'status_by_name' => $actor?->name,
        ])->save();
        Audit::query()->whereKey($box->audit_id)->increment('revision');
    }
}
