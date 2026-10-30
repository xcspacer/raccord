<?php

namespace App\Absences;

use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\PublicHolidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * « Exporter Excel » of the absences page: one line per person still in the planning with an absence in the month,
 * the periods grouped by type (shown whole, even when they cross the month) and the month's totals: calendar days
 * per type, total and working days.
 */
class AbsenceExport
{
    private const string HEADER_FILL = 'FFF2A104';

    private const string HEADER_INK = 'FF2A1B00';

    private const string TITLE_INK = 'FF12303F';

    private const string BAND_FILL = 'FFF3F6F8';

    private const string TOTAL_FILL = 'FFFCE9B8';

    private const string NOTE_INK = 'FF667780';

    private const int HEADER_ROW = 6;

    /** Order of the types in the periods cell and of their columns. */
    private const array TYPES = [AbsenceType::Absent, AbsenceType::Leave, AbsenceType::Sick];

    public function __construct(private AbsenceCalendar $calendar, private PublicHolidays $holidays, private CompanySettings $company) {}

    /**
     * @return list<array{name: string, lines: list<string>, absent: int, leave: int, sick: int, total: int, working: int}>
     */
    public function rows(CarbonImmutable $month): array
    {
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth()->startOfDay();

        $people = User::query()
            ->where(fn ($query) => $query->where('is_plannable', true)->orWhereHas('roles', fn ($query) => $query->where('name', Role::Technician->value)))
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>', $monthStart))
            ->get(['id', 'name']);

        $periods = $this->calendar->periodsForUsers($people->pluck('id')->all(), $monthStart, $monthEnd);
        $rows = [];

        foreach ($people as $person) {
            $ranges = collect($periods[$person->id] ?? [])->sortBy(fn (array $period) => $period['start']->toDateString())->values();

            if ($ranges->isNotEmpty()) {
                $rows[] = $this->row($person->name, $ranges, $monthStart, $monthEnd);
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp(Str::lower(Str::ascii($a['name'])), Str::lower(Str::ascii($b['name']))));

        return $rows;
    }

    /**
     * @param  list<array{name: string, lines: list<string>, absent: int, leave: int, sick: int, total: int, working: int}>  $rows
     */
    public function build(CarbonImmutable $month, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator($this->company->displayName());
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(__('absences.export.sheet'), 0, 31));
        $sheet->setShowGridlines(false);

        foreach (['A' => 15, 'B' => 2, 'C' => 26, 'D' => 52, 'E' => 10, 'F' => 10, 'G' => 14, 'H' => 12, 'I' => 14] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $this->header($sheet, $month);

        foreach ($rows as $index => $row) {
            $this->personRow($sheet, self::HEADER_ROW + 1 + $index, $row, $index % 2 === 1);
        }

        $this->totals($sheet, $rows);

        return $spreadsheet;
    }

    public function fileName(CarbonImmutable $month): string
    {
        return __('absences.export.file', ['month' => $month->format('Y-m')]).'.xlsx';
    }

    /**
     * @param  Collection<int, array{type: AbsenceType, start: CarbonImmutable, end: CarbonImmutable, days: int}>  $ranges
     * @return array{name: string, lines: list<string>, absent: int, leave: int, sick: int, total: int, working: int}
     */
    private function row(string $name, Collection $ranges, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $byType = array_fill_keys(array_map(fn (AbsenceType $type) => $type->value, self::TYPES), []);
        $days = array_fill_keys(array_keys($byType), 0);
        $working = 0;

        foreach ($ranges as $range) {
            $from = $range['start']->max($monthStart);
            $to = $range['end']->min($monthEnd);

            $byType[$range['type']->value][] = $this->period($range['start'], $range['end']);
            $days[$range['type']->value] += (int) $from->diffInDays($to) + 1;

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $working += $this->holidays->isWorkingDay($day) ? 1 : 0;
            }
        }

        $lines = [];

        foreach (self::TYPES as $type) {
            if ($byType[$type->value] !== []) {
                $lines[] = __('absences.export.periods_line', ['type' => $type->label(), 'periods' => implode(' · ', $byType[$type->value])]);
            }
        }

        return [
            'name' => $name,
            'lines' => $lines,
            'absent' => $days[AbsenceType::Absent->value],
            'leave' => $days[AbsenceType::Leave->value],
            'sick' => $days[AbsenceType::Sick->value],
            'total' => array_sum($days),
            'working' => $working,
        ];
    }

