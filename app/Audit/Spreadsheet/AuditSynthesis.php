<?php

namespace App\Audit\Spreadsheet;

use App\Audit\BoxProgress;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditCable;
use App\Models\AuditPhoto;
use App\Models\User;
use App\Support\SheetText;
use PhpOffice\PhpSpreadsheet\Cell\Hyperlink;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The synthesis of a cabinet in the optical mode: one line per box with its cable, its confirmed position (map link),
 * its TCO photos, status and technician. It is written for the design office, in the language of the person asking.
 */
class AuditSynthesis
{
    private const string HEADER_FILL = 'FFF2A104';

    private const string BAND_FILL = 'FFF3F6F8';

    private const string RED = 'FFB3382C';

    public function __construct(private BoxProgress $progress) {}

    public function build(Audit $audit, User $by): Spreadsheet
    {
        $audit->load(['boxes.cables', 'boxes.photos', 'boxes.remarks']);
        $mode = $audit->effectiveMode();
        $boxes = $audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved())->sortBy(fn (AuditBox $box) => $box->kind->value.' '.$box->name, SORT_NATURAL | SORT_FLAG_CASE)->values();
        $done = $boxes->filter(fn (AuditBox $box) => $box->isFinal())->count();
        $timezone = (string) config('platform.timezone');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(__('audit.synthesis.sheet', ['cabinet' => $audit->cabinet_key]), 0, 31));
        $sheet->setShowGridlines(false);

        foreach (['A' => 18, 'B' => 8, 'C' => 14, 'E' => 24, 'F' => 11, 'G' => 8, 'H' => 38, 'I' => 22, 'J' => 18, 'K' => 10] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->mergeCells('A1:K1');
        SheetText::cell($sheet, 'A1', __('audit.synthesis.title').' — '.$audit->cabinet_key.($audit->sro ? ' · '.$audit->sro : ''));
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(15)->setBold(true)->getColor()->setARGB('FF12303F');
        $sheet->mergeCells('A2:K2')->setCellValue('A2', __('audit.synthesis.generated', ['date' => now($timezone)->translatedFormat('d/m/Y'), 'user' => $by->name, 'done' => $done, 'total' => $boxes->count(), 'percent' => $this->progress->auditPercent($audit)]));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setARGB('FF667780');

        foreach (['box', 'type', 'status', 'cable', 'position', 'accuracy', 'tco', 'tco_comment', 'technician', 'finished_on', 'open_messages'] as $index => $key) {
            $sheet->setCellValue([$index + 1, 4], __('audit.synthesis.columns.'.$key));
        }

        $sheet->getStyle('A4:K4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF2A1B00']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC9D3D9']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(30);

        foreach ($boxes as $index => $box) {
            $row = 5 + $index;
            $tco = $box->photos->where('folder', 'tco')->count();
            SheetText::row($sheet, [
                $box->name, $box->kind->label(), trim(($box->status?->label() ?? __('audit.todo')).($box->reason ? ' : '.$box->reason : '')),
                $this->cables($box, $mode->value === 'optical'), '', $box->confirmed_accuracy !== null ? '± '.$box->confirmed_accuracy.' m' : '', $tco, (string) $box->note_tco,
                $this->technician($box), $box->status_at?->timezone($timezone)->format('d/m H:i') ?? '',
                $box->remarks->filter(fn ($remark) => $remark->author_side === 'office' && $remark->acknowledged_at === null)->count(),
            ], $row);

            $sheet->setCellValue("G{$row}", $tco);
            $range = "A{$row}:K{$row}";
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFC9D3D9');
            $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            if ($index % 2 === 1) {
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BAND_FILL);
            }

            $this->position($sheet, $row, $box);

            if ($tco === 0) {
                $sheet->getStyle("G{$row}")->getFont()->setBold(true)->getColor()->setARGB(self::RED);
            }
        }

        return $book;
    }

    private function position(Worksheet $sheet, int $row, AuditBox $box): void
    {
        $cell = $sheet->getCell("E{$row}");

        if ($box->confirmed_at === null) {
            $cell->setValueExplicit(__('audit.synthesis.not_confirmed'), 's');
            $sheet->getStyle("E{$row}")->getFont()->setItalic(true)->getColor()->setARGB(self::RED);

            return;
        }

        $latitude = (float) $box->confirmed_latitude;
        $longitude = (float) $box->confirmed_longitude;
        $cell->setValueExplicit(number_format($latitude, 6, '.', '').', '.number_format($longitude, 6, '.', '').'  📍', 's');
        $cell->setHyperlink(new Hyperlink("https://www.google.com/maps?q={$latitude},{$longitude}", __('audit.synthesis.see_on_map')));
        $sheet->getStyle("E{$row}")->getFont()->setUnderline(true)->getColor()->setARGB('FF1F6FB2');
    }

    /**
     * Who set the status; without status, the last person who worked on the box on site (photo or position).
     */
    private function technician(AuditBox $box): string
    {
        if ($box->status_by_name !== null) {
            return $box->status_by_name;
        }

        $photo = $box->photos->reject(fn (AuditPhoto $photo) => $photo->is_imported)->sortBy('captured_at')->last();
        $candidates = collect([[$photo?->captured_at, $photo?->user_name], [$box->confirmed_at, $box->confirmed_by_name]])->filter(fn (array $candidate) => $candidate[0] !== null && $candidate[1] !== null);

        return (string) ($candidates->sortBy(fn (array $candidate) => $candidate[0])->last()[1] ?? '');
    }

    private function cables(AuditBox $box, bool $optical): string
    {
        if (! $optical) {
            return (string) $box->fibre_type?->label();
        }

        if ($box->cables->isEmpty()) {
            return '';
        }

        return trans_choice('pdf.audit.cable_count', $box->cables->count(), ['count' => $box->cables->count()]).' : '
            .$box->cables->map(fn (AuditCable $cable) => (trim(implode(' ', array_filter([$cable->capacity, $cable->fibre_type?->label()]))) ?: '?').($cable->note ? ' ('.preg_replace('/\s+/', ' ', $cable->note).')' : ''))->implode(', ');
    }
}
