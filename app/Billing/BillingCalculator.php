<?php

namespace App\Billing;

use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Models\BillingArticle;
use App\Models\BillingEntry;
use App\Models\BillingEntryLine;
use Illuminate\Support\Collection;

/**
 * What an entry is worth: its total amount from the client's rates, the number of people who shared the ticket and
 * each person's part. Amounts are euros excluding tax and never rounded here.
 */
class BillingCalculator
{
    /** @var array<string, bool>|null tickets priced flat that are closed, as « client|ticket » */
    private ?array $closedTickets = null;

    /** @var array<int, bool>|null coefficient articles by id */
    private ?array $coefficients = null;

    public function __construct(private BillingRates $rates) {}

    public function amount(BillingEntry $entry): float
    {
        $client = $entry->billingClient;
        $type = $entry->interventionType;

        if ($entry->type === BillingEntryType::Works) {
            if ($client->form_type === BillingFormType::DayRatePair) {
                return $type !== null && $type->is_billable ? $this->rates->get($client, BillingRates::typeKey($type)) : 0.0;
            }

            $quantities = [];

            foreach ($entry->lines as $line) {
                /** @var BillingEntryLine $line */
                $quantities[$line->billing_article_id] = ($quantities[$line->billing_article_id] ?? 0.0) + (float) $line->quantity;
            }

            return (float) array_sum($this->lineAmounts($quantities));
        }

        return match ($client->form_type) {
            BillingFormType::TypeRate => $type !== null && $type->is_billable ? $this->rates->get($client, BillingRates::typeKey($type)) : 0.0,
            BillingFormType::LevelSchedule => $this->gridAmount($entry),
            BillingFormType::Hourly => $this->hourlyAmount($entry),
            BillingFormType::TicketFlat => $this->isClosed($entry) ? $this->rates->get($client, BillingRates::TICKET) : 0.0,
            BillingFormType::Quote, BillingFormType::DayRatePair => 0.0,
        };
    }

    /**
     * What each article of works is worth: quantity × price, and for a coefficient quantity × factor × the total of
     * the other articles.
     *
     * @param  array<int, float>  $quantities  by article id
     * @return array<int, float> by article id
     */
    public function lineAmounts(array $quantities): array
    {
        $this->coefficients ??= BillingArticle::query()->where('is_coefficient', true)->pluck('id')->mapWithKeys(fn (int $id) => [$id => true])->all();
        $price = fn (int $article) => $this->rates->get(null, BillingRates::articleKey($article));
        $amounts = [];

        foreach ($quantities as $article => $quantity) {
            $amounts[$article] = isset($this->coefficients[$article]) ? 0.0 : $quantity * $price($article);
        }

        $base = array_sum($amounts);

        foreach ($quantities as $article => $quantity) {
            if (isset($this->coefficients[$article])) {
                $amounts[$article] = $quantity * $price($article) * $base;
            }
        }

        return $amounts;
    }

    /**
     * People who did the intervention: for a breakdown, everyone named on any breakdown of the same ticket that day
     * (any day for a ticket priced flat); for works, the technician and their partners.
     *
     * @param  Collection<int, BillingEntry>  $context
     */
    public function participants(BillingEntry $entry, Collection $context): int
    {
        if ($entry->type === BillingEntryType::Works) {
            return 1 + $entry->partners->count();
        }

        return $this->people($context->filter(fn (BillingEntry $other) => $other->type === BillingEntryType::Breakdown && $this->ticketKey($other) === $this->ticketKey($entry))->push($entry))->count();
    }

    /**
     * This entry's part: the amount divided by the people. A ticket priced flat is paid once: each person gets
     * their part on their first entry of the ticket only.
     *
     * @param  Collection<int, BillingEntry>  $context
     */
    public function share(BillingEntry $entry, Collection $context): float
    {
        $amount = $this->amount($entry);

        if ($amount === 0.0) {
            return 0.0;
        }

        if ($entry->type === BillingEntryType::Breakdown && $entry->billingClient->form_type === BillingFormType::TicketFlat) {
            $first = $context->concat([$entry])
                ->filter(fn (BillingEntry $other) => $other->user_id === $entry->user_id && $this->ticketKey($other) === $this->ticketKey($entry))
                ->sortBy(fn (BillingEntry $other) => [$other->work_date->toDateString(), $other->id ?? PHP_INT_MAX])
                ->first();

            if ($first !== $entry && $first?->id !== $entry->id) {
                return 0.0;
            }
        }

        return $amount / max(1, $this->participants($entry, $context));
    }

    /**
     * The ticket an entry belongs to for sharing: client, day and ticket (client and ticket for flat tickets).
     */
    public function ticketKey(BillingEntry $entry): string
    {
        $ticket = mb_strtolower(trim($entry->ticket));

        return $entry->billingClient->form_type === BillingFormType::TicketFlat
            ? $entry->billing_client_id.'|'.$ticket
            : $entry->billing_client_id.'|'.$entry->work_date->toDateString().'|'.$ticket;
    }

    /**
     * @return array{business: int, off: int}
     */
    public function minutes(BillingEntry $entry): array
    {
        return WorkingHours::split($entry->started_at, $entry->ended_at);
    }

    /**
     * @param  Collection<int, BillingEntry>  $entries
     * @return Collection<int, int>
     */
    private function people(Collection $entries): Collection
    {
        return $entries->flatMap(fn (BillingEntry $entry) => [$entry->user_id, ...$entry->partners->modelKeys()])->unique()->values();
    }

    private function gridAmount(BillingEntry $entry): float
    {
        $type = $entry->interventionType;

        if ($type === null || ! $type->is_billable) {
            return 0.0;
        }

        $client = $entry->billingClient;
        $amount = $this->rates->get($client, BillingRates::gridKey($entry->schedule ?? BillingSchedule::BusinessHours, $entry->level ?? 1, $type));

        if ($client->has_travel_fee && $entry->schedule !== null) {
            $amount += $this->rates->get($client, BillingRates::travelKey($entry->schedule));
        }

        return $amount;
    }

    private function hourlyAmount(BillingEntry $entry): float
    {
        $minutes = $this->minutes($entry);
        $client = $entry->billingClient;

        return $minutes['business'] / 60 * $this->rates->get($client, BillingRates::hourKey(BillingSchedule::BusinessHours))
            + $minutes['off'] / 60 * $this->rates->get($client, BillingRates::hourKey(BillingSchedule::OffHours));
    }

    /**
     * A flat ticket is closed when any of its breakdowns, whatever the day, says so.
     */
    private function isClosed(BillingEntry $entry): bool
    {
        $this->closedTickets ??= BillingEntry::query()
            ->where('type', BillingEntryType::Breakdown->value)
            ->where('closure_status', BillingClosureStatus::Closed->value)
            ->get(['billing_client_id', 'ticket'])
            ->mapWithKeys(fn (BillingEntry $closed) => [$closed->billing_client_id.'|'.mb_strtolower(trim($closed->ticket)) => true])
            ->all();

        return $entry->closure_status === BillingClosureStatus::Closed
            || isset($this->closedTickets[$entry->billing_client_id.'|'.mb_strtolower(trim($entry->ticket))]);
    }
}
