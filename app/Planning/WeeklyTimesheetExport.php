<?php

namespace App\Planning;

use App\Models\Absence;
use App\Models\Agency;
use App\Models\DayPunch;
use App\Models\Task;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\SheetText;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Weekly time sheet of an agency's technicians as an Excel file: for each day (Monday to Saturday) and person,
 * the work planned or the absence, and the start and end of the day punched with a link to the position (⚠ when
 * the position is imprecise, D9). The positions are for internal use, which the sheet says under the table.
 * People removed from the week only appear when they have something that week; Saturday only when a task is
 * planned that day.
 */
class WeeklyTimesheetExport
{
    private const string HEADER_FILL = 'FFF2A104';

    private const string DAY_FILL = 'FFFCE9B8';

    private const string BAND_FILL = 'FFF3F6F8';

    private const string INK = 'FF12303F';

    private const string GREY = 'FF667780';

    private const string RED = 'FFB3382C';

    private const string LINK = 'FF1F6FB2';

    /** Thin frame of every cell of the table. */
    private const array FRAME = ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC9D3D9']]];

    public function __construct(private WeekRoster $roster, private TaskDetail $detail, private CompanySettings $company) {}

    public function build(User $generatedBy, Agency $agency, CarbonImmutable $monday): Spreadsheet
    {
        $timezone = (string) config('platform.timezone');
        $roster = $this->roster->for($agency, $monday);
        $people = $roster['visible']->concat($roster['removed'])->sortBy(fn (User $person) => [$person->planning_order === 0 ? 1 : 0, $person->planning_order, $person->name])->values();
        $data = $this->load($people, $monday);

        $people = $people->filter(fn (User $person) => $roster['visible']->contains('id', $person->id) || $this->hasEntries($person, $data))->values();
        $days = $this->days($monday, $data['tasks']);
        $week = $monday->isoWeek;

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(__('weekly_timesheet.sheet', ['week' => $week]), 0, 31));
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getSheetView()->setZoomScale(100);

