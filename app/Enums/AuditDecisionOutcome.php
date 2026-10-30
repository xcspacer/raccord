<?php

namespace App\Enums;

/**
 * Effect of a decision of the design office on a box. Every decision type of the catalogue has one.
 */
enum AuditDecisionOutcome: string
{
    case Validate = 'validate';
    case ValidateWithReserves = 'validate_with_reserves';
    case Refuse = 'refuse';
    case RequestControl = 'request_control';
    case AuthorizeContinuation = 'authorize_continuation';
    case RepairsValidated = 'repairs_validated';
    case RepairsPartial = 'repairs_partial';
    case RepairsRefused = 'repairs_refused';

    public function label(): string
    {
        return __('enums.audit_decision_outcomes.'.$this->value);
    }

    public function submissionKind(): AuditSubmissionKind
    {
        return in_array($this, [self::RepairsValidated, self::RepairsPartial, self::RepairsRefused], true) ? AuditSubmissionKind::Repair : AuditSubmissionKind::Inspection;
    }

    /**
     * Whether the decision validates the inspection of the box (it then unlocks the responsible technician).
     */
    public function validatesInspection(): bool
    {
        return in_array($this, [self::Validate, self::ValidateWithReserves, self::AuthorizeContinuation], true);
    }
}
