<?php

namespace App\Billing;

use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Models\BillingEntry;
use App\Models\BillingEntryLine;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Inconsistencies between the entries of colleagues: a different crew or a different description on the same ticket,
 * the same person counting a ticket twice, and partners named on a ticket who have not declared it yet.
 */
class BillingChecks
{
    /**
     * Another entry of the same ticket on the same day (any type) naming a different crew.
     *
     * @param  Collection<int, BillingEntry>  $context
     */
    public function partnerMismatch(BillingEntry $entry, Collection $context, bool $othersOnly = false): ?BillingEntry
    {
        $crew = $this->crew($entry);

        return $context->first(fn (BillingEntry $other) => $this->isOther($entry, $other, $othersOnly)
            && $this->sameTicketDay($entry, $other)
            && $this->crew($other) !== $crew);
    }

    /**
     * Another entry of the same type, ticket and day describing a different intervention.
     *
     * @param  Collection<int, BillingEntry>  $context
     */
    public function interventionMismatch(BillingEntry $entry, Collection $context, bool $othersOnly = false): ?BillingEntry
    {
        $signature = $this->signature($entry);

        return $context->first(fn (BillingEntry $other) => $this->isOther($entry, $other, $othersOnly)
            && $other->type === $entry->type
            && $this->sameTicketDay($entry, $other)
            && $this->signature($other) !== $signature);
    }

    /**
     * Entries counted twice: the same person declaring the same client ticket more than once in the list.
     *
     * @param  Collection<int, BillingEntry>  $entries
     * @return array<int, int> number of occurrences by entry id, only for duplicates
     */
    public function duplicates(Collection $entries): array
    {
        $groups = $entries->groupBy(fn (BillingEntry $entry) => $entry->user_id.'|'.$entry->billing_client_id.'|'.mb_strtolower(trim($entry->ticket)));
        $duplicates = [];

        foreach ($groups as $group) {
            if ($group->count() > 1) {
                foreach ($group as $entry) {
                    $duplicates[$entry->id] = $group->count();
                }
            }
        }

        return $duplicates;
    }

    /**
     * Partners named on an entry who have no entry of their own for that ticket and day, newest first.
     *
     * @param  Collection<int, BillingEntry>  $entries  entries that may name partners
     * @param  Collection<int, BillingEntry>  $all  every entry the partners' own entries are looked up in
     * @return list<array{partner: User, entry: BillingEntry}>
     */
    public function missingPartners(Collection $entries, Collection $all): array
    {
        $declared = $all->mapWithKeys(fn (BillingEntry $entry) => [$entry->user_id.'#'.$this->dayKey($entry) => true]);
        $reminders = [];

        foreach ($entries->sortByDesc(fn (BillingEntry $entry) => $entry->work_date->toDateString()) as $entry) {
            foreach ($entry->partners as $partner) {
                $key = $partner->id.'#'.$this->dayKey($entry);

                if (! isset($declared[$key]) && ! isset($reminders[$key])) {
                    $reminders[$key] = ['partner' => $partner, 'entry' => $entry];
                }
            }
        }

        return array_values($reminders);
    }

    /**
     * What describes the intervention itself, never who did it or the comment.
     */
    public function signature(BillingEntry $entry): string
    {
        $time = fn () => [$entry->started_at?->format('Y-m-d H:i'), $entry->ended_at?->format('Y-m-d H:i')];

        $parts = match (true) {
            $entry->type === BillingEntryType::Works && $entry->billingClient->form_type === BillingFormType::DayRatePair => [$entry->billing_intervention_type_id, ...$time()],
            $entry->type === BillingEntryType::Works => $entry->lines->sortBy('billing_article_id')->map(fn (BillingEntryLine $line) => [$line->billing_article_id, (float) $line->quantity])->values()->all(),
            default => match ($entry->billingClient->form_type) {
                BillingFormType::TypeRate => [$entry->billing_intervention_type_id],
                BillingFormType::LevelSchedule => [$entry->billing_intervention_type_id, $entry->level, $entry->schedule?->value],
                BillingFormType::Hourly => [...$time(), $entry->billing_operator_id],
                BillingFormType::TicketFlat => [...$time(), $entry->closure_status?->value],
                BillingFormType::Quote, BillingFormType::DayRatePair => $time(),
            },
        };

        return (string) json_encode($parts);
    }

    /**
     * @return list<int>
     */
    public function crewIds(BillingEntry $entry): array
    {
        $ids = array_values(array_unique([$entry->user_id, ...$entry->partners->modelKeys()]));
        sort($ids);

        return $ids;
    }

    private function crew(BillingEntry $entry): string
    {
        return implode(',', $this->crewIds($entry));
    }

    private function isOther(BillingEntry $entry, BillingEntry $other, bool $othersOnly): bool
    {
        if ($other === $entry || ($entry->exists && $other->id === $entry->id)) {
            return false;
        }

        return ! $othersOnly || $other->user_id !== $entry->user_id;
    }

    private function sameTicketDay(BillingEntry $entry, BillingEntry $other): bool
    {
        return $this->dayKey($entry) === $this->dayKey($other);
    }

    private function dayKey(BillingEntry $entry): string
    {
        return $entry->billing_client_id.'|'.$entry->work_date->toDateString().'|'.mb_strtolower(trim($entry->ticket));
    }
}
