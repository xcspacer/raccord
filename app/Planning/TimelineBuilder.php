<?php

namespace App\Planning;

use App\Absences\AbsenceCalendar;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Layout of the planning timeline (« frise »): the day window, header groups, one lane per overlapping
 * work site, overbooked days, today and the absences of the agency's technicians. Positions are day
 * indexes from the start of the window, never pixels.
 *
 * @phpstan-type Bar array{id: int, start: int, span: int, lane: int, status: string, crews: int, client: string, city: string, tooltip: string}
 * @phpstan-type AbsenceBar array{start: int, span: int, type: string, fits: bool, name: string, tooltip: string}
 */
class TimelineBuilder
{
    private const int MAX_DAYS = 500;

    private const int PADDING_BEFORE = 3;

    private const int PADDING_AFTER = 4;

    public function __construct(private AbsenceCalendar $absences) {}

    /**
     * @param  Collection<int, WorkSite>  $sites  Work sites to draw (already filtered).
     * @param  array<string, int>  $overbooked  Crews needed per overbooked day.
     * @param  Collection<int, User>  $technicians
     * @return array{total: int, dayWidth: int, months: list<array{label: string, span: int}>, weeks: list<array{label: string, span: int}>, days: list<array{number: int, today: bool}>, bars: list<Bar>, lanes: int, stripes: list<array{index: int, title: string}>, todayIndex: int|null, absenceRows: list<array{name: string, bars: list<AbsenceBar>}>}|null
     */
    public function build(Collection $sites, array $overbooked, int $capacity, CarbonImmutable $today, Collection $technicians): ?array
    {
        if ($sites->isEmpty()) {
            return null;
        }

        $start = CarbonImmutable::instance($sites->min('starts_on'))->startOfDay()->subDays(self::PADDING_BEFORE);
        $end = CarbonImmutable::instance($sites->max('ends_on'))->startOfDay()->addDays(self::PADDING_AFTER);
        $total = min((int) $start->diffInDays($end), self::MAX_DAYS);
        $dayWidth = $total > 200 ? 18 : ($total > 90 ? 24 : 32);
        $end = $start->addDays($total);

        [$months, $weeks, $days] = $this->header($start, $total, $today);
        [$bars, $lanes] = $this->bars($sites, $start, $total);

        $stripes = [];

        foreach ($overbooked as $date => $crews) {
            $day = CarbonImmutable::parse($date);

            if ($day->between($start, $end)) {
                $stripes[] = ['index' => (int) $start->diffInDays($day), 'title' => __('planning.overbooked_day', ['date' => $day->translatedFormat('d M'), 'crews' => $crews, 'capacity' => $capacity])];
            }
        }

        return [
            'total' => $total,
            'dayWidth' => $dayWidth,
            'months' => $months,
            'weeks' => $weeks,
            'days' => $days,
            'bars' => $bars,
            'lanes' => $lanes,
            'stripes' => $stripes,
            'todayIndex' => $today->between($start, $end) ? (int) $start->diffInDays($today) : null,
            'absenceRows' => $this->absenceRows($technicians, $start, $end, $dayWidth),
        ];
    }

    /**
     * @return array{0: list<array{label: string, span: int}>, 1: list<array{label: string, span: int}>, 2: list<array{number: int, today: bool}>}
     */
    private function header(CarbonImmutable $start, int $total, CarbonImmutable $today): array
    {
        $months = $weeks = $days = [];

        for ($index = 0; $index <= $total; $index++) {
            $day = $start->addDays($index);
            $month = $day->translatedFormat('F Y');
            $week = 'S'.$day->isoWeek;

            if ($months === [] || $months[array_key_last($months)]['label'] !== $month) {
                $months[] = ['label' => $month, 'span' => 0];
            }

            if ($weeks === [] || $weeks[array_key_last($weeks)]['label'] !== $week) {
                $weeks[] = ['label' => $week, 'span' => 0];
            }

            $months[array_key_last($months)]['span']++;
            $weeks[array_key_last($weeks)]['span']++;
            $days[] = ['number' => $day->day, 'today' => $day->isSameDay($today)];
        }

        return [$months, $weeks, $days];
    }

    /**
     * Each work site goes to the first lane whose last work site ended strictly before it starts.
     *
     * @param  Collection<int, WorkSite>  $sites
     * @return array{0: list<Bar>, 1: int}
     */
    private function bars(Collection $sites, CarbonImmutable $start, int $total): array
    {
        $laneEnds = [];
        $bars = [];

        foreach ($sites->sortBy(fn (WorkSite $site) => $site->starts_on->toDateString().sprintf('%010d', $site->id)) as $site) {
            $from = CarbonImmutable::instance($site->starts_on)->startOfDay();
            $to = CarbonImmutable::instance($site->ends_on)->startOfDay();
            $lane = null;

            foreach ($laneEnds as $index => $laneEnd) {
                if ($laneEnd->lt($from)) {
                    $lane = $index;

                    break;
                }
            }

            $lane ??= count($laneEnds);
            $laneEnds[$lane] = $to;

            $index = (int) $start->diffInDays($from);

            if ($index > $total) {
                continue;
            }

            $bars[] = [
                'id' => $site->id,
                'start' => $index,
                'span' => max(1, min((int) $from->diffInDays($to) + 1, $total + 1 - $index)),
                'lane' => $lane,
                'status' => $site->status->value,
                'crews' => $site->crew_count,
                'client' => (string) $site->client?->name,
                'city' => $site->city,
                'tooltip' => implode("\n", [
                    $site->client?->name.' — '.$site->city,
                    $from->translatedFormat('d M').' → '.$to->translatedFormat('d M'),
                    trans_choice('planning.crews', $site->crew_count, ['count' => $site->crew_count]).' — '.$site->status->label(),
                ]),
            ];
        }

        return [$bars, count($laneEnds)];
    }

    /**
     * @param  Collection<int, User>  $technicians
     * @return list<array{name: string, bars: list<AbsenceBar>}>
     */
    private function absenceRows(Collection $technicians, CarbonImmutable $start, CarbonImmutable $end, int $dayWidth): array
    {
        $periods = $this->absences->periodsForUsers($technicians->pluck('id')->all(), $start, $end);
        $rows = [];

        foreach ($technicians->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $user) {
            if (! isset($periods[$user->id])) {
                continue;
            }

            $rows[] = [
                'name' => $user->name,
                'bars' => array_map(function (array $period) use ($user, $start, $end, $dayWidth): array {
                    $from = $period['start']->gt($start) ? $period['start'] : $start;
                    $to = $period['end']->lt($end) ? $period['end'] : $end;
                    $span = (int) $from->diffInDays($to) + 1;

                    return [
                        'start' => (int) $start->diffInDays($from),
                        'span' => $span,
                        'type' => $period['type']->value,
                        'fits' => $span * $dayWidth - 4 >= mb_strlen($user->name) * 7.4 + 18,
                        'name' => $user->name,
                        'tooltip' => $user->name.' — '.$period['type']->label()."\n".$period['start']->translatedFormat('d M').($period['start']->eq($period['end']) ? '' : ' → '.$period['end']->translatedFormat('d M')),
                    ];
                }, $periods[$user->id]),
            ];
        }

        return $rows;
    }
}
