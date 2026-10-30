<?php

namespace App\Audit\Recheck;

use App\Enums\AuditSpliceType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the RECNOK workbook: the original plan, never touched, plus a « RECNOK » sheet right after « BOITE », a copy
 * of it with what the technician found on site (changes in red, colours on the fibre and tube cells, cassette number in
 * column I). A conform box gets a copy with only the cassette numbers added.
 *
 * @phpstan-import-type Line from BoxRecheck
 */
class RecnokWriter
{
    private const string RED = 'FFFF0000';

    public function __construct(private RecheckPlanReader $reader) {}

    /**
     * @param  list<Line>  $lines
     * @param  array<string, array{default: string, value: string}>  $numbers
     *
     * @throws RecheckPlanException
     */
    public function write(string $originalPath, array $lines, array $numbers, bool $conform): string
    {
        try {
            $book = IOFactory::createReader('Xlsx')->load($originalPath);
        } catch (\Throwable) {
            throw new RecheckPlanException('unreadable');
        }

        $this->dropSheet($book, 'RECNOK');
        $source = $this->sheet($book);
        $copy = clone $source;
        $copy->setTitle('RECNOK');
        $book->addSheet($copy, $book->getIndex($source) + 1);

        if (! $conform) {
            $this->fill($copy, $lines);
        }

        $this->cassetteColumn($copy, $this->reader->headerRow($copy), $lines, $numbers);
        $book->setActiveSheetIndex($book->getIndex($source));
        $path = (string) tempnam(sys_get_temp_dir(), 'recnok');

        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }

    private function dropSheet(Spreadsheet $book, string $name): void
    {
        foreach ($book->getAllSheets() as $sheet) {
            if (strcasecmp(trim($sheet->getTitle()), $name) === 0 && $book->getSheetCount() > 1) {
                $book->removeSheetByIndex($book->getIndex($sheet));
            }
        }
    }

    private function sheet(Spreadsheet $book): Worksheet
    {
        foreach ($book->getAllSheets() as $sheet) {
            if (strcasecmp(trim($sheet->getTitle()), 'BOITE') === 0) {
                return $sheet;
            }
        }

        return $book->getSheet(0);
    }

    /**
     * @param  list<Line>  $lines
     */
    private function fill(Worksheet $sheet, array $lines): void
    {
        foreach ($lines as $line) {
            $row = $line['row'];
            $original = $line['original'];

            $this->typeCell($sheet, $row, AuditSpliceType::tryFrom($line['type'])?->planWord() ?? $line['plan_word'], $line['type'] !== $original['type']);
            $this->colour($sheet, 6, $row, $line['fibre_b'], self::number($line['fibre_b']) !== self::number($original['fibre_b']));
            $this->colour($sheet, 7, $row, $line['tube_b'], self::number($line['tube_b']) !== self::number($original['tube_b']));
            $this->cable($sheet, 8, $row, $line['cable_b'], $line['cable_b'] !== $original['cable_b']);

            foreach ([3 => 'tube_a', 4 => 'fibre_a'] as $column => $field) {
                if (self::number($line[$field]) !== self::number($original[$field])) {
                    $this->colour($sheet, $column, $row, $line[$field], true);
                }
            }

            if ($line['cable_a'] !== $original['cable_a']) {
                $this->unmerge($sheet, 2, $row);
                $this->cable($sheet, 2, $row, $line['cable_a'], true);
            }
        }
    }

    private function typeCell(Worksheet $sheet, int $row, string $word, bool $changed): void
    {
        $cell = $sheet->getCell([5, $row]);
        $cell->setValueExplicit($word, 's');

        if ($changed) {
            $cell->getStyle()->getFont()->setBold(true)->getColor()->setARGB(self::RED);
        }
    }

    /**
     * A cable name (column B or H); a changed one in bold red.
     */
    private function cable(Worksheet $sheet, int $column, int $row, string $value, bool $changed): void
    {
        $cell = $sheet->getCell([$column, $row]);
        $value === '' ? $cell->setValue(null) : $cell->setValueExplicit($value, 's');

        if ($changed && $value !== '') {
            $cell->getStyle()->getFont()->setBold(true)->getColor()->setARGB(self::RED);
        }
    }

