<?php

namespace App\TechnicianSpace;

use App\Enums\VehiclePhotoSlot;
use App\Models\User;
use App\Models\VehiclePhoto;
use App\Settings\VehicleSettings;
use App\Support\PublicHolidays;
use Carbon\CarbonImmutable;

/**
 * Monthly inspection of the vehicle: five photos each month, due 3 days after the first working day of the
 * month. Past that, the technician is blocked on the vehicle page until the five photos are there.
 */
class VehicleCycle
{
    /** Days allowed after the first working day of the month. */
    private const int GRACE_DAYS = 3;

    public function __construct(private VehicleSettings $settings, private PublicHolidays $holidays) {}

    /**
     * @return array{status: 'inactive'|'done'|'due'|'blocked', days: int}
     */
    public function state(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(config('platform.timezone'))->startOfDay();
        $start = $this->settings->cycle_start_month;

        if ($start === null || $today->format('Y-m') < $start || $this->isComplete($user, $today->format('Y-m'))) {
            return ['status' => $start === null || $today->format('Y-m') < $start ? 'inactive' : 'done', 'days' => 0];
        }

        $deadline = $this->firstWorkingDay($today->startOfMonth())->addDays(self::GRACE_DAYS);

        return $today->gt($deadline)
            ? ['status' => 'blocked', 'days' => (int) $deadline->diffInDays($today)]
            : ['status' => 'due', 'days' => (int) $today->diffInDays($deadline)];
    }

    public function isBlocked(User $user): bool
    {
        return $this->state($user)['status'] === 'blocked';
    }

    private function isComplete(User $user, string $month): bool
    {
        return VehiclePhoto::query()->where('user_id', $user->id)->whereNull('archived_at')->where('month', $month)->distinct()->count('slot') >= count(VehiclePhotoSlot::cases());
    }

    private function firstWorkingDay(CarbonImmutable $day): CarbonImmutable
    {
        $day = CarbonImmutable::parse($day->toDateString());

        while (! $this->holidays->isWorkingDay($day)) {
            $day = $day->addDay();
        }

        return CarbonImmutable::parse($day->toDateString(), config('platform.timezone'));
    }
}
