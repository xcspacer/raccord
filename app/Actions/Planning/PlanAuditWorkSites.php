<?php

namespace App\Actions\Planning;

use App\Enums\AuditMode;
use App\Enums\ProjectType;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\PublicHolidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plans the audit of a cabinet (PM): one one-day work site per team and per working day (weekends and
 * public holidays are skipped). Editing changes the day of one work site and, with an end date, extends
 * the same team over the following working days.
 *
 * @phpstan-type Team array{lead: int, partners: list<int>}
 */
class PlanAuditWorkSites
{
    public function __construct(private SaveDailyWorkSite $saveDaily, private PublicHolidays $holidays) {}

    /**
     * Working days of the period.
     *
     * @return list<CarbonImmutable>
     */
    public function workingDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $day = CarbonImmutable::instance($day);

            if ($this->holidays->isWorkingDay($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Days to plan and the people who cannot be assigned or would be reassigned, before anything is saved.
     *
     * @param  list<Team>  $teams
     * @return array{days: list<CarbonImmutable>, absent: Collection<int, string>, reassigned: Collection<int, string>}
     */
    public function preview(?WorkSite $editing, array $teams, CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $days = $this->daysToPlan($editing, $from, $to);
        $absent = collect();
        $reassigned = collect();

        foreach ($days as $index => $day) {
            $ids = array_values(array_unique(array_merge(...array_map(fn (array $team) => [$team['lead'], ...$team['partners']], $teams))));
            $isEdited = $editing !== null && $index === 0;

            $absent = $absent->merge($this->saveDaily->absentTechnicians($ids, $day));
            $reassigned = $reassigned->merge($this->saveDaily->reassignedTechnicians($isEdited ? $editing : null, $ids, $day));
        }

        return ['days' => $days, 'absent' => $absent->unique()->values(), 'reassigned' => $reassigned->unique()->values()];
    }

    /**
     * @param  list<Team>  $teams  Several teams on creation; exactly one when editing.
     * @return int Number of work sites created or updated.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Agency $agency, ?WorkSite $editing, string $pm, ?AuditMode $mode, array $teams, CarbonImmutable $from, ?CarbonImmutable $to, ?string $notes): int
    {
        $plan = $this->preview($editing, $teams, $from, $to);

        if ($plan['days'] === []) {
            throw ValidationException::withMessages(['date' => __('work_sites.audit_required')]);
        }

        if ($plan['absent']->isNotEmpty()) {
            throw ValidationException::withMessages(['teams' => __('work_sites.absent_technicians', ['names' => $plan['absent']->implode(', ')])]);
        }

        $known = WorkSite::query()->where('project_type', ProjectType::Audit->value)->where('cabinet_ref', $pm)->with('client')->latest('id')->first();
        $client = data_get($known, 'client.name') ?? data_get($agency, 'defaultClient.name');
        $city = (string) (data_get($known, 'city') ?? data_get($editing, 'city') ?? '');
        $cabinetRef = (string) (data_get($known, 'cabinet_ref') ?? $pm);

        if ($client === null) {
            throw ValidationException::withMessages(['cabinetRef' => __('work_sites.audit_client_missing')]);
        }

        return DB::transaction(function () use ($actor, $agency, $editing, $client, $city, $cabinetRef, $mode, $teams, $plan, $notes): int {
            $count = 0;

            foreach ($plan['days'] as $index => $day) {
                foreach ($teams as $team) {
                    $site = $editing !== null && $index === 0 ? $editing : null;

                    $this->saveDaily->handle($actor, $site, $agency, [
                        'date' => $day,
                        'client' => $client,
                        'project' => ProjectType::Audit,
                        'audit_mode' => $mode,
                        'lead' => $team['lead'],
                        'partners' => $team['partners'],
                        'city' => $city,
                        'cabinet_ref' => $cabinetRef,
                        'notes' => $notes,
                        'boxes' => [],
                    ]);

                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function daysToPlan(?WorkSite $editing, CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($editing === null) {
            return $to === null || $to->lt($from) ? [] : $this->workingDays($from, $to);
        }

        $days = [$from];

        if ($to !== null && $to->gt($from)) {
            $days = [...$days, ...$this->workingDays($from->addDay(), $to)];
        }

        return $days;
    }
}
