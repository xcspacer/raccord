<?php

namespace App\Audit\Recheck;

use App\Enums\AuditSpliceType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the splice lines of a box plan. The sheet used is « RECNOK » when the plan was already rechecked, else
 * « BOITE », else the first one. The header is the last row (among the first 15) with « TYPE CONNEXION » in E and
 * « TUBE » in C, merged cells reading the value of their range; lines follow until the first one without tube and fibre A. Columns: A cassette, B cable A, C tube A,
 * D fibre A, E type (empty = waiting), F fibre B, G tube B, H cable B, I cassette number. The type is kept as an
 * AuditSpliceType value ('' for a word that is not a known type, whose plan word is kept to be written back as is).
 *
 * @phpstan-type PlanLine array{row: int, cassette: string, cable_a: string, tube_a: string, fibre_a: string, type: string, plan_word: string, fibre_b: string, tube_b: string, cable_b: string, cassette_number: string}
 */
class RecheckPlanReader
{
    private const int HEADER_SEARCH = 15;

    /**
     * @return list<PlanLine>
     *
     * @throws RecheckPlanException
     */
    public function read(string $path, bool $original = false): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $book = $reader->load($path);
        } catch (\Throwable) {
            throw new RecheckPlanException('unreadable');
        }

        $sheet = ($original ? null : $this->sheetNamed($book->getAllSheets(), 'RECNOK')) ?? $this->sheetNamed($book->getAllSheets(), 'BOITE') ?? $book->getSheet(0);
        $lines = $this->lines($sheet);
        $book->disconnectWorksheets();

        if ($lines === []) {
            throw new RecheckPlanException('empty');
        }

        return $lines;
    }

    /**
     * @param  list<Worksheet>  $sheets
     */
    private function sheetNamed(array $sheets, string $name): ?Worksheet
    {
        foreach ($sheets as $sheet) {
            if (strcasecmp(trim($sheet->getTitle()), $name) === 0) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @return list<PlanLine>
     */
    private function lines(Worksheet $sheet): array
    {
        $last = $sheet->getHighestRow();
        $header = $this->headerRow($sheet);

        if ($header === 0) {
            throw new RecheckPlanException('format');
        }

        $lines = [];

        for ($row = $header + 1; $row <= $last; $row++) {
            $tube = $this->text($sheet, 3, $row);
            $fibre = $this->text($sheet, 4, $row);

            if ($tube === '' && $fibre === '') {
                if ($lines !== []) {
                    break;
                }

                continue;
            }

            $word = mb_strtoupper($this->text($sheet, 5, $row));
            $type = AuditSpliceType::fromPlanWord($word);
            $lines[] = [
                'row' => $row, 'cassette' => $this->text($sheet, 1, $row), 'cable_a' => $this->text($sheet, 2, $row), 'tube_a' => $tube, 'fibre_a' => $fibre,
                'type' => $type->value ?? '', 'plan_word' => $type?->planWord() ?? $word, 'fibre_b' => $this->text($sheet, 6, $row), 'tube_b' => $this->text($sheet, 7, $row),
                'cable_b' => $this->text($sheet, 8, $row), 'cassette_number' => $this->text($sheet, 9, $row),
            ];
        }

        return $lines;
    }

    /**
     * Row of the header (0 when the sheet has not the expected layout).
     */
    public function headerRow(Worksheet $sheet): int
    {
        $header = 0;

        for ($row = 1; $row <= min($sheet->getHighestRow(), self::HEADER_SEARCH); $row++) {
            if (stripos($this->text($sheet, 5, $row), 'TYPE CONNEXION') !== false && stripos($this->text($sheet, 3, $row), 'TUBE') !== false) {
                $header = $row;
            }
        }

        return $header;
    }

    /**
     * Text of a cell; a cell inside a merged range reads the range's value (the client's plans merge the two header
     * rows and often the cable A of a whole cassette).
     */
    private function text(Worksheet $sheet, int $column, int $row): string
    {
        $cell = $sheet->getCell([$column, $row]);
        $range = $cell->getMergeRange();

        if ($range !== false) {
            $cell = $sheet->getCell(strtok($range, ':'));
        }

        $value = $cell->getCalculatedValue();

        return trim(str_replace(['_x000D_', "\r"], '', (string) $value));
    }
}
