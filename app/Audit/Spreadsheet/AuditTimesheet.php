<?php

namespace App\Audit\Spreadsheet;

use App\Enums\AuditBoxStatus;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditPhoto;
use App\Models\Task;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\SheetText;
use Carbon\CarbonImmutable;
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
 * The audit time sheet of a period: for each day and each technician, the boxes and control points really audited
 * (photos taken that day, not imported by a manager, and statuses set that day by someone working on site) with the
 * number of photos, plus the report articles written that day. Technicians planned on an audit site appear even without
 * photo. Weekends only appear when there was work.
 *
 * @phpstan-type Slot array{boxes: array<string, array{photos: int, status: AuditBoxStatus|null}>, points: array<string, int>, report: array<string, int>}
 */
class AuditTimesheet
{
    public const int MAX_DAYS = 100;

    private const string HEADER_FILL = 'FFF2A104';

    private const string DAY_FILL = 'FFFCE9B8';

    private const string BAND_FILL = 'FFF3F6F8';

    public function __construct(private CompanySettings $company) {}

    /**
     * @return Spreadsheet|null Null when nothing happened in the period.
     */
    public function build(User $by, CarbonImmutable $from, CarbonImmutable $to): ?Spreadsheet
    {
        $timezone = (string) config('platform.timezone');
        $from = $from->setTimezone($timezone)->startOfDay();
        $to = $to->setTimezone($timezone)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $to = $from->addDays(min($from->diffInDays($to), self::MAX_DAYS - 1));
        $act = $this->activity($from, $to->endOfDay(), $timezone);
        $planned = $this->planned($from, $to);

        if ($act === [] && $planned === []) {
            return null;
        }

        $technicians = $this->technicians(collect($act)->flatMap(fn (array $day) => array_keys($day))->merge(collect($planned)->flatMap(fn (array $day) => array_keys($day)))->map(fn ($key) => (string) $key)->unique()->values()->all());
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sameWeek = $from->startOfWeek()->equalTo($to->startOfWeek());
        $sheet->setTitle(mb_substr($sameWeek ? __('audit.timesheet.sheet_week', ['week' => $from->isoWeek]) : __('audit.timesheet.sheet'), 0, 31));
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);

