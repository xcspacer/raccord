<?php

namespace App\Actions\Absences;

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AddAbsence
{
    public function __construct(private RemoveAbsencePeriod $removePeriod) {}

    /**
     * Number of the person's tasks on those days, which will have to be reassigned.
     */
    public function tasksToReassign(User $user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->tasksBetween($user, $from, $to)->count();
    }

    /**
     * Records the absence for every calendar day of the period, replacing other absences on those days.
     * Planned tasks are kept (the work still has to be done) and the planning flags them for reassignment.
     */
    public function handle(User $actor, User $user, AbsenceType $type, CarbonImmutable $from, CarbonImmutable $to, ?string $note = null): Absence
    {
        return DB::transaction(function () use ($actor, $user, $type, $from, $to, $note): Absence {
            $this->removePeriod->handle($user, $from, $to);

            return Absence::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'note' => $note,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * @return Builder<Task>
     */
    private function tasksBetween(User $user, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Task::query()->where('user_id', $user->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }
}
