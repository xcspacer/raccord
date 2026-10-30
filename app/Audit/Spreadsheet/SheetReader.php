<?php

namespace App\Audit\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A workbook turned into plain data for the plan viewer: visible sheets, column widths, row heights, merged cells and
 * the look of each cell (colours, bold, alignment, borders). Styles are listed once and cells point to them.
 *
 * @phpstan-type CellStyle array{bg: string|null, color: string|null, bold: bool, italic: bool, size: float|null, align: string|null, valign: string|null, wrap: bool, borders: list<string>}
 * @phpstan-type SheetData array{name: string, columns: list<array{width: int, hidden: bool}>, rows: list<array{height: int|null, hidden: bool}>, merges: list<array{int, int, int, int}>, cells: list<list<array{v: string, s: int}|null>>}
 */
class SheetReader
{
    public const int MAX_ROWS = 400;

    public const int MAX_COLUMNS = 40;

    /** @var list<CellStyle> */
    private array $styles = [];

    /** @var array<string, int> */
    private array $styleIndex = [];

    /**
     * @return array{sheets: list<SheetData>, styles: list<CellStyle>}
     */
    public function read(string $path): array
    {
        $this->styles = $this->styleIndex = [];
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);
        $book = $reader->load($path);
        $sheets = [];

        foreach ($book->getAllSheets() as $sheet) {
            if ($sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE) {
                $sheets[] = $this->sheet($sheet);
            }
        }

        $book->disconnectWorksheets();

        return ['sheets' => $sheets, 'styles' => $this->styles];
    }

    /**
     * @return SheetData
     */
    private function sheet(Worksheet $sheet): array
    {
        $ranges = array_map(fn (string $range) => Coordinate::rangeBoundaries($range), array_values($sheet->getMergeCells()));
        $lastRow = min(max([$sheet->getHighestRow(), ...array_map(fn (array $range) => (int) $range[1][1], $ranges)]), self::MAX_ROWS);
        $lastColumn = min(max([Coordinate::columnIndexFromString($sheet->getHighestColumn()), ...array_map(fn (array $range) => (int) $range[1][0], $ranges)]), self::MAX_COLUMNS);
        $columns = [];
        $rows = [];
        $cells = [];

        for ($column = 1; $column <= $lastColumn; $column++) {
            $dimension = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column));
            $width = $dimension->getWidth();
            $columns[] = ['width' => (int) round(($width > 0 ? $width : 8.43) * 7 + 5), 'hidden' => ! $dimension->getVisible()];
        }

        for ($row = 1; $row <= $lastRow; $row++) {
            $dimension = $sheet->getRowDimension($row);
            $height = $dimension->getRowHeight();
            $rows[] = ['height' => $height > 0 ? (int) round($height * 1.333) : null, 'hidden' => ! $dimension->getVisible()];
            $line = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $cell = $sheet->getCell([$column, $row]);
                $line[] = ['v' => (string) $cell->getFormattedValue(), 's' => $this->styleId($cell->getStyle())];
            }

            $cells[] = $line;
        }

        $merges = [];

        foreach ($ranges as [[$firstColumn, $firstRow], [$endColumn, $endRow]]) {

            if ($firstRow <= $lastRow && $firstColumn <= $lastColumn) {
                $merges[] = [$firstRow, $firstColumn, min($endRow, $lastRow), min($endColumn, $lastColumn)];
            }
        }

        return ['name' => $sheet->getTitle(), 'columns' => $columns, 'rows' => $rows, 'merges' => $merges, 'cells' => $cells];
    }

    private function styleId(Style $style): int
    {
        $fill = $style->getFill();
        $font = $style->getFont();
        $alignment = $style->getAlignment();
        $borders = [];

        foreach (['top' => $style->getBorders()->getTop(), 'right' => $style->getBorders()->getRight(), 'bottom' => $style->getBorders()->getBottom(), 'left' => $style->getBorders()->getLeft()] as $side => $border) {
            if ($border->getBorderStyle() !== Border::BORDER_NONE) {
                $borders[] = $side;
            }
        }

        $data = [
            'bg' => $fill->getFillType() === Fill::FILL_SOLID ? $this->colour($fill->getStartColor()->getRGB()) : null,
            'color' => $this->colour($font->getColor()->getRGB()),
            'bold' => (bool) $font->getBold(),
            'italic' => (bool) $font->getItalic(),
            'size' => $font->getSize() !== null ? (float) $font->getSize() : null,
            'align' => in_array($alignment->getHorizontal(), [Alignment::HORIZONTAL_LEFT, Alignment::HORIZONTAL_CENTER, Alignment::HORIZONTAL_RIGHT], true) ? $alignment->getHorizontal() : null,
            'valign' => in_array($alignment->getVertical(), [Alignment::VERTICAL_TOP, Alignment::VERTICAL_CENTER, Alignment::VERTICAL_BOTTOM], true) ? $alignment->getVertical() : null,
            'wrap' => (bool) $alignment->getWrapText(),
            'borders' => $borders,
        ];

        $key = md5((string) json_encode($data));

        if (! isset($this->styleIndex[$key])) {
            $this->styleIndex[$key] = count($this->styles);
            $this->styles[] = $data;
        }

        return $this->styleIndex[$key];
    }

    private function colour(string $rgb): ?string
    {
        return preg_match('/^[0-9A-Fa-f]{6}$/', $rgb) === 1 ? '#'.strtolower($rgb) : null;
    }
}
