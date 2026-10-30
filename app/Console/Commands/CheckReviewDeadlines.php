<?php

namespace App\Console\Commands;

use App\DesignOfficeWorkflow\ReviewQueue;
use App\Enums\Module;
use App\Settings\ModuleSettings;
use Illuminate\Console\Command;

/**
 * Every minute: alerts on validation requests reaching the alert time, marks those past their deadline late (the
 * supervisors are alerted and it is journaled) and hands out what waits in the central queue. Running it twice
 * changes nothing.
 */
class CheckReviewDeadlines extends Command
{
    protected $signature = 'design-office:check-deadlines';

    protected $description = 'Alert and mark late the design office validation requests, then dispatch the queue';

    public function handle(ReviewQueue $queue, ModuleSettings $modules): int
    {
        if (! $modules->isEnabled(Module::DesignOfficeWorkflow)) {
            return self::SUCCESS;
        }

        $result = $queue->checkDeadlines();

        $this->info("{$result['alerted']} request(s) alerted, {$result['late']} request(s) late.");

        return self::SUCCESS;
    }
}
