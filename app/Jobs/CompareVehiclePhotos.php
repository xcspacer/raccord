<?php

namespace App\Jobs;

use App\Ai\VehiclePhotoComparison;
use App\Models\User;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compares a replaced vehicle photo with its replacement, after the person has been answered.
 */
class CompareVehiclePhotos
{
    use Queueable;

    public function __construct(public int $userId, public int $beforeId, public int $afterId) {}

    public function handle(VehiclePhotoComparison $comparison): void
    {
        $user = User::query()->find($this->userId);
        $before = VehiclePhoto::query()->with('file')->find($this->beforeId);
        $after = VehiclePhoto::query()->with('file')->find($this->afterId);

        if ($user !== null && $before !== null && $after !== null) {
            $comparison->compare($user, $before, $after);
        }
    }
}
