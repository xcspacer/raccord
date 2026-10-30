<?php

namespace App\ClientPortal;

use Carbon\CarbonImmutable;

/**
 * Geometry of the portal's small SVG charts, so views only draw what is computed here.
 *
 * @phpstan-type Ring array{size: int, centre: float, radius: float, circumference: string, offset: string, font: float}
 * @phpstan-type Segment array{status: string, x: string, width: string, count: int}
 * @phpstan-type Bar array{day: string, label: string, count: int, x: string, y: string, width: string, height: string, centre: string}
 * @phpstan-type DayChart array{width: int, height: int, grid: list<array{y: string, value: int}>, bars: list<Bar>, left: int}
 */
class PortalCharts
{
    /**
     * @return Ring
     */
    public function ring(int $percent, int $size = 92): array
    {
        $radius = ($size - 12) / 2;
        $circumference = 2 * M_PI * $radius;

        return [
            'size' => $size,
            'centre' => $size / 2,
            'radius' => $radius,
            'circumference' => number_format($circumference, 1, '.', ''),
            'offset' => number_format($circumference * (1 - $percent / 100), 1, '.', ''),
            'font' => $size / 4,
        ];
    }

    /**
     * Stacked bar of a bucket, one segment per status present, in percent of the bar width.
     *
     * @param  array<string, int>  $bucket
     * @return list<Segment>
     */
    public function stack(array $bucket): array
    {
        $total = max(1, $bucket['total'] ?? 0);
        $segments = [];
        $x = 0.0;

        foreach (PortalOverview::STATUSES as $status) {
            $count = $bucket[$status] ?? 0;

            if ($count === 0) {
                continue;
            }

            $width = $count * 100 / $total;
            $segments[] = ['status' => $status, 'x' => number_format($x, 2, '.', ''), 'width' => number_format($width, 2, '.', ''), 'count' => $count];
            $x += $width;
        }

        return $segments;
    }

    /**
     * Boxes finished per day: from the first day with production (60 days back at most) to today, weekends hidden
     * unless something was finished, the last 14 days shown.
     *
     * @param  array<string, int>  $perDay
     * @return DayChart|null
     */
    public function days(array $perDay, CarbonImmutable $today): ?array
    {
        if ($perDay === []) {
            return null;
        }

        $days = [];
        $end = $today->startOfDay();
        $day = CarbonImmutable::parse(array_key_first($perDay), $today->getTimezone())->startOfDay()->max($end->subDays(59));

        while ($day->lte($end) && count($days) < 60) {
            $days[] = ['day' => $day, 'count' => $perDay[$day->toDateString()] ?? 0];
            $day = $day->addDay();
        }

        $shown = array_slice(array_values(array_filter($days, fn (array $entry) => ! $entry['day']->isWeekend() || $entry['count'] > 0)), -14);
        $max = max(1, ...array_map(fn (array $entry) => $entry['count'], $shown));
        [$width, $height, $left, $bottom] = [560, 190, 28, 26];
        $barWidth = ($width - $left) / max(1, count($shown));
        $plot = $height - $bottom - 14;

        $grid = array_map(fn (float $fraction) => ['y' => number_format(($height - $bottom) - $plot * $fraction, 1, '.', ''), 'value' => (int) round($max * $fraction)], [0, 0.5, 1]);
        $bars = [];

        foreach ($shown as $index => $entry) {
            $barHeight = $plot * $entry['count'] / $max;
            $x = $left + $index * $barWidth + $barWidth * 0.18;
            $w = $barWidth * 0.64;

            $bars[] = [
                'day' => $entry['day']->toDateString(),
                'label' => $entry['day']->format('d/m'),
                'count' => $entry['count'],
                'x' => number_format($x, 1, '.', ''),
                'y' => number_format($height - $bottom - $barHeight, 1, '.', ''),
                'width' => number_format($w, 1, '.', ''),
                'height' => number_format(max($barHeight, $entry['count'] > 0 ? 2 : 0), 1, '.', ''),
                'centre' => number_format($x + $w / 2, 1, '.', ''),
            ];
        }

        return ['width' => $width, 'height' => $height, 'grid' => $grid, 'bars' => $bars, 'left' => $left];
    }

    /**
     * « Today 14:05 » or « 03/11 14:05 », in the company's time zone.
     */
    public function time(?CarbonImmutable $at, CarbonImmutable $now): string
    {
        if ($at === null) {
            return '—';
        }

        $local = $at->setTimezone(config('platform.timezone'));
        $today = $now->setTimezone(config('platform.timezone'));

        return ($local->isSameDay($today) ? __('client_portal.today') : $local->format('d/m')).' '.$local->format('H:i');
    }

    /**
     * Same as time() without « Today » for today's events.
     */
    public function shortTime(?CarbonImmutable $at, CarbonImmutable $now): string
    {
        if ($at === null) {
            return '—';
        }

        $local = $at->setTimezone(config('platform.timezone'));

        return $local->isSameDay($now->setTimezone(config('platform.timezone'))) ? $local->format('H:i') : $local->format('d/m H:i');
    }

    public function ago(?CarbonImmutable $at, CarbonImmutable $now): string
    {
        if ($at === null) {
            return '';
        }

        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < 60 => __('client_portal.ago.now'),
            $seconds < 3600 => __('client_portal.ago.minutes', ['count' => (int) round($seconds / 60)]),
            $seconds < 86400 => __('client_portal.ago.hours', ['count' => (int) round($seconds / 3600)]),
            default => __('client_portal.ago.days', ['count' => (int) round($seconds / 86400)]),
        };
    }
}
