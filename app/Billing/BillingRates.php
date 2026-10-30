<?php

namespace App\Billing;

use App\Enums\BillingSchedule;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingInterventionType;
use App\Models\BillingRate;
use App\Models\BillingRateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prices of the billing clients and of the works price list. Every price has a key; a missing price is 0. Each
 * change is kept in the history with who made it.
 */
class BillingRates
{
    /** @var array<string, float>|null */
    private ?array $amounts = null;

    public static function typeKey(BillingInterventionType $type): string
    {
        return 'type:'.$type->id;
    }

    public static function gridKey(BillingSchedule $schedule, int $level, BillingInterventionType $type): string
    {
        return 'grid:'.$schedule->value.':'.$level.':'.$type->id;
    }

    public static function travelKey(BillingSchedule $schedule): string
    {
        return 'travel:'.$schedule->value;
    }

    public static function hourKey(BillingSchedule $schedule): string
    {
        return 'hour:'.$schedule->value;
    }

    public const string TICKET = 'ticket';

    public static function articleKey(BillingArticle|int $article): string
    {
        return 'article:'.($article instanceof BillingArticle ? $article->id : $article);
    }

    public function get(?BillingClient $client, string $key): float
    {
        $this->amounts ??= BillingRate::query()->get()->mapWithKeys(fn (BillingRate $rate) => [$this->slot($rate->billing_client_id, $rate->rate_key) => (float) $rate->amount])->all();

        return $this->amounts[$this->slot($client?->id, $key)] ?? 0.0;
    }

    public function set(?BillingClient $client, string $key, float $amount, ?User $by): void
    {
        $amount = max(0.0, round($amount, 2));

        DB::transaction(function () use ($client, $key, $amount, $by): void {
            $rate = BillingRate::query()->where('billing_client_id', $client?->id)->where('rate_key', $key)->lockForUpdate()->first();
            $old = $rate === null ? null : (float) $rate->amount;

            if ($old === $amount) {
                return;
            }

            BillingRate::query()->updateOrCreate(['billing_client_id' => $client?->id, 'rate_key' => $key], ['amount' => $amount, 'updated_by' => $by?->id]);
            BillingRateChange::query()->create(['billing_client_id' => $client?->id, 'rate_key' => $key, 'old_amount' => $old, 'new_amount' => $amount, 'changed_by' => $by?->id]);
        });

        $this->amounts = null;
    }

    private function slot(?int $clientId, string $key): string
    {
        return ($clientId ?? 0).'|'.$key;
    }
}
