<?php

namespace App\Audit\Drawer;

use App\Audit\Recheck\RecheckPlanException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads a drawer plan: sheet « PB » (which PBO occupies each position) and sheet « CABLE » (which cable, tube and
 * fibre reaches it). A drawer starts on the row with « Tiroir : n » in column B; its positions are the numbers of the
 * next row in columns D, F, H…; its modules are the rows below with a single letter in column B. The position p is in
 * column 2 + 2p. In the CABLE sheet a cell is the cable on the first line and « Tnn Fnn » on the second.
 *
 * @phpstan-import-type Cells from DrawerPlan
 * @phpstan-import-type Drawer from DrawerPlan
 */
class DrawerReader
{
    private const int MAX_COLUMN = 120;

    /**
     * @return array{drawers: list<Drawer>, cells: Cells}
     *
     * @throws RecheckPlanException
     */
    public function read(string $path, bool $recnok = false): array
    {
        try {
            $book = IOFactory::createReader('Xlsx')->load($path);
        } catch (\Throwable) {
            throw new RecheckPlanException('unreadable');
        }

        $pb = $this->sheet($book, $recnok ? 'PB RECNOK' : 'PB');
        $cable = $this->sheet($book, $recnok ? 'CABLE RECNOK' : 'CABLE');

        if ($pb === null || $cable === null) {
            throw new RecheckPlanException('format');
        }

        $parsed = $this->parse($pb, $cable);
        $book->disconnectWorksheets();

        if ($parsed['drawers'] === []) {
            throw new RecheckPlanException('empty');
        }

        return $parsed;
    }

    public function hasRecnokSheets(string $path): bool
    {
        try {
            $book = IOFactory::createReader('Xlsx')->load($path);
        } catch (\Throwable) {
            return false;
        }

        $has = $this->sheet($book, 'PB RECNOK') !== null && $this->sheet($book, 'CABLE RECNOK') !== null;
        $book->disconnectWorksheets();

        return $has;
    }

    public function sheet(Spreadsheet $book, string $name): ?Worksheet
    {
        foreach ($book->getAllSheets() as $sheet) {
            if (strtoupper(trim($sheet->getTitle())) === $name) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @return array{drawers: list<Drawer>, cells: Cells}
     */
    private function parse(Worksheet $pb, Worksheet $cable): array
    {
        $drawers = [];
        $cells = [];
        $last = $pb->getHighestRow();

        for ($row = 1; $row <= $last; $row++) {
            if (preg_match('/^Tiroir\s*:?\s*(\d+)/i', $this->text($pb, 2, $row), $match) !== 1) {
                continue;
            }

            $drawer = ['n' => (int) $match[1], 'npos' => 0, 'mods' => []];

            for ($column = 4; $column <= self::MAX_COLUMN; $column += 2) {
                if (preg_match('/^\d+$/', $this->text($pb, $column, $row + 1)) !== 1) {
                    break;
                }

                $drawer['npos']++;
            }

            for ($module = $row + 2; $module <= $last; $module++) {
                $letter = $this->text($pb, 2, $module);

                if (preg_match('/^[A-Z]$/', $letter) !== 1) {
                    break;
                }

                $drawer['mods'][] = ['L' => $letter, 'row' => $module];
            }

            if ($drawer['npos'] === 0 || $drawer['mods'] === []) {
                continue;
            }

            $drawers[] = $drawer;

            foreach ($drawer['mods'] as $module) {
                for ($position = 1; $position <= $drawer['npos']; $position++) {
                    $column = 2 + 2 * $position;
                    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $this->raw($cable, $column, $module['row'])) ?: [])));
                    $has = preg_match('/T\s*(\d+)\s*F\s*(\d+)/i', $lines[1] ?? '', $tf) === 1;
                    $cells[DrawerPlan::key($drawer['n'], $module['L'], $position)] = ['pb' => $this->text($pb, $column, $module['row']), 'cable' => $lines[0] ?? '', 'tube' => $has ? (int) $tf[1] : 0, 'fibre' => $has ? (int) $tf[2] : 0];
                }
            }
        }

        return ['drawers' => $drawers, 'cells' => $cells];
    }

    private function raw(Worksheet $sheet, int $column, int $row): string
    {
        return str_replace('_x000D_', "\r", (string) $sheet->getCell([$column, $row])->getCalculatedValue());
    }

    private function text(Worksheet $sheet, int $column, int $row): string
    {
        return trim($this->raw($sheet, $column, $row));
    }
}
