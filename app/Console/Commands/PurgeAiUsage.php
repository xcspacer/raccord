<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The AI usage counter only serves the quotas (current UTC day and last hour): its rows are erased after the
 * retention period, never before two days so the running quotas stay right.
 */
class PurgeAiUsage extends Command
{
    protected $signature = 'ai:purge-usage';

    protected $description = 'Erase the AI usage rows older than the retention period';

    public function handle(): int
    {
        $days = max(2, (int) config('platform.ai_usage_retention_days'));
        $count = DB::table('ai_usage')->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("{$count} AI usage row(s) erased.");

        return self::SUCCESS;
    }
}
