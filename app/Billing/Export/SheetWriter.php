<?php

namespace App\Billing\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plain tables written into the billing workbooks: numbers stay numbers, text stays text, and sheet names follow
 * Excel's rules.
 */
class SheetWriter
{
    /** Fill of the rows that are not billed. */
    public const string NOT_BILLED_FILL = 'FFE9A8';

    /**
     * A new sheet (the workbook's first one when it is still unused), with a valid and unique name.
     */
    public static function sheet(Spreadsheet $book, string $title): Worksheet
    {
        $first = $book->getSheet(0);
        $sheet = $book->getSheetCount() === 1 && $first->getTitle() === 'Worksheet' && $first->getHighestRow() === 1 && $first->getCell('A1')->getValue() === null
            ? $first
            : $book->createSheet();

        $base = mb_substr(trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', $title)) ?: __('billing.export.sheets.untitled'), 0, 31);
        $name = $base;

        for ($copy = 2; $book->sheetNameExists($name) && $book->getSheetByName($name) !== $sheet; $copy++) {
            $name = mb_substr($base, 0, 31 - strlen(' ('.$copy.')')).' ('.$copy.')';
        }

        $sheet->setTitle($name);

        return $sheet;
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function rows(Worksheet $sheet, array $rows, int $firstRow = 1): void
    {
        foreach ($rows as $offset => $values) {
            foreach ($values as $index => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $cell = [$index + 1, $firstRow + $offset];
                is_string($value) ? $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING) : $sheet->setCellValue($cell, $value);
            }
        }
    }

    /**
     * @param  list<int>  $widths
     */
    public static function widths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
    }

    public static function fill(Worksheet $sheet, int $row, int $columns, string $colour = self::NOT_BILLED_FILL): void
    {
        $sheet->getStyle([1, $row, $columns, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colour);
    }

    /**
     * The workbook as an in-memory stream, ready to be added to a ZIP.
     *
     * @return resource
     */
    public static function stream(Spreadsheet $book)
    {
        $stream = fopen('php://memory', 'w+b');
        abort_unless(is_resource($stream), 500);

        (new Xlsx($book))->save($stream);
        rewind($stream);

        return $stream;
    }

    /**
     * A file or folder name without the characters file systems refuse.
     */
    public static function fileName(string $name): string
    {
        return trim((string) preg_replace('/[\\\\\/:\*\?"<>\|]+/', '-', $name));
    }
}
