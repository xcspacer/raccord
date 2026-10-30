<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use Illuminate\Database\Eloquent\Builder;

/**
 * Checks shared by every action of the design office workflow: the audit must use it (a standard audit answers 409,
 * whatever the action), and a closed audit no longer changes — except for the repair of an occurrence its client
 * declared after the closure, which is planned, corrected, submitted and decided without reopening the audit
 * (spec 13-44). Everything else stays refused on a closed audit.
 */
class WorkflowGuard
{
    /** States of a client occurrence whose repair is still under way. */
    private const array CLIENT_REPAIR_STATES = [AuditOccurrenceState::Detected, AuditOccurrenceState::ToRepair, AuditOccurrenceState::Refused, AuditOccurrenceState::Corrected];

    /**
     * @throws WorkflowException
     */
    public function ensureDesignOffice(Audit $audit): void
    {
        if (! $audit->usesDesignOfficeWorkflow()) {
            throw new WorkflowException('standard_workflow', 409);
        }
    }

    /**
     * @throws WorkflowException
     */
    public function ensureOpen(Audit $audit): void
    {
        $this->ensureDesignOffice($audit);

        if ($audit->isClosed()) {
            throw new WorkflowException('audit_closed', 409);
        }
    }

    /**
     * Repair work on a box (planning, photos, submission): an open audit, or a closed one while an occurrence the
     * client declared is still being repaired on that box.
     *
     * @throws WorkflowException
     */
    public function ensureOpenOrClientRepair(Audit $audit, AuditBox $box): void
    {
        $this->ensureDesignOffice($audit);

        if ($audit->isClosed() && ! self::hasClientRepair($box)) {
            throw new WorkflowException('audit_closed', 409);
        }
    }

    /**
     * Changes to an occurrence: an open audit, or a closed one when the client declared the occurrence.
     *
     * @throws WorkflowException
     */
    public function ensureOpenForOccurrence(AuditOccurrence $occurrence): void
    {
        $this->ensureDesignOffice($occurrence->audit);

        if ($occurrence->audit->isClosed() && $occurrence->source !== AuditOccurrenceSource::Client) {
            throw new WorkflowException('audit_closed', 409);
        }
    }

    /**
     * Whether an occurrence declared by the client, confirmed on the box, is still to repair or to decide.
     */
    public static function hasClientRepair(AuditBox $box): bool
    {
        return AuditOccurrence::query()
            ->where('source', AuditOccurrenceSource::Client)
            ->whereIn('state', self::CLIENT_REPAIR_STATES)
            ->whereHas('boxLinks', fn (Builder $links) => $links->where('audit_box_id', $box->id)->whereNotNull('confirmed_at')->whereNull('excluded_at'))
            ->exists();
    }

    /**
     * @throws WorkflowException
     */
    public function ensureBoxInService(AuditBox $box): void
    {
        if ($box->isRemoved()) {
            throw new WorkflowException('box_removed', 409, ['box' => $box->name]);
        }
    }
}
