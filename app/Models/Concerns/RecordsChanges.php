<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Records every creation, change and deletion in the « Logs » journal with the complete record before
 * and after, so an administrator can restore it. Secrets are never recorded.
 */
trait RecordsChanges
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['password', 'legacy_password_hash', 'remember_token'])
            ->dontLogEmptyChanges()
            ->useLogName(static::activityLogName());
    }

    /**
     * Journal type the record is listed under (filter « Chantiers / Tâches / Utilisateurs »).
     */
    abstract public static function activityLogName(): string;
}
