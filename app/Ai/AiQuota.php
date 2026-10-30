<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Limits of AI requests: per UTC day, per person and for the whole installation, and per person within the last
 * hour (so one person cannot use their whole day at once). A request is counted when it is allowed, so a failed
 * call still uses part of the quota.
 */
class AiQuota
{
    /**
     * @throws AiException quota_reached
     */
    public function consume(User $user, AiFeature $feature): void
    {
        $limits = $feature->limits();
        $since = now()->utc()->startOfDay();

        DB::transaction(function () use ($user, $feature, $limits, $since): void {
            $today = DB::table('ai_usage')->where('feature', $feature->value)->where('created_at', '>=', $since)->lockForUpdate();
            $lastHour = DB::table('ai_usage')->where('feature', $feature->value)->where('user_id', $user->id)->where('created_at', '>=', now()->subHour());

            if ((clone $today)->count() >= $limits['global']
                || (clone $today)->where('user_id', $user->id)->count() >= $limits['per_user']
                || $lastHour->count() >= $limits['per_user_hour']) {
                throw new AiException('quota_reached');
            }

            DB::table('ai_usage')->insert(['user_id' => $user->id, 'feature' => $feature->value, 'created_at' => now()]);
        });
    }
}
