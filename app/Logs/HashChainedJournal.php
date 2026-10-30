<?php

namespace App\Logs;

use App\Models\ActivityLogChain;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Append-only journals kept in the platform's activity log, each entry chained to the previous one of the same
 * journal by a SHA-256 hash: changing or removing an entry breaks the chain, which the daily verification reports.
 * A correction is a new entry, never an edit. Writers of a journal are serialised by locking its chain head, so the
 * chain never forks; the entry is written in the caller's transaction (no entry for an action that was rolled back).
 *
 * The hash covers the previous hash, the time, the author, the subject, the event, the description and the properties
 * in a canonical form (keys sorted), so it does not depend on how the database orders JSON keys. The « batch » property
 * the platform adds to every entry is left out.
 */
class HashChainedJournal
{
    /** Properties added by the platform to every activity, outside the chained content. */
    private const array UNHASHED_PROPERTIES = ['batch'];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function append(string $logName, string $event, ?Model $subject, ?User $causer, array $properties = [], ?string $description = null): Activity
    {
        return DB::transaction(function () use ($logName, $event, $subject, $causer, $properties, $description): Activity {
            ActivityLogChain::query()->insertOrIgnore(['log_name' => $logName, 'created_at' => now(), 'updated_at' => now()]);
            $head = ActivityLogChain::query()->where('log_name', $logName)->lockForUpdate()->firstOrFail();

            $activity = new Activity;
            $activity->forceFill([
                'log_name' => $logName,
                'description' => $description ?? $event,
                'event' => $event,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'causer_type' => $causer?->getMorphClass(),
                'causer_id' => $causer?->getKey(),
                'properties' => $properties,
                'created_at' => CarbonImmutable::now()->startOfSecond(),
                'updated_at' => CarbonImmutable::now()->startOfSecond(),
                'previous_hash' => $head->last_hash,
            ]);
            $activity->setAttribute('chain_hash', $this->hash($activity, $head->last_hash));
            $activity->save();

            $head->forceFill(['last_activity_id' => $activity->id, 'last_hash' => $activity->getAttribute('chain_hash')])->save();

            return $activity;
        });
    }

    /**
     * Walks a whole journal in order and recomputes every link.
     *
     * @return array{intact: bool, checked: int, broken_at: int|null}
     */
    public function verify(string $logName): array
    {
        $previous = null;
        $checked = 0;

        foreach (Activity::query()->where('log_name', $logName)->orderBy('id')->lazyById(500) as $activity) {
            $checked++;

            if ($activity->getAttribute('previous_hash') !== $previous || $activity->getAttribute('chain_hash') !== $this->hash($activity, $previous)) {
                return ['intact' => false, 'checked' => $checked, 'broken_at' => $activity->id];
            }

            $previous = $activity->getAttribute('chain_hash');
        }

        $head = ActivityLogChain::query()->where('log_name', $logName)->first();

        if ($head !== null && $head->last_hash !== $previous) {
            return ['intact' => false, 'checked' => $checked, 'broken_at' => $head->last_activity_id];
        }

        return ['intact' => true, 'checked' => $checked, 'broken_at' => null];
    }

    public function hash(Activity $activity, ?string $previous): string
    {
        $properties = collect($activity->properties ?? [])->except(self::UNHASHED_PROPERTIES)->all();
        $at = $activity->created_at === null ? '' : CarbonImmutable::parse($activity->created_at)->utc()->format('Y-m-d\TH:i:s\Z');

        return hash('sha256', implode('|', [
            $previous ?? '',
            $at,
            (string) $activity->causer_type,
            (string) $activity->causer_id,
            (string) $activity->subject_type,
            (string) $activity->subject_id,
            (string) $activity->event,
            $activity->description,
            $this->canonical($properties),
        ]));
    }

    /**
     * JSON with every object's keys sorted, recursively (lists keep their order).
     */
    public function canonical(mixed $value): string
    {
        return (string) json_encode($this->normalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->normalise($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item) => $this->normalise($item), $value);
    }
}
