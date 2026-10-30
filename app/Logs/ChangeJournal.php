<?php

namespace App\Logs;

use App\Actions\Users\ChangeUserRole;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Reads the « Logs » journal: the recorded types, which entries can be restored (and with what) and a one-line
 * summary of each recorded record.
 */
class ChangeJournal
{
    /** @var list<class-string<Model>> */
    public const array SUBJECTS = [WorkSite::class, Task::class, Absence::class, User::class];

    public const array TYPES = ['work_sites', 'tasks', 'users'];

    /**
     * Never put back by a restore: credentials, and what decides what a person may reach (their agency). Roles live
     * outside the record and are never restored either.
     *
     * @var array<class-string<Model>, list<string>>
     */
    public const array NOT_RESTORED = [
        User::class => ['password', 'legacy_password_hash', 'remember_token', 'must_change_password', 'agency_id', 'all_agencies'],
    ];

    /** Journal events that change a recorded record. */
    private const array RECORD_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    /**
     * @return Builder<Activity>
     */
    public function query(?string $type = null): Builder
    {
        return Activity::query()
            ->whereIn('subject_type', array_map(fn (string $class) => (new $class)->getMorphClass(), self::SUBJECTS))
            ->when(in_array($type, self::TYPES, true), fn (Builder $query) => $query->where('log_name', $type))
            ->with('causer')
            ->latest('id');
    }

    /**
     * Changed and deleted records can be put back, never the read-only history brought over from a previous
     * platform (properties.legacy).
     */
    public function isRestorable(Activity $activity): bool
    {
        return in_array($activity->event, ['updated', 'deleted'], true)
            && $this->subjectClass($activity) !== null
            && $activity->getProperty('legacy') !== true;
    }

    /**
     * Name of whoever made the change: the platform user, else the name stored with the entry (an author who
     * has no account on the platform).
     */
    public function authorName(Activity $activity): ?string
    {
        $name = $activity->causer?->getAttribute('name') ?? $activity->getProperty('author_name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * @return class-string<Model>|null
     */
    public function subjectClass(Activity $activity): ?string
    {
        foreach (self::SUBJECTS as $class) {
            if ((new $class)->getMorphClass() === $activity->subject_type) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Record as it was before the change (changed or deleted) or after it (added).
     *
     * @return array<string, mixed>
     */
    public function snapshot(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];

        return (array) ($activity->event === 'created' ? ($changes['attributes'] ?? []) : ($changes['old'] ?? $changes['attributes'] ?? []));
    }

    /**
     * What a restore puts back: the snapshot without the attributes that are never restored.
     *
     * @return array<string, mixed>
     */
    public function restorableSnapshot(Activity $activity): array
    {
        return array_diff_key($this->snapshot($activity), array_flip(self::NOT_RESTORED[$this->subjectClass($activity)] ?? []));
    }

    /**
     * Whether the record was changed (or deleted, or brought back) after this entry by another entry than the
     * given ones: restoring it would silently overwrite that later change.
     *
     * @param  list<int>  $ignoredIds
     */
    public function changedSince(Activity $activity, array $ignoredIds = []): bool
    {
        return Activity::query()
            ->where('subject_type', $activity->subject_type)
            ->where('subject_id', $activity->subject_id)
            ->where('id', '>', $activity->id)
            ->whereIn('event', self::RECORD_EVENTS)
            ->whereNotIn('id', $ignoredIds)
            ->exists();
    }

    /**
     * One-line summaries keyed by activity id, with the names they mention loaded in a few queries.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<int, string>
     */
    public function summaries(Collection $activities): array
    {
        $snapshots = $activities->mapWithKeys(fn (Activity $activity) => [$activity->id => $this->snapshot($activity)]);
        $ids = fn (string $key) => $snapshots->pluck($key)->filter()->unique()->values()->all();

        $people = User::withTrashed()->with('roles')->whereIn('id', [...$ids('user_id'), ...$activities->where('log_name', 'users')->pluck('subject_id')->filter()->all()])->get()->keyBy('id');
        $clients = Client::query()->whereIn('id', $ids('client_id'))->pluck('name', 'id');
        $agencies = Agency::query()->whereIn('id', $ids('agency_id'))->pluck('name', 'id');

        return $activities->mapWithKeys(function (Activity $activity) use ($snapshots, $people, $clients, $agencies) {
            $data = $snapshots[$activity->id];

            if ($activity->event === ChangeUserRole::EVENT) {
                return [$activity->id => $this->roleChange($activity, (string) ($people->get($activity->subject_id ?? 0)->name ?? $data['name'] ?? '—'))];
            }

            $summary = match ($this->subjectClass($activity)) {
                WorkSite::class => collect([
                    trim(($clients->get($data['client_id'] ?? 0) ?? '—').' — '.($data['city'] ?? '')),
                    $this->period($data['starts_on'] ?? null, $data['ends_on'] ?? null, 'd M'),
                    isset($data['crew_count']) ? trans_choice('logs.crews', (int) $data['crew_count'], ['count' => $data['crew_count']]) : null,
                    $data['cabinet_ref'] ?? null,
                ])->filter()->implode(' · '),
                Task::class => collect([
                    $people->get($data['user_id'] ?? 0)->name ?? '—',
                    $this->period($data['date'] ?? null, null),
                    collect([($data['label'] ?? null) ?: ($data['state_code'] ?? null), $data['detail'] ?? null])->filter()->implode(' · '),
                ])->filter()->implode(' — '),
                Absence::class => collect([
                    $people->get($data['user_id'] ?? 0)->name ?? '—',
                    $this->period($data['starts_on'] ?? null, $data['ends_on'] ?? null),
                    AbsenceType::tryFrom((string) ($data['type'] ?? ''))?->label(),
                ])->filter()->implode(' — '),
                User::class => ($data['name'] ?? '—').' ('.collect([
                    ($role = $people->get($activity->subject_id ?? 0)?->roles->first()?->getAttribute('name')) ? __('roles.'.$role) : null,
                    ($data['all_agencies'] ?? false) ? __('users.all_agencies') : $agencies->get($data['agency_id'] ?? 0),
                ])->filter()->implode(' · ').')',
                default => '',
            };

            return [$activity->id => $summary];
        })->all();
    }

    /**
     * « Name (Old role → New role) ».
     */
    private function roleChange(Activity $activity, string $name): string
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $label = fn (mixed $role): string => is_string($role) && $role !== '' ? __('roles.'.$role) : '—';

        return $name.' ('.$label($changes['old']['role'] ?? null).' → '.$label($changes['attributes']['role'] ?? null).')';
    }

    /**
     * « 05 oct. → 09 oct. »: work sites show day and month only, as on the planning.
     */
    private function period(?string $from, ?string $to, string $format = 'd M Y'): ?string
    {
        if ($from === null) {
            return null;
        }

        $start = CarbonImmutable::parse($from)->translatedFormat($format);

        return $to !== null && substr($to, 0, 10) !== substr($from, 0, 10) ? $start.' → '.CarbonImmutable::parse($to)->translatedFormat($format) : $start;
    }
}
