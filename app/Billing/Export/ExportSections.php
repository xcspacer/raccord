<?php

namespace App\Billing\Export;

use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How entries are spread over the sheets of a billing export: one sheet per client form (named after the client's
 * sheet label), one per part for day-rate clients, then the works price lists, each only when it has entries.
 * A part's or a works sheet's own name wins over the derived one (« <sheet label> <PART> », « <sheet label> WORKS »).
 */
class ExportSections
{
    public const string BREAKDOWN = 'breakdown';

    public const string DAY_RATE = 'day_rate';

    public const string DAY_QUOTE = 'day_quote';

    public const string WORKS = 'works';

    /**
     * @param  Collection<int, BillingEntry>  $entries
     * @return list<array{title: string, kind: string, client: BillingClient, entries: Collection<int, BillingEntry>}>
     */
    public function split(Collection $entries): array
    {
        $byClient = $entries->groupBy('billing_client_id');
        $clients = $entries->map(fn (BillingEntry $entry) => $entry->billingClient)->unique('id')
            ->sortBy(fn (BillingClient $client) => [$client->sort_order, mb_strtolower($client->name)])->values();
        $sheets = [];
        $works = [];

        foreach ($clients as $client) {
            /** @var Collection<int, BillingEntry> $clientEntries */
            $clientEntries = $byClient[$client->id];

            if ($client->form_type === BillingFormType::DayRatePair) {
                $parts = $clientEntries->groupBy(fn (BillingEntry $entry) => (int) $entry->billing_intervention_type_id)
                    ->sortBy(fn (Collection $part) => [$part->first()?->interventionType->sort_order ?? PHP_INT_MAX, $part->first()?->billing_intervention_type_id]);

                foreach ($parts as $part) {
                    $type = $part->first()?->interventionType;
                    $sheets[] = [
                        'title' => $type?->sheet_label ?: trim($client->sheet_label.' '.mb_strtoupper((string) $type?->label)),
                        'kind' => $type?->is_billable === true ? self::DAY_RATE : self::DAY_QUOTE,
                        'client' => $client,
                        'entries' => $part->values(),
                    ];
                }

                continue;
            }

            $breakdowns = $clientEntries->where('type', BillingEntryType::Breakdown)->values();
            $clientWorks = $clientEntries->where('type', BillingEntryType::Works)->values();

            if ($breakdowns->isNotEmpty()) {
                $sheets[] = ['title' => $client->sheet_label, 'kind' => self::BREAKDOWN, 'client' => $client, 'entries' => $breakdowns];
            }

            if ($clientWorks->isNotEmpty()) {
                $works[] = ['title' => $client->works_sheet_label ?: __('billing.export.sheets.works', ['label' => $client->sheet_label]), 'kind' => self::WORKS, 'client' => $client, 'entries' => $clientWorks];
            }
        }

        return [...$sheets, ...$works];
    }

    /**
     * « Septembre 2026 », in the language of the person exporting.
     */
    public static function monthLabel(string $month): string
    {
        return mb_convert_case(CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat('F Y'), MB_CASE_TITLE);
    }

    /**
     * The technician and their partners, « Alice/Bruno ».
     */
    public static function crewNames(BillingEntry $entry, string $separator): string
    {
        return collect([$entry->user->name, ...$entry->partners->pluck('name')->all()])->filter()->implode($separator);
    }
}
