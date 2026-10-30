<?php

namespace App\Audit\Drawer;

/**
 * The rules of a drawer plan, with no I/O. A drawer holds modules (A, B, C…) of positions (one fibre each); a position
 * is written « B2-T<drawer>-M<module>-<from> à <to> ». A tube is a colour (1 to 12) and a number of rings: tube 24 is
 * the 12th colour with one ring. The fibre of a position in its tube follows from its place in the module.
 *
 * @phpstan-type Cell array{pb: string, cable: string, tube: int, fibre: int}
 * @phpstan-type Cells array<string, Cell>
 * @phpstan-type Segment array{t: int, L: string, a: int, b: int, pb: string, cable: string, tube: int, f1: int, f2: int}
 * @phpstan-type Drawer array{n: int, npos: int, mods: list<array{L: string, row: int}>}
 */
class DrawerPlan
{
    public const array CAPACITIES = [12, 24, 36, 48, 72, 96, 144, 288, 432, 576, 720];

    public static function key(int $drawer, string $module, int $position): string
    {
        return $drawer.'|'.$module.'|'.$position;
    }

    public static function colourOfTube(int $tube): int
    {
        return (($tube - 1) % 12) + 1;
    }

    public static function ringsOfTube(int $tube): int
    {
        return intdiv($tube - 1, 12);
    }

    public static function tubeName(int $tube): string
    {
        if ($tube <= 0) {
            return '—';
        }

        $name = __('enums.audit_recheck_colours.'.config('audit.recheck_palette')[self::colourOfTube($tube) - 1]['key']);

        return self::ringsOfTube($tube) > 0 ? $name.' '.__('audit.drawer.ring', ['n' => self::ringsOfTube($tube)]) : $name;
    }

    /**
     * Fibre in the tube given by the position in the module.
     */
    public static function fibreOf(int $position): int
    {
        return (($position - 1) % 12) + 1;
    }

    /**
     * 0 for positions 1 to 12 (first tube), 1 for 13 to 24 (second tube).
     */
    public static function slotOf(int $position): int
    {
        return intdiv($position - 1, 12);
    }

    public static function code(int $drawer, string $module, int $from, int $to): string
    {
        return sprintf('B2-T%d-M%s-%02d à %02d', $drawer, $module, $from, $to);
    }

    public static function pad(int $number): string
    {
        return str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{prefix: string, cap: int, origin: string, dest: string}|null
     */
    public static function parseCable(string $label): ?array
    {
        if (preg_match('/^(.*?)\s+(\d+)\s*Fo\s*-\s*A\s+(.+?)\s+O\s+(.+?)\s+E$/iu', trim($label), $match) !== 1) {
            return null;
        }

        return ['prefix' => trim($match[1]), 'cap' => (int) $match[2], 'origin' => trim($match[3]), 'dest' => trim($match[4])];
    }

    public static function buildCable(string $prefix, int $capacity, string $origin, string $destination): string
    {
        return "$prefix $capacity Fo - A $origin O $destination E";
    }

    public static function shortCable(string $label): string
    {
        $parsed = self::parseCable($label);

        return $parsed === null ? ($label !== '' ? $label : '—') : $parsed['cap'].' Fo → '.$parsed['dest'];
    }

    /**
     * @param  Cell|null  $a
     * @param  Cell|null  $b
     */
    public static function sameCell(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && $a['pb'] === $b['pb'] && $a['cable'] === $b['cable'] && $a['tube'] === $b['tube'] && $a['fibre'] === $b['fibre'];
    }

    /**
     * Neighbouring positions of a module with the same PBO, cable and tube and consecutive fibres, in one drawer.
     *
     * @param  list<Drawer>  $drawers
     * @param  Cells  $cells
     * @return list<Segment>
     */
    public static function segments(array $drawers, array $cells, int $drawer): array
    {
        $found = collect($drawers)->firstWhere('n', $drawer);
        $out = [];

        if ($found === null) {
            return $out;
        }

        foreach ($found['mods'] as $module) {
            $current = null;

            for ($position = 1; $position <= $found['npos']; $position++) {
                $cell = $cells[self::key($drawer, $module['L'], $position)] ?? ['pb' => '', 'cable' => '', 'tube' => 0, 'fibre' => 0];
                $same = $cell['pb'].'|'.$cell['cable'].'|'.$cell['tube'];
                $fibre = $cell['fibre'] ?: self::fibreOf($position);

                if ($current !== null && $current['k'] === $same && $fibre === $current['f2'] + 1) {
                    $current['b'] = $position;
                    $current['f2'] = $fibre;
                } else {
                    if ($current !== null) {
                        $out[] = $current;
                    }

                    $current = ['k' => $same, 't' => $drawer, 'L' => $module['L'], 'a' => $position, 'b' => $position, 'pb' => $cell['pb'], 'cable' => $cell['cable'], 'tube' => $cell['tube'], 'f1' => $fibre, 'f2' => $fibre];
                }
            }

            if ($current !== null) {
                $out[] = $current;
            }
        }

        return array_map(fn (array $segment) => array_diff_key($segment, ['k' => 0]), $out);
    }

    /**
     * All the segments, grouped by PBO name (free positions under « »).
     *
     * @param  list<Drawer>  $drawers
     * @param  Cells  $cells
     * @return array<string, list<Segment>>
     */
    public static function byPbo(array $drawers, array $cells): array
    {
        $by = [];

        foreach ($drawers as $drawer) {
            foreach (self::segments($drawers, $cells, $drawer['n']) as $segment) {
                $by[$segment['pb']][] = $segment;
            }
        }

        return $by;
    }

    /**
     * @param  list<Segment>|null  $segments
     */
    public static function signature(?array $segments): string
    {
        return implode(';', array_map(fn (array $s) => $s['t'].$s['L'].$s['a'].'-'.$s['b'].'|'.$s['cable'].'|'.$s['tube'].'|'.$s['f1'], $segments ?? []));
    }

    /**
     * Short label of a PBO for the mock-up cells.
     */
    public static function shortName(string $pbo): string
    {
        if (preg_match('/PB0*(\d+)(JD)?$/i', $pbo, $m) === 1) {
            return 'PB'.$m[1].($m[2] ?? '');
        }

        if (preg_match('/BPE-?0*(\d+)/i', $pbo, $m) === 1) {
            return 'BPE'.$m[1];
        }

        return preg_match('/-0*(\d+)$/', $pbo, $m) === 1 ? $m[1] : mb_substr($pbo, 0, 8);
    }
}
