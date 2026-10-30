<?php

namespace App\Logs;

use App\DesignOfficeWorkflow\WorkflowJournal;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Support\Config;

/**
 * The retention clean-up of the activity log never touches the hash-chained journals: they are kept without automatic
 * deletion (removing old entries would also break their chain).
 */
class CleanActivityLog extends CleanActivityLogAction
{
    /** @var list<string> */
    public const array KEPT_LOGS = [WorkflowJournal::LOG_NAME];

    protected function deleteOldActivities(string $cutOffDate, ?string $logName): int
    {
        if ($logName !== null && in_array($logName, self::KEPT_LOGS, true)) {
            return 0;
        }

        $model = Config::activityModelInstance();
        assert($model instanceof Model);

        return $model->newQuery()
            ->where('created_at', '<', $cutOffDate)
            ->where(fn ($query) => $query->whereNull('log_name')->orWhereNotIn('log_name', self::KEPT_LOGS))
            ->when($logName !== null, fn ($query) => $query->where('log_name', $logName))
            ->delete();
    }
}