        foreach (['A' => 11, 'B' => 17, 'C' => 24, 'D' => 70, 'E' => 21, 'F' => 21] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $this->header($sheet, $agency, $monday, $week);

        $row = 7;
        $firstDataRow = $row;

        foreach ($days as $dayIndex => $day) {
            $dayStart = $row;

            foreach ($people as $person) {
                $this->personRow($sheet, $row, $person, $day, $data, $timezone, $dayIndex % 2 === 1);
                $row++;
            }

            if ($row - 1 > $dayStart) {
                $sheet->mergeCells("B{$dayStart}:B".($row - 1));
            }

            $sheet->setCellValue("B{$dayStart}", mb_convert_case($day->translatedFormat('l'), MB_CASE_TITLE)."\n".$day->format('d/m/Y'));
            $sheet->getStyle("B{$dayStart}:B".max($dayStart, $row - 1))->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::DAY_FILL]],
                'font' => ['bold' => true, 'color' => ['argb' => self::INK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => self::FRAME,
            ]);
        }

        $last = max($firstDataRow, $row - 1);

        if ($last > $firstDataRow) {
            $sheet->mergeCells("A{$firstDataRow}:A{$last}");
        }

        $sheet->setCellValue("A{$firstDataRow}", 'S'.$week);
        $sheet->getStyle("A{$firstDataRow}:A{$last}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => self::INK]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::DAY_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => self::FRAME,
        ]);

        foreach (['generated' => $row + 1, 'location' => $row + 2] as $line => $noteRow) {
            $sheet->mergeCells("A{$noteRow}:F{$noteRow}");
            $sheet->setCellValue("A{$noteRow}", $line === 'generated'
                ? __('weekly_timesheet.generated', ['date' => CarbonImmutable::now($timezone)->format('d/m/Y'), 'user' => $generatedBy->name, 'app' => $this->company->displayName()])
                : __('weekly_timesheet.location_notice', ['metres' => (int) config('platform.location_accuracy_warning_metres')]));
            $sheet->getStyle("A{$noteRow}")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB(self::GREY);
        }

        return $spreadsheet;
    }

    private function header(Worksheet $sheet, Agency $agency, CarbonImmutable $monday, int $week): void
    {
        $logo = $this->company->logo_path;

        if ($logo !== null && Storage::disk('local')->exists($logo)) {
            $drawing = new Drawing;
            $drawing->setPath(Storage::disk('local')->path($logo));
            $drawing->setHeight(56);
            $drawing->setCoordinates('A1');
            $drawing->setWorksheet($sheet);
        }

        $sheet->mergeCells('C2:F3');
        $sheet->setCellValue('C2', __('weekly_timesheet.title', ['team' => mb_strtoupper($agency->name)]));
        $sheet->getStyle('C2')->applyFromArray(['font' => ['name' => 'Arial', 'bold' => true, 'size' => 16, 'color' => ['argb' => self::INK]], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);

        $sheet->mergeCells('C4:F4');
        $sheet->setCellValue('C4', __('weekly_timesheet.week', ['week' => $week, 'from' => $monday->format('d/m/Y'), 'to' => $monday->addDays(5)->format('d/m/Y')]));
        $sheet->getStyle('C4')->getFont()->setName('Arial')->setSize(11)->getColor()->setARGB(self::GREY);

        foreach (['week', 'date', 'technician', 'work', 'start', 'end'] as $index => $key) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).'6';
            $sheet->setCellValue($cell, __('weekly_timesheet.columns.'.$key));
        }

        $sheet->getStyle('A6:F6')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF2A1B00']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => self::FRAME,
        ]);
        $sheet->getRowDimension(6)->setRowHeight(26);
    }

    /**
     * @param  array{tasks: array<int, array<string, Task>>, absences: array<int, array<string, Absence>>, punches: array<int, array<string, DayPunch>>}  $data
     */
    private function personRow(Worksheet $sheet, int $row, User $person, CarbonImmutable $day, array $data, string $timezone, bool $banded): void
    {
        $key = $day->toDateString();
        $departed = $person->hasDeparted($day);
        $task = $departed ? null : $data['tasks'][$person->id][$key] ?? null;
        $absence = $departed ? null : $data['absences'][$person->id][$key] ?? null;
        $punch = $data['punches'][$person->id][$key] ?? null;

        SheetText::cell($sheet, "C{$row}", $person->name);
        $sheet->getStyle("C{$row}")->getFont()->setBold(true);

        if ($departed) {
            $work = __('weekly_timesheet.departed');
        } elseif ($absence !== null) {
            $work = $absence->type->label().($absence->note ? ' — '.$absence->note : '');
        } elseif ($task !== null) {
            $work = collect([$task->label, $task->displayDetail($this->detail)])->filter()->unique()->implode(' — ');
            $work .= $task->note ? ($work !== '' ? "\n" : '').__('weekly_timesheet.note', ['note' => $task->note]) : '';
        } else {
            $work = '';
        }

        SheetText::cell($sheet, "D{$row}", $work === '' ? '—' : $work);

        if ($work === '') {
            $sheet->getStyle("D{$row}")->getFont()->setItalic(true)->getColor()->setARGB('FF99A5AC');
        } elseif ($absence !== null) {
            $sheet->getStyle("D{$row}")->getFont()->setBold(true)->getColor()->setARGB(self::RED);
        }

        if ($absence === null && ! $departed) {
            $this->punchCells($sheet, $row, $punch, $day, $timezone);
        }

        $sheet->getStyle("C{$row}:F{$row}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => self::FRAME,
        ]);

        if ($banded) {
            $sheet->getStyle("C{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BAND_FILL);
        }

        $lines = collect(explode("\n", $work))->sum(fn (string $line) => max(1, (int) ceil(mb_strlen($line) / 88)));
        $sheet->getRowDimension($row)->setRowHeight(max(22, $lines * 15 + 6));
    }

    private function punchCells(Worksheet $sheet, int $row, ?DayPunch $punch, CarbonImmutable $day, string $timezone): void
    {
        if ($punch === null) {
            return;
        }

        $purged = $punch->location_purged_at !== null;
        $this->timeCell($sheet, "E{$row}", $punch->start_at->setTimezone($timezone)->format('H:i'), $purged ? null : $punch->start_latitude, $purged ? null : $punch->start_longitude, false, $punch->hasImpreciseStart());

        if ($punch->end_at === null) {
            $sheet->setCellValue("F{$row}", __($day->lt(CarbonImmutable::now($timezone)->startOfDay()) ? 'weekly_timesheet.not_finished' : 'weekly_timesheet.in_progress'));
            $sheet->getStyle("F{$row}")->getFont()->setItalic(true)->getColor()->setARGB(self::RED);

            return;
        }

        $this->timeCell($sheet, "F{$row}", $punch->end_at->setTimezone($timezone)->format('H:i'), $purged ? null : $punch->end_latitude, $purged ? null : $punch->end_longitude, $punch->end_declared, $punch->hasImpreciseEnd());
    }

    /**
     * A punch time, with « 📍 » and a link to the map when its position is known (an end declared afterwards is
     * marked « * » and has no link). ⚠ follows an imprecise position.
     */
    private function timeCell(Worksheet $sheet, string $cell, string $time, ?string $latitude, ?string $longitude, bool $declared, bool $imprecise): void
    {
        $located = $latitude !== null && $longitude !== null;
        $sheet->setCellValue($cell, $time.($declared ? ' *' : '').($located ? '  📍'.($imprecise ? ' ⚠' : '') : ''));

        if (! $located || $declared) {
            $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setARGB(self::INK);

            return;
        }

        $sheet->getCell($cell)->getHyperlink()->setUrl(str_replace([':lat', ':lng'], [(string) (float) $latitude, (string) (float) $longitude], (string) config('platform.maps_link')));
        $sheet->getStyle($cell)->getFont()->setBold(true)->setUnderline(true)->getColor()->setARGB(self::LINK);
    }

    /**
     * @param  Collection<int, User>  $people
     * @return array{tasks: array<int, array<string, Task>>, absences: array<int, array<string, Absence>>, punches: array<int, array<string, DayPunch>>}
     */
    private function load(Collection $people, CarbonImmutable $monday): array
    {
        $ids = $people->pluck('id')->all();
        $last = $monday->addDays(5);

        $tasks = [];

        foreach (Task::query()->with(['workSite.client', 'workSite.boxes.partners'])->whereIn('user_id', $ids)->whereDate('date', '>=', $monday)->whereDate('date', '<=', $last)->get() as $task) {
            $tasks[$task->user_id][$task->date->toDateString()] = $task;
        }

        $punches = [];

        foreach (DayPunch::query()->whereIn('user_id', $ids)->whereDate('date', '>=', $monday)->whereDate('date', '<=', $last)->get() as $punch) {
            $punches[$punch->user_id][$punch->date->toDateString()] = $punch;
        }

        $absences = [];

        foreach (Absence::query()->whereIn('user_id', $ids)->whereDate('starts_on', '<=', $last)->whereDate('ends_on', '>=', $monday)->orderBy('id')->get() as $absence) {
            foreach (CarbonPeriod::create($absence->starts_on->max($monday), $absence->ends_on->min($last)) as $day) {
                $absences[$absence->user_id][$day->toDateString()] = $absence;
            }
        }

        return ['tasks' => $tasks, 'absences' => $absences, 'punches' => $punches];
    }

    /**
     * @param  array{tasks: array<int, array<string, Task>>, absences: array<int, array<string, Absence>>, punches: array<int, array<string, DayPunch>>}  $data
     */
    private function hasEntries(User $person, array $data): bool
    {
        return isset($data['tasks'][$person->id]) || isset($data['absences'][$person->id]) || isset($data['punches'][$person->id]);
    }

    /**
     * Monday to Friday, and Saturday when a task is planned that day.
     *
     * @param  array<int, array<string, Task>>  $tasks
     * @return list<CarbonImmutable>
     */
    private function days(CarbonImmutable $monday, array $tasks): array
    {
        $saturday = $monday->addDays(5)->toDateString();
        $days = [];

        foreach (range(0, 4) as $offset) {
            $days[] = $monday->addDays($offset);
        }

        foreach ($tasks as $perDay) {
            if (isset($perDay[$saturday])) {
                $days[] = $monday->addDays(5);

                break;
            }
        }

        return $days;
    }
}
