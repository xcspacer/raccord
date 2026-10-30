<?php

namespace App\Billing\Export;

use App\Billing\BillingCalculator;
use App\Billing\BillingPeriod;
use App\Enums\BillingFormType;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipStream\ZipStream;

/**
 * « Export par technicien »: one workbook per person who declared entries in the month (their own entries only, not
 * those naming them as partner), in the reference format with a footer per sheet; all of them in one ZIP.
 */
class TechnicianWorkbooks
{
    public function __construct(private BillingPeriod $period, private BillingCalculator $calculator, private ExportSections $sections) {}

    /**
     * Adds the workbooks to the ZIP.
     */
    public function write(ZipStream $zip, string $month): void
    {
        $entries = $this->period->entries($month);
        $context = $this->period->context($entries);
        $label = ExportSections::monthLabel($month);

        foreach ($entries->groupBy('user_id')->sortBy(fn (Collection $group) => mb_strtolower((string) $group->first()?->user->name)) as $group) {
            /** @var User $technician */
            $technician = $group->first()?->user;
            $stream = SheetWriter::stream($this->build($technician, $group, $context));
            $zip->addFileFromStream(SheetWriter::fileName(__('billing.export.files.technician', ['month' => $label, 'name' => $technician->name])).'.xlsx', $stream);
            fclose($stream);
        }
    }

    public function fileName(string $month): string
    {
        return SheetWriter::fileName(__('billing.export.files.technicians', ['month' => ExportSections::monthLabel($month)])).'.zip';
    }

    /**
     * @param  Collection<int, BillingEntry>  $entries  the technician's own entries of the month
     * @param  Collection<int, BillingEntry>  $context
     */
    public function build(User $technician, Collection $entries, Collection $context): Spreadsheet
    {
        $columns = new EntryColumns($this->calculator, $context, true);
        $book = new Spreadsheet;

        foreach ($this->sections->split($entries) as $section) {
            $sheet = SheetWriter::sheet($book, $section['title']);
            $sorted = $section['entries']->sortBy(fn (BillingEntry $entry) => [$entry->work_date->getTimestamp(), $entry->id])->values();

            if ($section['kind'] === ExportSections::WORKS) {
                $this->works($sheet, $sorted);

                continue;
            }

            $this->table($sheet, $technician, $section, $sorted, $columns);
        }

        if ($book->getSheetCount() === 1 && $book->getSheet(0)->getTitle() === 'Worksheet') {
            $sheet = SheetWriter::sheet($book, __('billing.export.sheets.empty'));
            SheetWriter::rows($sheet, [[__('billing.export.empty_month')]]);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @param  array{title: string, kind: string, client: BillingClient, entries: Collection<int, BillingEntry>}  $section
     * @param  Collection<int, BillingEntry>  $entries
     */
    private function table(Worksheet $sheet, User $technician, array $section, Collection $entries, EntryColumns $columns): void
    {
        $form = $section['client']->form_type;
        $kind = $section['kind'];
        $quote = $kind === ExportSections::DAY_QUOTE || ($kind === ExportSections::BREAKDOWN && $form === BillingFormType::Quote);

        [$details, $footer] = match (true) {
            $quote => [[...$columns->times(), $columns->duration()], 'total_hours'],
            $kind === ExportSections::DAY_RATE => [$columns->amounts(), 'total'],
            $form === BillingFormType::TypeRate => [$columns->types($section['client']), 'breakdowns'],
            $form === BillingFormType::LevelSchedule => [[$columns->level(), ...$columns->types($section['client']), $columns->schedule()], 'breakdowns'],
            $form === BillingFormType::Hourly => [[$columns->operator(), ...$columns->times(), ...$columns->hours(), ...$columns->amounts()], 'total_hours'],
            $form === BillingFormType::TicketFlat => [[...$columns->times(), $columns->duration(), $columns->status(), ...$columns->amounts(true)], 'total'],
            default => [[], 'total'],
        };

        $sheetColumns = [
            new SheetColumn(__('billing.export.columns.date'), 12, fn (BillingEntry $entry) => $entry->work_date->format('d/m/Y')),
            new SheetColumn(__('billing.export.columns.crew'), 22, fn (BillingEntry $entry) => ExportSections::crewNames($entry, '/')),
            new SheetColumn(__($quote ? 'billing.export.columns.quote_ticket' : 'billing.export.columns.ticket'), $quote ? 20 : 16, fn (BillingEntry $entry) => $entry->ticket),
            ...$details,
            new SheetColumn(__('billing.export.columns.comments_upper'), 70, fn (BillingEntry $entry) => $entry->comment),
        ];

        $rows = $entries->map(fn (BillingEntry $entry) => array_map(fn (SheetColumn $column) => ($column->value)($entry), $sheetColumns))->all();
        $footerRow = [__('billing.export.footers.'.$footer), $technician->name];

        foreach (array_slice($sheetColumns, 2, null, true) as $index => $column) {
            $sum = $column->summed ? round((float) array_sum(array_map(fn (array $row) => is_numeric($row[$index]) ? (float) $row[$index] : 0.0, $rows)), 2) : 0.0;
            $footerRow[$index] = $sum === 0.0 ? '' : $sum;
        }

        SheetWriter::rows($sheet, [
            array_map(fn (SheetColumn $column) => $column->header, $sheetColumns),
            ...$rows,
            [],
            [],
            $footerRow,
            [__($kind === ExportSections::DAY_RATE ? 'billing.export.footers.day_count' : 'billing.export.footers.ticket_count'), $entries->count()],
        ]);
        SheetWriter::widths($sheet, array_map(fn (SheetColumn $column) => $column->width, $sheetColumns));

        foreach ($entries as $index => $entry) {
            if ($kind === ExportSections::BREAKDOWN && $entry->interventionType !== null && ! $entry->interventionType->is_billable) {
                SheetWriter::fill($sheet, $index + 2, count($sheetColumns));
            }
        }
    }

    /**
     * Works without articles nor amounts: one column per entry.
     *
     * @param  Collection<int, BillingEntry>  $entries
     */
    private function works(Worksheet $sheet, Collection $entries): void
    {
        SheetWriter::rows($sheet, [
            [__('billing.export.works.ticket_number'), ...$entries->map(fn (BillingEntry $entry) => $entry->ticket)->all()],
            [__('billing.export.columns.date'), ...$entries->map(fn (BillingEntry $entry) => $entry->work_date->format('d/m/Y'))->all()],
            [__('billing.export.columns.crew'), ...$entries->map(fn (BillingEntry $entry) => ExportSections::crewNames($entry, '/'))->all()],
            [__('billing.export.works.comments'), ...$entries->map(fn (BillingEntry $entry) => $entry->comment)->all()],
        ]);
        SheetWriter::widths($sheet, [16, ...array_fill(0, $entries->count(), 40)]);
    }
}