    private function period(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->eq($end)) {
            return $start->translatedFormat('d M');
        }

        $from = $start->isSameMonth($end) ? $start->format('d') : $start->translatedFormat('d M');

        return $from.' → '.$end->translatedFormat('d M');
    }

    private function header(Worksheet $sheet, CarbonImmutable $month): void
    {
        $logo = $this->company->logo_path;

        if ($logo !== null && Storage::disk('local')->exists($logo)) {
            $drawing = new Drawing;
            $drawing->setPath(Storage::disk('local')->path($logo));
            $drawing->setHeight(27);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(15);
            $drawing->setOffsetY(6);
            $drawing->setWorksheet($sheet);
        }

        $sheet->mergeCells('C2:I3');
        $this->text($sheet, 'C2', __('absences.export.title', ['month' => mb_convert_case($month->translatedFormat('F Y'), MB_CASE_TITLE)]));
        $sheet->getStyle('C2')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => ['argb' => self::TITLE_INK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(30);

        foreach (['technician', 'periods', 'absent', 'leave', 'sick', 'total', 'working'] as $index => $key) {
            $cell = [$index + 3, self::HEADER_ROW];
            $this->text($sheet, $cell, __('absences.export.columns.'.$key));
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => self::HEADER_INK]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => $index >= 2 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
            ]);
        }
    }

    /**
     * @param  array{name: string, lines: list<string>, absent: int, leave: int, sick: int, total: int, working: int}  $row
     */
    private function personRow(Worksheet $sheet, int $line, array $row, bool $banded): void
    {
        $wrapped = array_sum(array_map(fn (string $text) => max(1, (int) ceil(mb_strlen($text) / 50)), $row['lines']));
        $sheet->getRowDimension($line)->setRowHeight(max(20, $wrapped * 15 + 5));

        $this->text($sheet, [3, $line], $row['name']);
        $sheet->getStyle([3, $line])->getFont()->setBold(true);
        $this->text($sheet, [4, $line], implode("\n", $row['lines']));

        foreach ([5 => $row['absent'], 6 => $row['leave'], 7 => $row['sick']] as $column => $days) {
            if ($days > 0) {
                $sheet->setCellValueExplicit([$column, $line], $days, DataType::TYPE_NUMERIC);
            }
        }

        $sheet->setCellValueExplicit([8, $line], $row['total'], DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit([9, $line], $row['working'], DataType::TYPE_NUMERIC);
        $sheet->getStyle([8, $line])->getFont()->setBold(true);

        for ($column = 3; $column <= 9; $column++) {
            $style = $sheet->getStyle([$column, $line]);
            $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)
                ->setHorizontal($column >= 5 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT)
                ->setWrapText($column === 4);

            if ($banded) {
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BAND_FILL);
            }
        }
    }

    /**
     * @param  list<array{name: string, lines: list<string>, absent: int, leave: int, sick: int, total: int, working: int}>  $rows
     */
    private function totals(Worksheet $sheet, array $rows): void
    {
        $line = self::HEADER_ROW + 1 + count($rows);
        $sheet->getRowDimension($line)->setRowHeight(22);

        $this->text($sheet, [3, $line], __('absences.export.total'));
        $this->text($sheet, [4, $line], trans_choice('absences.export.people', count($rows), ['count' => count($rows)]));

        foreach (['absent' => 5, 'leave' => 6, 'sick' => 7, 'total' => 8, 'working' => 9] as $key => $column) {
            $sheet->setCellValueExplicit([$column, $line], array_sum(array_column($rows, $key)), DataType::TYPE_NUMERIC);
        }

        for ($column = 3; $column <= 9; $column++) {
            $sheet->getStyle([$column, $line])->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => self::TITLE_INK]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TOTAL_FILL]],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::HEADER_FILL]]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => $column >= 5 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT],
            ]);
        }

        $note = $line + 2;
        $sheet->mergeCells([3, $note, 9, $note]);
        $this->text($sheet, [3, $note], __('absences.export.note'));
        $sheet->getStyle([3, $note])->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => self::NOTE_INK]],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
        ]);
        $sheet->getRowDimension($note)->setRowHeight(26);
    }

    /**
     * Text is always written as an explicit string so a name or a label starting with « = » is never run as a formula.
     *
     * @param  string|array{0: int, 1: int}  $cell
     */
    private function text(Worksheet $sheet, string|array $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }
}
