<?php

namespace App\Console\Commands;

use App\Models\Audit;
use App\Models\BillingEntry;
use App\Models\InventoryItem;
use App\Models\UserDocument;
use App\Models\Vehicle;
use App\Models\WorkSite;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Deleted records are kept (they can be restored) for the retention period, then erased for good; their files go
 * with the next orphan clean-up. People, agencies, clients, companies and expenses are never purged here: they are
 * kept for the records that name them and follow their own retention rules. The retention is 12 months unless the
 * installation configures another one; nothing happens when it is switched off (0).
 */
class PurgeDeletedRecords extends Command
{
    protected $signature = 'records:purge-deleted';

    protected $description = 'Erase deleted records whose retention period is over';

    /** @var list<class-string<Model>> */
    public const array MODELS = [Audit::class, BillingEntry::class, InventoryItem::class, UserDocument::class, Vehicle::class, WorkSite::class];

    public function handle(): int
    {
        $days = config('platform.deleted_records_retention_days');

        if ($days === null) {
            $this->info('No retention period configured: nothing erased.');

            return self::SUCCESS;
        }

        $before = now()->subDays((int) $days);
        $count = 0;
        $kept = 0;

        foreach (self::MODELS as $model) {
            $model::query()->onlyTrashed()->where('deleted_at', '<', $before)->chunkById(100, function ($records) use (&$count, &$kept): void {
                foreach ($records as $record) {
                    try {
                        $record->forceDelete();
                        $count++;
                    } catch (QueryException) {
                        $kept++;
                    }
                }
            });
        }

        $this->info("{$count} deleted record(s) erased, {$kept} still in use and kept.");

        return self::SUCCESS;
    }
}
