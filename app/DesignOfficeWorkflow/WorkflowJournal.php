<?php

namespace App\DesignOfficeWorkflow;

use App\Logs\HashChainedJournal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Journal of the design office workflow: every claim, hand-over, submission, assignment, decision, alert and catalogue
 * change, kept in the platform's activity log under its own name, hash-chained and verified every day. Internal only
 * (never shown to a client) and never cleaned automatically.
 */
class WorkflowJournal
{
    public const string LOG_NAME = 'design_office_workflow';

    public function __construct(private HashChainedJournal $journal) {}

    /**
     * @param  array<string, mixed>  $properties  Old and new values, reason, correlation id… (scalars and lists only).
     */
    public function record(string $event, ?Model $subject, ?User $actor, array $properties = []): Activity
    {
        return $this->journal->append(self::LOG_NAME, $event, $subject, $actor, $properties);
    }

    /**
     * @return array{intact: bool, checked: int, broken_at: int|null}
     */
    public function verify(): array
    {
        return $this->journal->verify(self::LOG_NAME);
    }
}
