<?php

namespace App\TechnicianSpace;

use Carbon\CarbonImmutable;

/**
 * Text of the « Retour journalier » e-mail sent to the client at the end of the day: the works done on each
 * box and the number of welds. The box reference is collected but, as in the legacy e-mail, not printed.
 *
 * @phpstan-type Box array{reference: string, works: string, cables: list<array{description: string, tubes: string}>, welds: string}
 */
class DailyReturnEmail
{
    /**
     * @param  list<Box>  $boxes
     */
    public function build(CarbonImmutable $date, string $team, array $boxes): string
    {
        $lines = [__('technician_space.email.greeting'), '', $date->translatedFormat('d/m/y'), '', __('technician_space.email.team', ['team' => $team])];
        $total = 0;

        foreach ($boxes as $index => $box) {
            $welds = trim($box['welds']) !== '' ? (int) $box['welds'] : $this->tubes($box);
            $total += $welds;

            $lines[] = '';
            $lines[] = __('technician_space.email.works_done');

            foreach (array_filter(array_map('trim', explode("\n", $box['works']))) as $line) {
                $lines[] = '• '.$line;
            }

            foreach ($box['cables'] as $cable) {
                if (trim($cable['description']) !== '') {
                    $lines[] = '• '.__('technician_space.email.cable', ['cable' => trim($cable['description'])]);
                }
            }

            $lines[] = '';
            $lines[] = __('technician_space.email.welds', ['count' => $welds]);

            if ($index < count($boxes) - 1) {
                $lines[] = '';
                $lines[] = str_repeat('─', 32);
            }
        }

        if (count($boxes) > 1) {
            $lines[] = '';
            $lines[] = __('technician_space.email.welds_day', ['count' => $total]);
        }

        return implode("\n", [...$lines, '', __('technician_space.email.done'), '', __('technician_space.email.regards')]);
    }

    /**
     * Sum of the tubes of the cables of a box (the number of welds when none is typed).
     *
     * @param  Box  $box
     */
    private function tubes(array $box): int
    {
        return (int) array_sum(array_map(fn (array $cable) => (int) $cable['tubes'], $box['cables']));
    }
}
