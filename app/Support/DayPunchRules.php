<?php

namespace App\Support;

use App\Enums\Module;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\DayPunch;
use App\Models\User;
use App\Settings\ModuleSettings;
use Carbon\CarbonImmutable;

/**
 * Who has to punch and when: technicians, on every day except Sunday and the days they are absent
 * (Saturdays and public holidays included), while the day punch module is on.
 */
class DayPunchRules
{
    public function __construct(private ModuleSettings $modules) {}

    public function appliesTo(User $user): bool
    {
        return $user->hasRole(Role::Technician->value) && $this->modules->isEnabled(Module::DailyClockIn);
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('platform.timezone'))->startOfDay();
    }

    /**
     * Whether the person must start their day now: a working day with no start recorded yet.
     */
    public function mustStart(User $user): bool
    {
        if (! $this->appliesTo($user)) {
            return false;
        }

        $today = $this->today();

        if ($today->isSunday()) {
            return false;
        }

        $absent = Absence::query()->where('user_id', $user->id)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->exists();

        return ! $absent && ! DayPunch::query()->where('user_id', $user->id)->whereDate('date', $today)->exists();
    }

    /**
     * The open day of today (running), for the « finish the day » button.
     */
    public function runningToday(User $user): ?DayPunch
    {
        return $this->appliesTo($user)
            ? DayPunch::query()->where('user_id', $user->id)->whereDate('date', $this->today())->whereNull('end_at')->first()
            : null;
    }

    /**
     * An earlier day left without an end.
     */
    public function previousOpen(User $user): ?DayPunch
    {
        return $this->appliesTo($user)
            ? DayPunch::query()->where('user_id', $user->id)->whereDate('date', '<', $this->today())->whereNull('end_at')->latest('date')->first()
            : null;
    }
}