    /**
     * Cable A is often merged over a whole cassette: the range is split and every one of its cells keeps the value and
     * the style of the range before one of them is changed.
     */
    private function unmerge(Worksheet $sheet, int $column, int $row): void
    {
        $range = $sheet->getCell([$column, $row])->getMergeRange();

        if ($range === false) {
            return;
        }

        [[$fromColumn, $fromRow], [$toColumn, $toRow]] = Coordinate::rangeBoundaries($range);

        if ($fromColumn !== $toColumn) {
            return;
        }

        $master = $sheet->getCell([$fromColumn, $fromRow]);
        $value = $master->getValue();
        $style = $master->getStyle();
        $sheet->unmergeCells($range);

        for ($line = $fromRow; $line <= $toRow; $line++) {
            $sheet->getCell([$column, $line])->setValue($value);
            $sheet->duplicateStyle($style, Coordinate::stringFromColumnIndex($column).$line);
        }
    }

    /**
     * A fibre or tube code written on its colour, white text on the dark colours; a changed one in bold red (on a dark
     * colour, white text and a red frame instead). No code empties the cell.
     */
    private function colour(Worksheet $sheet, int $column, int $row, string $code, bool $changed): void
    {
        $cell = $sheet->getCell([$column, $row]);
        $number = self::number($code);

        if ($number === 0) {
            $cell->setValue(null);

            return;
        }

        $colour = config('audit.recheck_palette')[($number - 1) % 12];
        $cell->setValueExplicit(($column === 4 || $column === 6 ? 'F' : 'T').str_pad((string) $number, 2, '0', STR_PAD_LEFT), 's');
        $style = $cell->getStyle();
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF'.$colour['hex']);
        $style->getFont()->getColor()->setARGB($colour['dark'] ? Color::COLOR_WHITE : Color::COLOR_BLACK);

        if (! $changed) {
            return;
        }

        $style->getFont()->setBold(true);

        if ($colour['dark']) {
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::RED);
        } else {
            $style->getFont()->getColor()->setARGB(self::RED);
        }
    }

    /**
     * Column I: the cassette number, in the style of column H (also on a conform box). A header on two rows gets a
     * merged « N° CASSETTE » over both, as column H.
     *
     * @param  list<Line>  $lines
     * @param  array<string, array{default: string, value: string}>  $numbers
     */
    private function cassetteColumn(Worksheet $sheet, int $header, array $lines, array $numbers): void
    {
        if ($header === 0) {
            return;
        }

        $top = $header > 1 && trim((string) $sheet->getCell([8, $header - 1])->getValue()) !== '' ? $header - 1 : $header;
        $sheet->getCell([9, $top])->setValueExplicit('N° CASSETTE', 's');
        $sheet->duplicateStyle($sheet->getStyle([8, $top]), Coordinate::stringFromColumnIndex(9).$top);

        if ($top < $header) {
            $sheet->duplicateStyle($sheet->getStyle([8, $header]), Coordinate::stringFromColumnIndex(9).$header);
            $sheet->mergeCells([9, $top, 9, $header]);
        }

        $sheet->getColumnDimensionByColumn(9)->setWidth(14);

        foreach ($lines as $line) {
            $number = $numbers[$line['cassette']] ?? ['default' => '', 'value' => ''];
            $cell = $sheet->getCell([9, $line['row']]);
            $number['value'] === '' ? $cell->setValue(null) : $cell->setValueExplicit($number['value'], 's');
            $sheet->duplicateStyle($sheet->getStyle([8, $line['row']]), $cell->getCoordinate());

            if ($number['value'] !== '' && $number['value'] !== $number['default']) {
                $cell->getStyle()->getFont()->setBold(true)->getColor()->setARGB(self::RED);
            }
        }
    }

    /**
     * Colour number of a fibre or tube code (« F05 » → 5), 0 when there is none.
     */
    private static function number(string $code): int
    {
        return preg_match('/(\d+)/', $code, $match) === 1 ? (int) $match[1] : 0;
    }
}
