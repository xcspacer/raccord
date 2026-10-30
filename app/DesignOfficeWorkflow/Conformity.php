<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditConformity;
use App\Enums\AuditInspectionState;
use App\Enums\AuditOccurrenceState;
use App\Models\AuditBox;
use App\Models\User;

/**
 * Technical conformity of a box, derived (never typed): a validated inspection and no open occurrence make it
 * conforming, or conforming with reserve when one of its occurrences ended in an accepted reserve (which is not a
 * technical conformity). A box that conformed and no longer does (an anomaly confirmed on it since, its inspection
 * taken up again) goes back to « not assessed »; anything else leaves it as it was. Every change is journaled.
 */
class Conformity
{
    /** Occurrence states that keep a box from conforming. */
    private const array OPEN_STATES = [AuditOccurrenceState::Detected, AuditOccurrenceState::ToRepair, AuditOccurrenceState::Corrected, AuditOccurrenceState::Refused];

    public function __construct(private OccurrenceRules $occurrences, private WorkflowJournal $journal, private LegacyBoxStatus $legacyStatus) {}

    public function recompute(?User $actor, AuditBox $box): AuditConformity
    {
        $target = match (true) {
            $box->inspection_state !== AuditInspectionState::Validated,
            $this->occurrences->confirmedOn($box)->whereIn('state', self::OPEN_STATES)->exists() => $this->isConforming($box->conformity) ? AuditConformity::NotAssessed : $box->conformity,
            $this->occurrences->confirmedOn($box)->where('state', AuditOccurrenceState::ReserveAccepted)->exists() => AuditConformity::ConformingWithReserve,
            default => AuditConformity::Conforming,
        };

        if ($target !== $box->conformity) {
            $before = $box->conformity;
            $box->forceFill(['conformity' => $target])->save();
            $this->journal->record('box_conformity_changed', $box, $actor, ['box' => $box->name, 'old' => $before->value, 'new' => $target->value]);
            $this->legacyStatus->conformityReached($actor, $box);
        }

        return $target;
    }

    private function isConforming(AuditConformity $conformity): bool
    {
        return in_array($conformity, [AuditConformity::Conforming, AuditConformity::ConformingWithReserve], true);
    }
}
