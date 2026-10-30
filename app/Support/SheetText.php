<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes text into exported spreadsheets as plain text: a name or a note typed by someone that starts with « = »,
 * « + », « - » or « @ » is shown as typed and never runs as a formula. Numbers stay numbers.
 */
class SheetText
{
    /**
     * @param  string|array{0: int, 1: int}  $cell
     */
    public static function cell(Worksheet $sheet, string|array $cell, string|int|float|null $value): void
    {
        is_string($value) ? $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING) : $sheet->setCellValue($cell, $value);
    }

    /**
     * @param  list<string|int|float|null>  $values
     */
    public static function row(Worksheet $sheet, array $values, int $row, int $firstColumn = 1): void
    {
        foreach ($values as $index => $value) {
            self::cell($sheet, [$firstColumn + $index, $row], $value);
        }
    }
}
