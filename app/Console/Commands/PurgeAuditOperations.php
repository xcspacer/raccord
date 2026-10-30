<?php

namespace App\Console\Commands;

use App\Audit\AuditOperationLog;
use Illuminate\Console\Command;

class PurgeAuditOperations extends Command
{
    protected $signature = 'audits:purge-operations';

    protected $description = 'Remove the record of applied audit operations older than the retention period';

    public function handle(AuditOperationLog $log): int
    {
        $count = $log->purge();

        $this->info("{$count} applied operation(s) removed.");

        return self::SUCCESS;
    }
}
