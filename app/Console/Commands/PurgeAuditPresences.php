<?php

namespace App\Console\Commands;

use App\Models\AuditPresence;
use Illuminate\Console\Command;

/**
 * Presences on audit boxes (with their position) are only useful while they are live: the client portal shows the
 * last 20 minutes. They are erased after the retention period (RGPD-04).
 */
class PurgeAuditPresences extends Command
{
    protected $signature = 'audits:purge-presences';

    protected $description = 'Erase the presences on audit boxes older than the retention period';

    public function handle(): int
    {
        $count = AuditPresence::query()->where('seen_at', '<', now()->subDays((int) config('platform.presence_retention_days')))->delete();

        $this->info("{$count} presence(s) erased.");

        return self::SUCCESS;
    }
}
