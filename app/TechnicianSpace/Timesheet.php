<?php

namespace App\TechnicianSpace;

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Task;
use App\Models\User;
use App\Planning\TaskDetail;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * The person's month: one row per day with a task or an absence, and the totals (worked days, absent,
 * holiday, sick). A day with an absence counts as that absence, never as worked.
 */
class Timesheet
{
    public function __construct(private TaskDetail $detail) {}

    /**
     * @return array{rows: list<array{date: CarbonImmutable, absence: AbsenceType|null, label: string, detail: string}>, worked: int, absent: int, leave: int, sick: int}
     */
    public function forMonth(User $user, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $tasks = Task::query()->with(['workSite.client', 'workSite.boxes.partners'])->where('user_id', $user->id)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)->get()
            ->keyBy(fn (Task $task) => $task->date->toDateString());

        $absences = [];

        Absence::query()->where('user_id', $user->id)->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)->orderBy('id')->get()
            ->each(function (Absence $absence) use (&$absences, $start, $end): void {
                foreach (CarbonPeriod::create($absence->starts_on->max($start), $absence->ends_on->min($end)) as $day) {
                    $absences[$day->toDateString()] = $absence->type;
                }
            });

        $rows = [];
        $totals = ['worked' => 0, 'absent' => 0, 'leave' => 0, 'sick' => 0];

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $key = $day->toDateString();
            $task = $tasks[$key] ?? null;
            $absence = $absences[$key] ?? null;

            if ($task === null && $absence === null) {
                continue;
            }

            if ($absence !== null) {
                $totals[match ($absence) {
                    AbsenceType::Absent => 'absent', AbsenceType::Leave => 'leave', AbsenceType::Sick => 'sick'
                }]++;
            } else {
                $totals['worked']++;
            }

            $rows[] = [
                'date' => CarbonImmutable::instance($day),
                'absence' => $absence,
                'label' => (string) $task?->label,
                'detail' => $task?->displayDetail($this->detail) ?? '',
            ];
        }

        return ['rows' => $rows] + $totals;
    }
}
