<?php

namespace App\Actions\DayPunch;

use App\Exceptions\DayPunchException;
use App\Models\DayPunch;
use App\Models\DayPunchEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The day punch rules: the server clock rules; a time sent by the phone is accepted only for requests that
 * waited offline (between 2 minutes and 36 hours old); starting twice, or ending an ended day, is harmless.
 *
 * @phpstan-type Geo array{lat: float|null, lng: float|null, accuracy: int|null, reason: string|null}
 */
class RecordDayPunch
{
    /** A phone time counts only if the request is at least this old (otherwise the server clock is used). */
    private const int MIN_OFFLINE_SECONDS = 120;

    private const int MAX_OFFLINE_HOURS = 36;

    /** A declared end cannot make a day longer than this. */
    private const int MAX_DAY_HOURS = 30;

    /**
     * @param  Geo  $geo
     * @return array{punch: DayPunch, duplicate: bool}
     */
    public function start(User $user, array $geo, ?CarbonImmutable $clientAt, bool $queued): array
    {
        [$at, $offline] = $this->resolveTime($clientAt, $queued);
        $date = $at->timezone(config('platform.timezone'))->toDateString();

        return $this->log($user, 'start', $date, function () use ($user, $geo, $at, $offline, $date): array {
            return DB::transaction(function () use ($user, $geo, $at, $offline, $date): array {
                $existing = DayPunch::query()->where('user_id', $user->id)->whereDate('date', $date)->lockForUpdate()->first();

                if ($existing !== null) {
                    return ['punch' => $existing, 'duplicate' => true];
                }

                $punch = DayPunch::query()->create(['user_id' => $user->id, 'date' => $date, 'start_received_at' => now(), 'start_at' => $at->utc(), 'start_offline' => $offline] + $this->geoColumns('start', $geo));

                return ['punch' => $punch, 'duplicate' => false];
            });
        });
    }

    /**
     * @param  Geo  $geo
     * @return array{punch: DayPunch, duplicate: bool}
     */
    public function end(User $user, array $geo, ?CarbonImmutable $clientAt, bool $queued): array
    {
        [$at, $offline] = $this->resolveTime($clientAt, $queued);
        $today = CarbonImmutable::now(config('platform.timezone'))->toDateString();

        return $this->log($user, 'end', $today, function () use ($user, $geo, $at, $offline, $today): array {
            return DB::transaction(function () use ($user, $geo, $at, $offline, $today): array {
                $open = DayPunch::query()->where('user_id', $user->id)->whereNull('end_at')->latest('date')->lockForUpdate()->first();

                if ($open === null) {
                    $todays = DayPunch::query()->where('user_id', $user->id)->whereDate('date', $today)->first();

                    if ($todays !== null) {
                        return ['punch' => $todays, 'duplicate' => true];
                    }

                    throw new DayPunchException('no_open_day');
                }

                if ($open->date->toDateString() < $today) {
                    throw new DayPunchException('previous_day_open');
                }

                $end = $at->lt($open->start_at) ? CarbonImmutable::instance($open->start_at) : $at;
                $open->update(['end_at' => $end->utc(), 'end_offline' => $offline, 'end_received_at' => now()] + $this->geoColumns('end', $geo));

                return ['punch' => $open->refresh(), 'duplicate' => false];
            });
        });
    }

    /**
     * Declares the end of a day that was left open (no GPS: the time is typed by the person).
     *
     * @return array{punch: DayPunch, duplicate: bool}
     */
    public function declareEnd(User $user, string $date, string $time): array
    {
        return $this->log($user, 'declare_end', $date, function () use ($user, $date, $time): array {
            return DB::transaction(function () use ($user, $date, $time): array {
                $punch = DayPunch::query()->where('user_id', $user->id)->whereDate('date', $date)->lockForUpdate()->first();

                if ($punch === null) {
                    throw new DayPunchException('no_open_day');
                }

                if ($punch->end_at !== null) {
                    return ['punch' => $punch, 'duplicate' => true];
                }

                $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, config('platform.timezone'));

                if ($end === null || $end->lte($punch->start_at) || $end->gt(now())) {
                    throw new DayPunchException('end_before_start', 422);
                }

                if ($end->diffInHours($punch->start_at, true) > self::MAX_DAY_HOURS) {
                    throw new DayPunchException('too_long', 422);
                }

                $punch->update(['end_at' => $end->utc(), 'end_geo_missing' => true, 'end_declared' => true, 'end_received_at' => now()]);

                return ['punch' => $punch->refresh(), 'duplicate' => false];
            });
        });
    }

    /**
     * The person's open day, if any (the one a « finish the day » would close).
     */
    public function openDay(User $user): ?DayPunch
    {
        return DayPunch::query()->where('user_id', $user->id)->whereNull('end_at')->latest('date')->first();
    }

    /**
     * @return array{0: CarbonImmutable, 1: bool} Instant to record and whether it came from the phone's own clock.
     */
    private function resolveTime(?CarbonImmutable $clientAt, bool $queued): array
    {
        $now = CarbonImmutable::now();

        if ($queued && $clientAt !== null) {
            $age = $clientAt->diffInSeconds($now, false);

            if ($age >= self::MIN_OFFLINE_SECONDS && $age <= self::MAX_OFFLINE_HOURS * 3600) {
                return [$clientAt, true];
            }
        }

        return [$now, false];
    }

    /**
     * @param  Geo  $geo
     * @return array<string, mixed>
     */
    private function geoColumns(string $prefix, array $geo): array
    {
        $missing = $geo['lat'] === null || $geo['lng'] === null;

        return [
            $prefix.'_latitude' => $missing ? null : round($geo['lat'], 6),
            $prefix.'_longitude' => $missing ? null : round($geo['lng'], 6),
            $prefix.'_accuracy' => $missing || $geo['accuracy'] === null ? null : (int) round($geo['accuracy']),
            $prefix.'_geo_missing' => $missing,
            $prefix.'_geo_reason' => $missing ? ($geo['reason'] ?? 'unavailable') : null,
        ];
    }

    /**
     * Runs the operation and writes it to the append-only journal, failures included.
     *
     * @param  callable(): array{punch: DayPunch, duplicate: bool}  $callback
     * @return array{punch: DayPunch, duplicate: bool}
     */
    private function log(User $user, string $operation, string $date, callable $callback): array
    {
        try {
            $result = $callback();
        } catch (DayPunchException $exception) {
            DayPunchEvent::query()->create(['user_id' => $user->id, 'operation' => $operation, 'date' => $date, 'duplicate' => false, 'payload' => ['error' => $exception->reason], 'created_at' => now()]);

            throw $exception;
        }

        DayPunchEvent::query()->create(['user_id' => $user->id, 'operation' => $operation, 'date' => $date, 'duplicate' => $result['duplicate'], 'payload' => ['punch_id' => $result['punch']->id], 'created_at' => now()]);

        return $result;
    }
}