        foreach (['A' => 11, 'B' => 17, 'C' => 24, 'D' => 84, 'E' => 10, 'F' => 10] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $this->header($sheet, $from, $to, $sameWeek);
        $totals = [];
        $row = 7;
        $dayIndex = 0;
        $weekBlocks = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $keys = collect(array_keys($act[$key] ?? []))->merge(array_keys($planned[$key] ?? []))->map(fn ($id) => (string) $id)->unique()->sortBy(fn (string $id) => $technicians[$id]['order'])->values();

            if ($day->isWeekend() && $keys->isEmpty() || $keys->isEmpty() && $day->gt(CarbonImmutable::now($timezone))) {
                continue;
            }

            $first = $row;
            $banded = $dayIndex % 2 === 1;

            foreach ($keys->isEmpty() ? [null] : $keys as $id) {
                $this->line($sheet, $row, $id, $act[$key][$id] ?? null, $planned[$key][$id] ?? null, $technicians, $banded, $totals);
                $row++;
            }

            if ($row - 1 > $first) {
                $sheet->mergeCells("B{$first}:B".($row - 1));
            }

            $sheet->setCellValue("B{$first}", mb_convert_case($day->translatedFormat('l'), MB_CASE_TITLE)."\n".$day->translatedFormat('d/m/Y'));
            $sheet->getStyle("B{$first}:B".($row - 1))->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF12303F']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::DAY_FILL]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC9D3D9']]],
            ]);

            $week = $day->startOfWeek()->toDateString();

            if (($weekBlocks[array_key_last($weekBlocks) ?? 0]['week'] ?? null) === $week) {
                $weekBlocks[array_key_last($weekBlocks)]['last'] = $row - 1;
            } else {
                $weekBlocks[] = ['week' => $week, 'number' => $day->isoWeek, 'first' => $first, 'last' => $row - 1];
            }

            $dayIndex++;
        }

        foreach ($weekBlocks as $block) {
            if ($block['last'] > $block['first']) {
                $sheet->mergeCells("A{$block['first']}:A{$block['last']}");
            }

            $sheet->setCellValue("A{$block['first']}", 'S'.$block['number']);
            $sheet->getStyle("A{$block['first']}:A{$block['last']}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF12303F']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::DAY_FILL]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC9D3D9']]],
            ]);
        }

        $this->recap($sheet, $row + 1, $totals, $technicians, $sameWeek);
        $note = $row + 4 + count($totals);
        $sheet->mergeCells("A{$note}:F{$note}")->setCellValue("A{$note}", __('audit.timesheet.note', ['date' => now($timezone)->translatedFormat('d/m/Y'), 'user' => $by->name, 'app' => $this->company->displayName()]));
        $sheet->getStyle("A{$note}")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF667780');

        return $book;
    }

    /**
     * What was done, by day and by technician (key: user id, or « n:<name> » when the author is unknown).
     *
     * @return array<string, array<string, array<string, Slot>>> date → technician → cabinet → slot
     */
    private function activity(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $act = [];
        $day = fn (?\DateTimeInterface $moment) => $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone($timezone)->toDateString();
        $range = [$from->toDateString(), $to->toDateString()];
        $slot = function (string $date, ?int $id, ?string $name, string $cabinet) use (&$act): array {
            $key = $id !== null ? (string) $id : 'n:'.($name ?: '?');
            $act[$date][$key][$cabinet] ??= ['boxes' => [], 'points' => [], 'report' => [], 'name' => $name];

            return [$date, $key, $cabinet];
        };
        $addReport = function (string $date, ?int $id, ?string $name, string $cabinet, string $label, int $quantity) use (&$act, $slot): void {
            [$d, $k, $c] = $slot($date, $id, $name, $cabinet);
            $act[$d][$k][$c]['report'][$label] = ($act[$d][$k][$c]['report'][$label] ?? 0) + $quantity;
        };
        $within = fn (?string $date) => $date !== null && $date >= $range[0] && $date <= $range[1];
        $manager = fn (?User $user) => $user !== null && ! $user->is_plannable && ! $user->hasRole(Role::Technician->value);

        Audit::query()->with(['boxes' => fn ($query) => $query->whereNull('removed_at'), 'boxes.photos', 'controlPoints.photos'])->get()->each(function (Audit $audit) use (&$act, $day, $within, $slot, $manager, $addReport): void {
            $cabinet = $audit->cabinet_key;
            $users = User::query()->with('roles')->whereIn('id', collect([...$audit->boxes->pluck('status_by'), ...$audit->boxes->pluck('report_by'), ...$audit->controlPoints->pluck('report_by')])->filter()->unique())->get()->keyBy('id');

            foreach ($audit->boxes as $box) {
                foreach ($box->photos->reject(fn (AuditPhoto $photo) => $photo->is_imported) as $photo) {
                    if ($within($date = $day($photo->captured_at))) {
                        [$d, $k, $c] = $slot($date, $photo->user_id, $photo->user_name, $cabinet);
                        $act[$d][$k][$c]['boxes'][$box->name]['photos'] = ($act[$d][$k][$c]['boxes'][$box->name]['photos'] ?? 0) + 1;
                    }
                }

                if ($box->status !== null && ! $box->isReturned() && $within($date = $day($box->status_at)) && ! $manager($users->get($box->status_by))) {
                    [$d, $k, $c] = $slot($date, $box->status_by, $box->status_by_name, $cabinet);
                    $act[$d][$k][$c]['boxes'][$box->name]['photos'] ??= 0;
                    $act[$d][$k][$c]['boxes'][$box->name]['status'] = $box->status;
                }

                $this->report($addReport, $within, $day, $box, $cabinet);
            }

            foreach ($audit->controlPoints as $point) {
                foreach ($point->photos->reject(fn (AuditPhoto $photo) => $photo->is_imported) as $photo) {
                    if ($within($date = $day($photo->captured_at))) {
                        [$d, $k, $c] = $slot($date, $photo->user_id, $photo->user_name, $cabinet);
                        $act[$d][$k][$c]['points'][$point->name] = ($act[$d][$k][$c]['points'][$point->name] ?? 0) + 1;
                    }
                }

                $this->report($addReport, $within, $day, $point, $cabinet);
            }

            foreach ($act as $date => $technicians) {
                foreach ($technicians as $key => $cabinets) {
                    foreach ($audit->boxes as $box) {
                        if (isset($cabinets[$cabinet]['boxes'][$box->name]) && $box->status !== null && ! $box->isReturned()) {
                            $act[$date][$key][$cabinet]['boxes'][$box->name]['status'] ??= $box->status;
                        }
                    }
                }
            }
        });

        return $act;
    }

    /**
     * @param  callable(string, int|null, string|null, string, string, int): void  $add
     */
    private function report(callable $add, callable $within, callable $day, AuditBox|AuditControlPoint $unit, string $cabinet): void
    {
        if (($unit->report_items ?? []) === [] || ! $within($date = $day($unit->report_at))) {
            return;
        }

        foreach ($unit->report_items as $item) {
            $add($date, $unit->report_by, $unit->report_by_name, $cabinet, $item['label'], (int) $item['qty']);
        }
    }

    /**
     * Technicians planned on an audit site, by day (not when they are absent that day).
     *
     * @return array<string, array<int|string, array{detail: string}>>
     */
    private function planned(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $planned = [];
        $absences = Absence::query()->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->get();

        Task::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereHas('workSite', fn ($query) => $query->where('project_type', ProjectType::Audit->value))
            ->get()->each(function (Task $task) use (&$planned, $absences): void {
                $absent = $absences->contains(fn (Absence $absence) => $absence->user_id === $task->user_id && $absence->starts_on->lte($task->date) && $absence->ends_on->gte($task->date));

                if (! $absent) {
                    $planned[$task->date->toDateString()][(string) $task->user_id] = ['detail' => (string) $task->detail];
                }
            });

        return $planned;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{name: string, order: int}>
     */
    private function technicians(array $keys): array
    {
        $users = User::query()->whereIn('id', array_filter($keys, fn (string $key) => ctype_digit($key)))->get()->keyBy('id');
        $out = [];

        foreach ($keys as $key) {
            $user = ctype_digit($key) ? $users->get((int) $key) : null;
            $out[$key] = ['name' => $user !== null ? $user->name : substr($key, 2), 'order' => $user === null || $user->planning_order === 0 ? 9999 : $user->planning_order];
        }

        return $out;
    }

    private function header(Worksheet $sheet, CarbonImmutable $from, CarbonImmutable $to, bool $sameWeek): void
    {
        $logo = $this->company->logo_path;

        if ($logo !== null && Storage::disk('local')->exists($logo)) {
            $drawing = new Drawing;
            $drawing->setPath(Storage::disk('local')->path($logo));
            $drawing->setHeight(40);
            $drawing->setCoordinates('A1');
            $drawing->setWorksheet($sheet);
        }

        $sheet->mergeCells('C2:F3')->setCellValue('C2', __('audit.timesheet.title', ['company' => mb_strtoupper($this->company->displayName())]));
        $sheet->getStyle('C2')->applyFromArray(['font' => ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => ['argb' => 'FF12303F']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->mergeCells('C4:F4')->setCellValue('C4', ($sameWeek ? __('audit.timesheet.week', ['week' => $from->isoWeek]).' · ' : '').__('audit.timesheet.period', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]));
        $sheet->getStyle('C4')->getFont()->setName('Arial')->setSize(11)->getColor()->setARGB('FF667780');

        foreach (['week', 'date', 'technician', 'work', 'boxes', 'photos'] as $index => $key) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'6', __('audit.timesheet.columns.'.$key));
        }

        $sheet->getStyle('A6:F6')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF2A1B00']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC9D3D9']]],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(26);
    }

    /**
     * @param  array<string, Slot>|null  $cabinets
     * @param  array{detail: string}|null  $planned
     * @param  array<string, array{name: string, order: int}>  $technicians
     * @param  array<string, array{name: string, boxes: int, photos: int}>  $totals
     */
    private function line(Worksheet $sheet, int $row, ?string $id, ?array $cabinets, ?array $planned, array $technicians, bool $banded, array &$totals): void
    {
        $text = '—';
        $boxes = $photos = null;
        $name = $id === null ? '' : $technicians[$id]['name'];

        if ($cabinets !== null) {
            $lines = [];
            $boxes = $photos = 0;

            foreach (collect($cabinets)->sortKeys(SORT_NATURAL) as $cabinet => $slot) {
                $names = collect($slot['boxes'])->sortKeysUsing('strnatcasecmp');
                $boxes += $names->count();
                $photos += (int) $names->sum('photos') + array_sum($slot['points']);
                $parts = [];

                if ($names->isNotEmpty()) {
                    $parts[] = $names->map(fn (array $box, string $box_name) => $box_name.$this->flag($box['status'] ?? null))->implode(' · ');
                }

                if ($slot['points'] !== []) {
                    $points = array_keys($slot['points']);
                    natcasesort($points);
                    $parts[] = __('audit.timesheet.points').' : '.implode(', ', $points);
                }

                if ($slot['report'] !== []) {
                    $parts[] = __('audit.timesheet.report').' : '.collect($slot['report'])->map(fn (int $quantity, string $label) => $label.($quantity > 1 ? ' ×'.$quantity : ''))->implode(' · ');
                }

                $lines[] = $cabinet.' — '.__('audit.timesheet.audit').' : '.implode(' — ', $parts);
            }

            $text = implode("\n", $lines);
            $totals[$id] = ['name' => $name, 'boxes' => ($totals[$id]['boxes'] ?? 0) + $boxes, 'photos' => ($totals[$id]['photos'] ?? 0) + $photos];
        } elseif ($planned !== null) {
            $text = __('audit.timesheet.planned_without_photo').($planned['detail'] !== '' ? ' ('.$planned['detail'].')' : '');
        }

        SheetText::cell($sheet, "C{$row}", $name);
        SheetText::cell($sheet, "D{$row}", $text);
        $sheet->setCellValue("E{$row}", $boxes);
        $sheet->setCellValue("F{$row}", $photos);
        $sheet->getStyle("C{$row}")->getFont()->setBold(true);

        if ($cabinets === null) {
            $sheet->getStyle("D{$row}")->getFont()->setItalic(true)->getColor()->setARGB('FF99A5AC');
        }

        $range = "C{$row}:F{$row}";
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFC9D3D9');
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("E{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(max(22, collect(explode("\n", $text))->sum(fn (string $line) => max(1, (int) ceil(mb_strlen($line) / 88))) * 15 + 6));

        if ($banded) {
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BAND_FILL);
        }
    }

    private function flag(?AuditBoxStatus $status): string
    {
        return match ($status) {
            AuditBoxStatus::Anomaly => ' ⚠',
            AuditBoxStatus::NotInstalled => ' ('.__('audit.timesheet.flag_not_installed').')',
            AuditBoxStatus::Partial => ' ('.__('audit.timesheet.flag_partial').')',
            default => '',
        };
    }

    /**
     * @param  array<string, array{name: string, boxes: int, photos: int}>  $totals
     * @param  array<string, array{name: string, order: int}>  $technicians
     */
    private function recap(Worksheet $sheet, int $row, array $totals, array $technicians, bool $sameWeek): void
    {
        $sheet->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", __($sameWeek ? 'audit.timesheet.recap_week' : 'audit.timesheet.recap_period'));
        $sheet->setCellValue("E{$row}", __('audit.timesheet.columns.boxes'));
        $sheet->setCellValue("F{$row}", __('audit.timesheet.columns.photos'));
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF12303F']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::DAY_FILL]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::HEADER_FILL]]],
        ]);
        $sheet->getStyle("E{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $keys = array_keys($totals);
        usort($keys, fn (string $a, string $b) => ($technicians[$a]['order'] <=> $technicians[$b]['order']) ?: strcasecmp($totals[$a]['name'], $totals[$b]['name']));
        $boxes = $photos = 0;

        foreach ($keys as $key) {
            $row++;
            $sheet->mergeCells("A{$row}:C{$row}");
            SheetText::cell($sheet, "A{$row}", $totals[$key]['name']);
            $sheet->setCellValue("E{$row}", $totals[$key]['boxes']);
            $sheet->setCellValue("F{$row}", $totals[$key]['photos']);
            $sheet->getStyle("E{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $boxes += $totals[$key]['boxes'];
            $photos += $totals[$key]['photos'];
        }

        $row++;
        $sheet->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", __('audit.timesheet.total'));
        $sheet->setCellValue("E{$row}", $boxes);
        $sheet->setCellValue("F{$row}", $photos);
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FF12303F']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::DAY_FILL]]]);
        $sheet->getStyle("E{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}
