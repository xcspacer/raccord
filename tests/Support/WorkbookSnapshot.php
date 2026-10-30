<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A comparable picture of a workbook, for the golden-file tests: sheet order and state, merged ranges, column widths,
 * row heights and, for every cell holding a value or a visible style, its value, link, number format, font, fill,
 * borders and alignment. Two workbooks written by different libraries compare equal when they look the same in
 * Excel: default styles are left out, cells hidden under a merged range are skipped, and on a sheet without
 * gridlines a white fill is the same as no fill.
 *
 * @phpstan-type Picture array<string, mixed>
 * @phpstan-type SheetPicture array{state: string, gridlines: bool, merges: list<string>, widths: array<string, float>, heights: array<int, float>, cells: array<string, Picture>}
 */
final class WorkbookSnapshot
{
    /**
     * @return array<string, SheetPicture>
     */
    public static function of(string $path): array
    {
        $book = IOFactory::createReader('Xlsx')->load($path);

        try {
            return self::ofBook($book);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * @return array<string, SheetPicture>
     */
    public static function ofContents(string $contents): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'snapshot');
        file_put_contents($path, $contents);

        try {
            return self::of($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, SheetPicture>
     */
    public static function ofBook(Spreadsheet $book): array
    {
        $sheets = [];

        foreach ($book->getAllSheets() as $sheet) {
            $sheets[$sheet->getTitle()] = self::sheet($sheet);
        }

        return $sheets;
    }

    /**
     * @return SheetPicture
     */
    private static function sheet(Worksheet $sheet): array
    {
        $merges = array_values($sheet->getMergeCells());
        sort($merges);
        $widths = [];

        foreach ($sheet->getColumnDimensions() as $column => $dimension) {
            if ($dimension->getWidth() >= 0) {
                $widths[$column] = round($dimension->getWidth(), 2);
            }
        }

        uksort($widths, fn (string $a, string $b) => Coordinate::columnIndexFromString($a) <=> Coordinate::columnIndexFromString($b));
        $heights = [];

        foreach ($sheet->getRowDimensions() as $row => $dimension) {
            if ($dimension->getRowHeight() >= 0) {
                $heights[$row] = round($dimension->getRowHeight(), 2);
            }
        }

        ksort($heights);
        $cells = [];

        foreach ($sheet->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);

            if ($cell->getMergeRange() !== false && ! $cell->isMergeRangeValueCell()) {
                continue;
            }

            $picture = self::cell($cell, $sheet->getShowGridlines());

            if ($picture !== []) {
                $cells[$coordinate] = $picture;
            }
        }

        uksort($cells, function (string $a, string $b): int {
            [$columnA, $rowA] = Coordinate::coordinateFromString($a);
            [$columnB, $rowB] = Coordinate::coordinateFromString($b);

            return [(int) $rowA, Coordinate::columnIndexFromString($columnA)] <=> [(int) $rowB, Coordinate::columnIndexFromString($columnB)];
        });

        return ['state' => $sheet->getSheetState(), 'gridlines' => $sheet->getShowGridlines(), 'merges' => $merges, 'widths' => $widths, 'heights' => $heights, 'cells' => $cells];
    }

    /**
     * @return Picture
     */
    private static function cell(Cell $cell, bool $gridlines): array
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        $picture = [];

        if ($value !== null && $value !== '') {
            $picture['value'] = is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
        }

        if ($cell->hasHyperlink()) {
            $picture['link'] = $cell->getHyperlink()->getUrl();
        }

        return $picture + self::style($cell->getStyle(), $gridlines);
    }

    /**
     * @return Picture
     */
    private static function style(Style $style, bool $gridlines): array
    {
        $picture = [];

        if ($style->getNumberFormat()->getFormatCode() !== 'General') {
            $picture['format'] = $style->getNumberFormat()->getFormatCode();
        }

        $font = $style->getFont();
        $fontPicture = array_filter([
            'bold' => $font->getBold(), 'italic' => $font->getItalic(), 'underline' => $font->getUnderline() !== 'none' ? $font->getUnderline() : null,
            'size' => (float) $font->getSize() !== 11.0 ? (float) $font->getSize() : null,
            'name' => ! in_array($font->getName(), ['Calibri', null, ''], true) ? $font->getName() : null,
            'color' => ! in_array($font->getColor()->getARGB(), ['FF000000', null, ''], true) ? $font->getColor()->getARGB() : null,
        ]);

        if ($fontPicture !== []) {
            $picture['font'] = $fontPicture;
        }

        $fill = $style->getFill();
        $type = $fill->getFillType();

        if ($type !== null && $type !== Fill::FILL_NONE && ! ($type === Fill::FILL_SOLID && $fill->getStartColor()->getARGB() === 'FFFFFFFF' && ! $gridlines)) {
            $picture['fill'] = $type.' '.$fill->getStartColor()->getARGB();
        }

        $borders = [];

        foreach (['top' => $style->getBorders()->getTop(), 'right' => $style->getBorders()->getRight(), 'bottom' => $style->getBorders()->getBottom(), 'left' => $style->getBorders()->getLeft()] as $side => $border) {
            if ($border->getBorderStyle() !== Border::BORDER_NONE) {
                $borders[$side] = $border->getBorderStyle().' '.$border->getColor()->getARGB();
            }
        }

        if ($borders !== []) {
            $picture['border'] = $borders;
        }

        $alignment = $style->getAlignment();
        $alignmentPicture = array_filter([
            'horizontal' => $alignment->getHorizontal() !== 'general' ? $alignment->getHorizontal() : null,
            'vertical' => $alignment->getVertical() !== 'bottom' ? $alignment->getVertical() : null,
            'wrap' => $alignment->getWrapText(),
        ]);

        if ($alignmentPicture !== []) {
            $picture['alignment'] = $alignmentPicture;
        }

        return $picture;
    }
}
