<?php

namespace App\Expenses;

use App\Models\ExpenseLine;
use Dompdf\FontMetrics;
use Illuminate\Support\Collection;

/**
 * Geometry of the expense report PDF, as on the paper form: at least 17 rows, comments cut to two lines, rows shrunk
 * by steps so that every line fits on the first page, and all the receipts on one second page.
 *
 * @phpstan-type Row array{line: ExpenseLine|null, comment: list<string>, double: bool}
 */
class ExpenseReportLayout
{
    public const int MINIMUM_ROWS = 17;

    /** Height of a row in mm, with one line of text or two (a long comment or nature). */
    private const float ROW_HEIGHT = 6.9;

    private const float DOUBLE_ROW_HEIGHT = 9.6;

    /** Room left on the first page for the rows, in mm, and what the document footer takes from it. */
    private const float ROWS_AREA = 145.5;

    private const float FOOTER_HEIGHT = 8.0;

    /** Steps (in %) the rows can be shrunk to; each one has its own CSS modifier. */
    private const int SMALLEST_FIT = 40;

    /** Width of the comment and nature columns inside their padding, in mm, and the size text is measured with, in pt. */
    private const float COMMENT_WIDTH = 66.0;

    private const float NATURE_WIDTH = 53.0;

    private const float FONT_SIZE = 8.5;

    private const int COMMENT_LINES = 2;

    /** Receipts per row of the second page, by number of receipts (up to 1, 4, 9, then more). */
    private const array PROOF_COLUMNS = [1 => 1, 4 => 2, 9 => 3];

    private ?FontMetrics $metrics = null;

    /**
     * The printed rows: the lines then empty rows up to the minimum.
     *
     * @param  Collection<int, ExpenseLine>  $lines
     * @return list<Row>
     */
    public function rows(Collection $lines): array
    {
        $rows = $lines->map(function (ExpenseLine $line): array {
            $comment = $this->wrap((string) $line->comment);

            return ['line' => $line, 'comment' => $comment, 'double' => count($comment) > 1 || ! $this->fits($line->nature->label(), self::NATURE_WIDTH)];
        })->values()->all();

        while (count($rows) < self::MINIMUM_ROWS) {
            $rows[] = ['line' => null, 'comment' => [], 'double' => false];
        }

        return $rows;
    }

    /**
     * Largest step, in %, at which the rows fit the first page (100 when nothing has to shrink).
     *
     * @param  list<Row>  $rows
     */
    public function fit(array $rows, bool $hasFooter): int
    {
        $height = 0.0;

        foreach ($rows as $row) {
            $height += $row['double'] ? self::DOUBLE_ROW_HEIGHT : self::ROW_HEIGHT;
        }

        $room = self::ROWS_AREA - ($hasFooter ? self::FOOTER_HEIGHT : 0);

        if ($height <= $room) {
            return 100;
        }

        return max(self::SMALLEST_FIT, (int) floor($room / $height * 10) * 10);
    }

    /**
     * Grid of the receipts page, so that they all fit on one A4 page.
     *
     * @return array{columns: int, rows: int}
     */
    public function proofGrid(int $photos): array
    {
        $columns = 4;

        foreach (self::PROOF_COLUMNS as $upTo => $count) {
            if ($photos <= $upTo) {
                $columns = $count;

                break;
            }
        }

        return ['columns' => $columns, 'rows' => max(1, (int) ceil($photos / $columns))];
    }

    /**
     * The comment on at most two lines of the column, a word too long for one line being cut.
     *
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->fits($candidate)) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            while (! $this->fits($word) && count($lines) < self::COMMENT_LINES) {
                $length = mb_strlen($word) - 1;

                while ($length > 1 && ! $this->fits(mb_substr($word, 0, $length))) {
                    $length--;
                }

                $lines[] = mb_substr($word, 0, $length);
                $word = mb_substr($word, $length);
            }

            $current = $word;

            if (count($lines) >= self::COMMENT_LINES) {
                break;
            }
        }

        if ($current !== '' && count($lines) < self::COMMENT_LINES) {
            $lines[] = $current;
        }

        return array_slice($lines, 0, self::COMMENT_LINES);
    }

    private function fits(string $text, float $width = self::COMMENT_WIDTH): bool
    {
        $this->metrics ??= app('dompdf')->getFontMetrics();
        $font = $this->metrics->getFont('DejaVu Sans');

        return $font !== null && $this->metrics->getTextWidth($text, $font, self::FONT_SIZE) * 25.4 / 72 <= $width;
    }
}
