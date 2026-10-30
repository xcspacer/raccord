<?php

namespace App\Billing\Export;

use App\Billing\BillingCalculator;
use App\Billing\BillingChecks;
use App\Billing\BillingPeriod;
use App\Billing\BillingRates;
use App\Enums\BillingFormType;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingEntryLine;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * « Export global »: every entry of a month, all technicians and clients, one sheet per client form with the
 * checks (duplicates, crews) and the amounts, then the works price lists.
 */
class GlobalWorkbook
{
    public function __construct(
        private BillingPeriod $period,
        private BillingCalculator $calculator,
        private BillingChecks $checks,
        private BillingRates $rates,
        private ExportSections $sections,
    ) {}

    public function build(string $month): Spreadsheet
    {
        $entries = $this->period->entries($month);
        $context = $this->period->context($entries);
        $columns = new EntryColumns($this->calculator, $context, false);
        $book = new Spreadsheet;

        foreach ($this->sections->split($entries) as $section) {
            $sheet = SheetWriter::sheet($book, $section['title']);

            if ($section['kind'] === ExportSections::WORKS) {
                $this->priceList($sheet, $section['entries']);

                continue;
            }

            $sorted = $section['entries']->sortBy(fn (BillingEntry $entry) => [-$entry->work_date->getTimestamp(), $entry->id])->values();
            $sheetColumns = $this->columns($section, $sorted, $context, $columns);

            SheetWriter::rows($sheet, [
                array_map(fn (SheetColumn $column) => $column->header, $sheetColumns),
                ...$sorted->map(fn (BillingEntry $entry) => array_map(fn (SheetColumn $column) => ($column->value)($entry), $sheetColumns))->all(),
            ]);
            SheetWriter::widths($sheet, array_map(fn (SheetColumn $column) => $column->width, $sheetColumns));

            foreach ($sorted as $index => $entry) {
                if ($entry->interventionType !== null && ! $entry->interventionType->is_billable && $section['kind'] === ExportSections::BREAKDOWN) {
                    SheetWriter::fill($sheet, $index + 2, count($sheetColumns));
                }
            }
        }

        if ($book->getSheetCount() === 1 && $book->getSheet(0)->getTitle() === 'Worksheet') {
            $sheet = SheetWriter::sheet($book, __('billing.export.sheets.empty'));
            SheetWriter::rows($sheet, [[__('billing.export.empty_period')]]);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function fileName(string $month): string
    {
        return SheetWriter::fileName(__('billing.export.files.global', ['month' => $month])).'.xlsx';
    }

    /**
     * @param  array{title: string, kind: string, client: BillingClient, entries: Collection<int, BillingEntry>}  $section
     * @param  Collection<int, BillingEntry>  $entries
     * @param  Collection<int, BillingEntry>  $context
     * @return list<SheetColumn>
     */
    private function columns(array $section, Collection $entries, Collection $context, EntryColumns $columns): array
    {
        $form = $section['client']->form_type;
        $quote = $section['kind'] === ExportSections::DAY_QUOTE || ($section['kind'] === ExportSections::BREAKDOWN && $form === BillingFormType::Quote);
        $flat = $section['kind'] === ExportSections::BREAKDOWN && $form === BillingFormType::TicketFlat;
        $duplicates = $this->checks->duplicates($entries);

        $details = match (true) {
            $quote => [...$columns->times(), $columns->duration()],
            $section['kind'] === ExportSections::DAY_RATE => [],
            $form === BillingFormType::TypeRate => $columns->types($section['client']),
            $form === BillingFormType::LevelSchedule => [$columns->level(), ...$columns->types($section['client']), $columns->schedule()],
            $form === BillingFormType::Hourly => [$columns->operator(), ...$columns->times(), ...$columns->hours()],
            $flat => [...$columns->times(), $columns->duration(), $columns->status()],
            default => [],
        };

        return array_values(array_filter([
            new SheetColumn(__('billing.export.columns.date'), 12, fn (BillingEntry $entry) => $entry->work_date->toDateString()),
            new SheetColumn(__('billing.export.columns.technician'), 14, fn (BillingEntry $entry) => $entry->user->name),
            new SheetColumn(__('billing.export.columns.partners'), 20, fn (BillingEntry $entry) => $entry->partners->pluck('name')->implode(', ')),
            new SheetColumn(__('billing.export.columns.people'), 8, fn (BillingEntry $entry) => $this->calculator->participants($entry, $context)),
            new SheetColumn(__($quote ? 'billing.export.columns.quote_ticket' : 'billing.export.columns.ticket'), $quote ? 20 : 16, fn (BillingEntry $entry) => $entry->ticket),
            ...$details,
            $flat ? null : new SheetColumn(__('billing.export.columns.duplicate'), 18, fn (BillingEntry $entry) => isset($duplicates[$entry->id])
                ? __('billing.export.values.occurrences', ['count' => $duplicates[$entry->id]])
                : __('billing.export.values.no')),
            new SheetColumn(__('billing.export.columns.crew_mismatch'), 18, fn (BillingEntry $entry) => $this->checks->partnerMismatch($entry, $context) !== null ? __('billing.export.values.yes') : __('billing.export.values.no')),
            ...($quote ? [] : $columns->amounts($flat)),
            new SheetColumn(__('billing.export.columns.comments'), 60, fn (BillingEntry $entry) => $entry->comment),
        ]));
    }

    /**
     * The works price list: one row per article, two columns (quantity, total) per ticket, tickets by their first
     * day; quantities of the same ticket are added up and coefficients apply to the ticket's other articles.
     *
     * @param  Collection<int, BillingEntry>  $entries
     */
    private function priceList(Worksheet $sheet, Collection $entries): void
    {
        $tickets = $entries->groupBy(fn (BillingEntry $entry) => mb_strtolower(trim($entry->ticket)))
            ->map(fn (Collection $group) => [
                'ticket' => $group->first()?->ticket,
                'date' => $group->min(fn (BillingEntry $entry) => $entry->work_date->toDateString()),
                'quantities' => $group->flatMap(fn (BillingEntry $entry) => $entry->lines)
                    ->groupBy('billing_article_id')
                    ->map(fn (Collection $lines) => (float) $lines->sum(fn (BillingEntryLine $line) => (float) $line->quantity)),
            ])
            ->map(fn (array $ticket) => $ticket + ['amounts' => $this->calculator->lineAmounts($ticket['quantities']->all())])
            ->sortBy('date')->values();

        $used = $tickets->flatMap(fn (array $ticket) => $ticket['quantities']->keys())->unique()->all();
        $articles = BillingArticle::query()->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $used))->orderBy('sort_order')->orderBy('code')->get();
        $prices = $articles->mapWithKeys(fn (BillingArticle $article) => [$article->id => $this->rates->get(null, BillingRates::articleKey($article))]);

        $rows = [
            ['', '', '', __('billing.export.works.number')],
            ['', '', '', ''],
            [__('billing.export.works.article'), __('billing.export.works.label'), __('billing.export.works.unit'), __('billing.export.works.unit_price')],
        ];

        foreach ($tickets as $ticket) {
            array_push($rows[0], $ticket['ticket'], '');
            array_push($rows[1], $ticket['date'], '');
            array_push($rows[2], __('billing.export.works.quantity'), __('billing.export.works.line_total'));
        }

        foreach ($articles as $article) {
            $row = [$article->code, $article->label, $article->unit, $prices[$article->id]];

            foreach ($tickets as $ticket) {
                $quantity = (float) ($ticket['quantities'][$article->id] ?? 0);
                array_push($row, ...($quantity > 0 ? [$quantity, round($ticket['amounts'][$article->id] ?? 0.0, 2)] : ['', '']));
            }

            $rows[] = $row;
        }

        $totals = ['', __('billing.export.works.total'), '', ''];
        $grandTotal = 0.0;

        foreach ($tickets as $ticket) {
            $total = round((float) array_sum($ticket['amounts']), 2);
            $grandTotal += $total;
            array_push($totals, '', $total);
        }

        $rows[] = $totals;
        $rows[] = ['', __('billing.export.works.grand_total'), '', '', round($grandTotal, 2)];

        SheetWriter::rows($sheet, $rows);
        SheetWriter::widths($sheet, [12, 65, 7, 11, ...array_fill(0, $tickets->count() * 2, 11)]);

        foreach ($tickets->keys() as $index) {
            $column = 5 + $index * 2;
            $sheet->mergeCells([$column, 1, $column + 1, 1]);
            $sheet->mergeCells([$column, 2, $column + 1, 2]);
        }
    }
}
