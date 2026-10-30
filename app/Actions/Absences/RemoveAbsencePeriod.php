<?php

namespace App\Actions\Absences;

use App\Models\Absence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RemoveAbsencePeriod
{
    /**
     * Frees the person's absence days (any type) between both dates: absences inside the period are
     * deleted, those crossing its edges are shortened or split.
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to): void
    {
        DB::transaction(function () use ($user, $from, $to): void {
            Absence::query()
                ->where('user_id', $user->id)
                ->whereDate('starts_on', '<=', $to)
                ->whereDate('ends_on', '>=', $from)
                ->lockForUpdate()
                ->get()
                ->each(function (Absence $absence) use ($from, $to): void {
                    $startsBefore = $absence->starts_on->lt($from);
                    $endsAfter = $absence->ends_on->gt($to);

                    if ($startsBefore && $endsAfter) {
                        $absence->replicate()->fill(['starts_on' => $to->addDay()->toDateString()])->save();
                    }

                    match (true) {
                        $startsBefore => $absence->update(['ends_on' => $from->subDay()->toDateString()]),
                        $endsAfter => $absence->update(['starts_on' => $to->addDay()->toDateString()]),
                        default => $absence->delete(),
                    };
                });
        });
    }
}
