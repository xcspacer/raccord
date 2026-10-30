<?php

namespace App\Audit\Drawer;

use App\Audit\Recheck\RecheckPlanException;
use App\Support\SheetText;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the RECNOK workbook of the drawer plan: the original plan, never touched, plus « PB RECNOK » (after PB) and
 * « CABLE RECNOK » (after CABLE) with what was found, changed cells in bold red and the colour bar of a changed tube,
 * and a « POSITIONS PBO » sheet the design office reads: one line per PBO and segment.
 *
 * @phpstan-import-type Cells from DrawerPlan
 * @phpstan-import-type Drawer from DrawerPlan
 */
class DrawerWriter
{
    private const string RED = 'FFFF0000';

    public function __construct(private DrawerReader $reader) {}

    /**
     * @param  list<Drawer>  $drawers
     * @param  Cells  $original
     * @param  Cells  $current
     * @param  list<string>  $confirmed
     *
     * @throws RecheckPlanException
     */
    public function write(string $originalPath, array $drawers, array $original, array $current, array $confirmed): string
    {
        try {
            $book = IOFactory::createReader('Xlsx')->load($originalPath);
        } catch (\Throwable) {
            throw new RecheckPlanException('unreadable');
        }

        foreach (['PB RECNOK', 'CABLE RECNOK', 'POSITIONS PBO'] as $stale) {
            $old = $this->reader->sheet($book, $stale);

            if ($old !== null) {
                $book->removeSheetByIndex($book->getIndex($old));
            }
        }

        $pb = $this->reader->sheet($book, 'PB');
        $cable = $this->reader->sheet($book, 'CABLE');

        if ($pb === null || $cable === null) {
            throw new RecheckPlanException('format');
        }

        $cableCopy = clone $cable;
        $cableCopy->setTitle('CABLE RECNOK');
        $book->addSheet($cableCopy, $book->getIndex($cable) + 1);
        $pbCopy = clone $pb;
        $pbCopy->setTitle('PB RECNOK');
        $book->addSheet($pbCopy, $book->getIndex($pb) + 1);

        foreach ($drawers as $drawer) {
            foreach ($drawer['mods'] as $module) {
                for ($position = 1; $position <= $drawer['npos']; $position++) {
                    $key = DrawerPlan::key($drawer['n'], $module['L'], $position);
                    $now = $current[$key];
                    $was = $original[$key];
                    $column = 2 + 2 * $position;

                    if ($now['pb'] !== $was['pb']) {
                        $cell = $pbCopy->getCell([$column, $module['row']]);
                        $cell->setValueExplicit($now['pb'], 's');
                        $cell->getStyle()->getFont()->setBold(true)->getColor()->setARGB(self::RED);
                    }

                    if ($now['cable'] !== $was['cable'] || $now['tube'] !== $was['tube'] || $now['fibre'] !== $was['fibre']) {
                        $cell = $cableCopy->getCell([$column, $module['row']]);
                        $cell->setValueExplicit($now['cable'] !== '' ? $now['cable']."\r\nT".DrawerPlan::pad($now['tube']).' F'.DrawerPlan::pad($now['fibre']) : '', 's');
                        $cell->getStyle()->getFont()->setBold(true)->getColor()->setARGB(self::RED);

                        if ($now['tube'] !== $was['tube'] && $now['tube'] > 0) {
                            $hex = config('audit.recheck_palette')[DrawerPlan::colourOfTube($now['tube']) - 1]['hex'];

                            foreach ([$pbCopy, $cableCopy] as $sheet) {
                                $sheet->getCell([$column - 1, $module['row']])->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF'.$hex);
                            }
                        }
                    }
                }
            }
        }

        $this->summary($book, $drawers, $original, $current, $confirmed);
        $book->setActiveSheetIndex($book->getIndex($pb));
        $path = (string) tempnam(sys_get_temp_dir(), 'drawer');

        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }

    /**
     * @param  list<Drawer>  $drawers
     * @param  Cells  $original
     * @param  Cells  $current
     * @param  list<string>  $confirmed
     */
    private function summary(Spreadsheet $book, array $drawers, array $original, array $current, array $confirmed): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('POSITIONS PBO');
        $sheet->fromArray([__('audit.drawer.sheet.pbo'), __('audit.drawer.sheet.position'), __('audit.drawer.sheet.drawer'), __('audit.drawer.sheet.module'), __('audit.drawer.sheet.from'), __('audit.drawer.sheet.to'), __('audit.drawer.sheet.cable'), __('audit.drawer.sheet.tube'), __('audit.drawer.sheet.tube_colour'), __('audit.drawer.sheet.fibres'), __('audit.drawer.sheet.status')], null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $now = DrawerPlan::byPbo($drawers, $current);
        $was = DrawerPlan::byPbo($drawers, $original);
        $names = array_values(array_filter(array_keys($now), fn (string $name) => $name !== ''));
        natcasesort($names);
        $row = 2;

        foreach ($names as $name) {
            $status = DrawerPlan::signature($now[$name]) !== DrawerPlan::signature($was[$name] ?? null) ? 'modified' : (in_array($name, $confirmed, true) ? 'conform' : 'unchecked');

            foreach ($now[$name] as $segment) {
                SheetText::row($sheet, [
                    $name, DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']), $segment['t'], $segment['L'], $segment['a'], $segment['b'],
                    DrawerPlan::parseCable($segment['cable']) !== null ? DrawerPlan::shortCable($segment['cable']).'  ['.$segment['cable'].']' : $segment['cable'],
                    'T'.DrawerPlan::pad($segment['tube']), DrawerPlan::tubeName($segment['tube']), 'F'.DrawerPlan::pad($segment['f1']).($segment['f2'] > $segment['f1'] ? '–F'.DrawerPlan::pad($segment['f2']) : ''),
                    __('audit.drawer.status.'.$status),
                ], $row);
                $row++;
            }
        }

        foreach (['A' => 14, 'B' => 22, 'C' => 8, 'D' => 8, 'E' => 6, 'F' => 6, 'G' => 70, 'H' => 8, 'I' => 18, 'J' => 12, 'K' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }
}
