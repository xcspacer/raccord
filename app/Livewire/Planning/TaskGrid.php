<?php

namespace App\Livewire\Planning;

use App\Actions\Planning\CopyTask;
use App\Actions\Planning\DeleteTasks;
use App\Enums\AbsenceType;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Livewire\WorkSites\WorkSiteEditor;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DayPunch;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlanningHiddenWeek;
use App\Planning\TaskDetail;
use App\Planning\WeekRoster;
use App\Support\EffectiveAgency;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Planning » of an agency that plans day by day: a technician × Monday-to-Saturday grid for managers,
 * the person's own week as cards for a technician.
 */
class TaskGrid extends Component
{
    private const int DAYS = 6;

    /** Monday of the displayed week (Y-m-d); empty means the current week. */
    #[Url(except: '')]
    public string $week = '';

    public bool $selecting = false;

    /** @var list<int> Selected task ids (selection mode). */
    public array $selected = [];

    public string $group = '';

    /** @var array{token: string, tasks: int, sites: int, expiresAt: int}|null */
    public ?array $undo = null;

    public function mount(): void
    {
        $user = $this->actor();

        if ($this->isTechnicianView($user)) {
            return;
        }

        $this->authorize('manage', Task::class);

        if ($this->agency()?->planning_mode !== PlanningMode::Daily) {
            $this->redirectRoute('planning', navigate: true);
        }
    }

    public function previousWeek(): void
    {
        $this->week = $this->monday()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = $this->monday()->addWeek()->toDateString();
    }

    public function currentWeek(): void
    {
        $this->week = '';
    }

    public function openTask(int $userId, string $date): void
    {
        $this->authorize('manage', Task::class);

        $this->dispatch('task-open', userId: $userId, date: $date)->to(TaskEditor::class);
    }

    public function openTechnician(int $userId): void
    {
        $this->authorize('manage', Task::class);

        $this->dispatch('technician-open', userId: $userId, week: $this->monday()->toDateString())->to(TechnicianEditor::class);
    }

    public function openDetail(string $date): void
    {
        abort_unless($this->isTechnicianView($this->actor()), 403);

        $this->dispatch('task-detail-open', date: $date)->to(TaskDetailModal::class);
    }

    public function openWorkSite(int $id): void
    {
        $this->authorize('manage', Task::class);

        $this->dispatch('work-site-open', id: $id)->to(WorkSiteEditor::class);
    }

    public function askRemove(int $userId): void
    {
        $this->authorize('manage', Task::class);

        $person = User::query()->findOrFail($userId);

        $this->dispatch('confirm-action',
            message: __('tasks.confirm_remove_week', ['name' => $person->name, 'week' => $this->monday()->translatedFormat('d F')]),
            method: 'removeFromWeek',
            params: [$userId],
            componentId: $this->getId(),
        );
    }

    public function removeFromWeek(int $userId): void
    {
        $this->authorize('manage', Task::class);

        UserPlanningHiddenWeek::query()->firstOrCreate(['user_id' => User::query()->findOrFail($userId)->id, 'week_starts_on' => $this->monday()->toDateString()]);
    }

    public function restoreToWeek(int $userId): void
    {
        $this->authorize('manage', Task::class);

        UserPlanningHiddenWeek::query()->where('user_id', $userId)->whereDate('week_starts_on', $this->monday())->delete();
    }

    /**
     * Moves a person up (-1) or down (+1); the order is global, the same for every week and agency.
     */
    public function move(int $userId, int $direction, WeekRoster $roster): void
    {
        $this->authorize('manage', Task::class);

        $agency = $this->agency();
        abort_if($agency === null, 404);

        $ids = $roster->for($agency, $this->monday())['visible']->pluck('id')->all();
        $index = array_search($userId, $ids, true);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target === null || ! isset($ids[$target])) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        foreach ($ids as $position => $id) {
            User::query()->whereKey($id)->update(['planning_order' => $position + 1]);
        }
    }

    public function toggleSelecting(): void
    {
        $this->authorize('manage', Task::class);

        $this->selecting = ! $this->selecting;
        $this->selected = [];
    }

    public function toggleTask(int $id): void
    {
        $this->authorize('manage', Task::class);

        $this->selected = in_array($id, $this->selected, true) ? array_values(array_diff($this->selected, [$id])) : [...$this->selected, $id];
    }

    /**
     * Selects or unselects a row, a column or the whole week; when every task is already selected they are unselected.
     *
     * @param  list<int>  $ids
     */
    public function toggleMany(array $ids): void
    {
        $this->authorize('manage', Task::class);

        $ids = array_map('intval', $ids);
        $allSelected = $ids !== [] && array_diff($ids, $this->selected) === [];

        $this->selected = array_values($allSelected ? array_diff($this->selected, $ids) : array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * Adds every task of a work site (« site:12 ») or the tasks without work site (« none »), for the people
     * shown, in all weeks.
     */
    public function updatedGroup(WeekRoster $roster): void
    {
        $this->authorize('manage', Task::class);

        $agency = $this->agency();
        abort_if($agency === null, 404);

        $people = $roster->for($agency, $this->monday())['visible'];
        $group = collect($this->groups($people))->firstWhere('key', $this->group);
        $this->group = '';

        if ($group === null) {
            return;
        }

        $query = Task::query()->whereIn('user_id', $people->pluck('id'));

        if ($group['filter'] === null) {
            $query->whereNull('work_site_id');
        } else {
            $query->whereHas('workSite', fn ($site) => $site->where($group['filter']));
        }

        $this->selected = array_values(array_unique([...$this->selected, ...$query->pluck('id')->all()]));
    }

    public function askDeleteSelected(): void
    {
        $this->authorize('manage', Task::class);

        if ($this->selected === []) {
            return;
        }

        $this->dispatch('confirm-action', message: __('tasks.confirm_delete_selected', ['count' => count($this->selected)]), method: 'deleteSelected', componentId: $this->getId());
    }

    public function deleteSelected(DeleteTasks $deleteTasks, WeekRoster $roster): void
    {
        $this->authorize('manage', Task::class);

        $agency = $this->agency();
        abort_if($agency === null, 404);

        $visible = $roster->for($agency, $this->monday())['visible']->pluck('id')->all();
        $ids = Task::query()->whereIn('id', $this->selected)->whereIn('user_id', $visible)->pluck('id')->all();

        $this->undo = $deleteTasks->handle($this->actor(), $ids);
        $this->selected = [];
    }

    public function undoDelete(DeleteTasks $deleteTasks): void
    {
        $this->authorize('manage', Task::class);

        if ($this->undo !== null) {
            $restored = $deleteTasks->undo($this->actor(), $this->undo['token'], $this->undo['expiresAt']);
            $this->undo = null;

            $this->dispatch('toast', message: $restored === null ? __('tasks.undo_expired') : __('tasks.undone'));
        }
    }

    public function dismissUndo(): void
    {
        $this->undo = null;
    }

    /**
     * Drop of a task on another cell: copy (or move with Shift). Everything is checked again here, including that
     * the dragged task was not changed by someone else since the grid was shown.
     */
    public function dropTask(int $taskId, int $userId, string $date, bool $move, int $version, CopyTask $copyTask): void
    {
        $this->authorize('manage', Task::class);

        $source = Task::query()->with('user')->findOrFail($taskId);
        $target = User::query()->findOrFail($userId);
        $day = $this->parseDate($date);
        abort_if($day === null, 404);

        if ($source->version !== $version) {
            $this->dispatch('toast', message: __('tasks.conflict_drop'));

            return;
        }

        $refusal = $copyTask->refusal($source, $target, $day);

        if ($refusal === 'absent') {
            $this->dispatch('toast', message: __('tasks.drop_absent', ['name' => $target->name, 'type' => $copyTask->absenceOn($target, $day)?->type->label(), 'date' => $day->translatedFormat('d M')]));

            return;
        }

        if ($refusal !== null) {
            return;
        }

        $existing = $copyTask->existingTask($target, $day);

        if ($existing !== null) {
            $this->dispatch('confirm-action',
                message: __('tasks.confirm_replace', ['name' => $target->name, 'date' => $day->translatedFormat('d M'), 'detail' => trim($existing->label.' '.$existing->displayDetail(app(TaskDetail::class)))]),
                method: 'confirmDrop',
                params: [$taskId, $userId, $date, $move, $version, $existing->version],
                componentId: $this->getId(),
            );

            return;
        }

        $this->performDrop($source, $target, $day, $move, $copyTask);
    }

    /**
     * The replacement was confirmed: neither the dragged task nor the replaced one may have changed meanwhile.
     */
    public function confirmDrop(int $taskId, int $userId, string $date, bool $move, int $version, int $replacedVersion, CopyTask $copyTask): void
    {
        $this->authorize('manage', Task::class);

        $source = Task::query()->findOrFail($taskId);
        $target = User::query()->findOrFail($userId);
        $day = $this->parseDate($date);
        abort_if($day === null || $copyTask->refusal($source, $target, $day) !== null, 404);

        if ($source->version !== $version || $copyTask->existingTask($target, $day)?->version !== $replacedVersion) {
            $this->dispatch('toast', message: __('tasks.conflict_drop'));

            return;
        }

        $this->performDrop($source, $target, $day, $move, $copyTask);
    }

    /**
     * New order of the people shown (drag of a row); must contain exactly the people shown.
     *
     * @param  list<int>  $ids
     */
    public function reorder(array $ids, WeekRoster $roster): void
    {
        $this->authorize('manage', Task::class);

        $agency = $this->agency();
        abort_if($agency === null, 404);

        $visible = $roster->for($agency, $this->monday())['visible']->pluck('id')->all();
        $ids = array_map('intval', $ids);

        if (count($ids) !== count($visible) || array_diff($ids, $visible) !== []) {
            return;
        }

        foreach ($ids as $position => $id) {
            User::query()->whereKey($id)->update(['planning_order' => $position + 1]);
        }
    }

    #[On('task-saved'), On('work-site-saved')]
    public function refreshGrid(): void
    {
        // Re-render the grid with the saved changes.
    }

    public function render(WeekRoster $roster, TaskDetail $detail): View
    {
        $user = $this->actor();
        $technicianView = $this->isTechnicianView($user);
        $monday = $this->monday();
        $days = collect(range(0, self::DAYS - 1))->map(fn (int $offset) => $monday->addDays($offset));
        $agency = $this->agency();

        if ($technicianView) {
            $people = collect([$user->loadMissing('vehicle')]);
            $removed = collect();
        } else {
            abort_if($agency === null, 404);
            ['visible' => $people, 'removed' => $removed] = $roster->for($agency, $monday);
        }

        return view('livewire.planning.task-grid', [
            'technicianView' => $technicianView,
            'canExportTimesheet' => ! $technicianView && Gate::allows('export-planning-timesheet'),
            'monday' => $monday,
            'days' => $days,
            'today' => CarbonImmutable::today(config('platform.timezone')),
            'people' => $people,
            'removed' => $removed,
            'cells' => $this->cells($user, $people, $days, $detail),
            'groups' => $technicianView || ! $this->selecting ? [] : $this->groups($people),
            'undoVisible' => $this->undo !== null && now()->getTimestamp() <= $this->undo['expiresAt'],
            'previousWeekNumber' => $monday->subWeek()->isoWeek,
            'nextWeekNumber' => $monday->addWeek()->isoWeek,
        ])->layout('components.layouts.app', ['title' => __('tasks.title'), 'page' => 'planning']);
    }

    /**
     * Task groups the selection bar can add, over every week: audits by PM and mode, other work by
     * client and town, and the tasks without a work site.
     *
     * @param  Collection<int, User>  $people
     * @return list<array{key: string, label: string, count: int, filter: array<string, mixed>|null}>
     */
    private function groups(Collection $people): array
    {
        $ids = $people->pluck('id');
        $groups = [];

        $withoutSite = Task::query()->whereIn('user_id', $ids)->whereNull('work_site_id')->count();

        if ($withoutSite > 0) {
            $groups[] = ['key' => 'none', 'label' => __('tasks.tasks_without_site'), 'count' => $withoutSite, 'filter' => null];
        }

        $rows = Task::query()
            ->join('work_sites', 'work_sites.id', '=', 'tasks.work_site_id')
            ->whereIn('tasks.user_id', $ids)->whereNull('work_sites.deleted_at')
            ->selectRaw('work_sites.project_type, work_sites.audit_mode, work_sites.cabinet_ref, work_sites.client_id, work_sites.city, count(*) as total')
            ->groupBy('work_sites.project_type', 'work_sites.audit_mode', 'work_sites.cabinet_ref', 'work_sites.client_id', 'work_sites.city')
            ->get();

        $clients = Client::query()->whereIn('id', $rows->pluck('client_id')->filter())->pluck('name', 'id');
        $merged = [];

        foreach ($rows as $row) {
            $isAudit = $row->getAttribute('project_type') === ProjectType::Audit->value;
            $filter = $isAudit
                ? ['project_type' => ProjectType::Audit->value, 'audit_mode' => $row->getAttribute('audit_mode'), 'cabinet_ref' => $row->getAttribute('cabinet_ref')]
                : ['client_id' => $row->getAttribute('client_id'), 'city' => $row->getAttribute('city')];
            $key = md5((string) json_encode($filter));
            $mode = $row->getAttribute('audit_mode');

            $merged[$key] ??= [
                'key' => $key,
                'label' => $isAudit
                    ? __('tasks.group_audit', ['pm' => (string) $row->getAttribute('cabinet_ref'), 'mode' => $mode ? __('work_sites.task_detail.audit_mode_'.$mode) : ''])
                    : trim(($clients[$row->getAttribute('client_id')] ?? '').' · '.$row->getAttribute('city'), ' ·'),
                'count' => 0,
                'filter' => $filter,
            ];
            $merged[$key]['count'] += (int) $row->getAttribute('total');
        }

        $groups = [...$groups, ...array_values($merged)];
        usort($groups, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));

        return $groups;
    }

    /**
     * Content of every cell: the task, the absence and whether the person had already left. Day punches
     * (times and positions) are only loaded for the viewer's own row, or every row for managers (D6).
     *
     * @param  Collection<int, User>  $people
     * @param  Collection<int, CarbonImmutable>  $days
     * @return array<int, array<string, array{departed: bool, task: Task|null, detail: string, absence: AbsenceType|null, punch: DayPunch|null}>>
     */
    private function cells(User $viewer, Collection $people, Collection $days, TaskDetail $detail): array
    {
        $ids = $people->pluck('id')->all();
        $punchIds = $viewer->isManager() ? $ids : array_values(array_intersect($ids, [$viewer->id]));
        $first = $days->first();
        $last = $days->last();

        $tasks = Task::query()
            ->with(['workSite.client', 'workSite.boxes.partners'])
            ->whereIn('user_id', $ids)
            ->whereDate('date', '>=', $first)->whereDate('date', '<=', $last)
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $perUser) => $perUser->keyBy(fn (Task $task) => $task->date->toDateString()));

        $punches = DayPunch::query()->whereIn('user_id', $punchIds)->whereDate('date', '>=', $first)->whereDate('date', '<=', $last)->get()
            ->groupBy('user_id')->map(fn (Collection $perUser) => $perUser->keyBy(fn (DayPunch $punch) => $punch->date->toDateString()));

        $absences = [];

        Absence::query()->whereIn('user_id', $ids)
            ->whereDate('starts_on', '<=', $last)->whereDate('ends_on', '>=', $first)
            ->orderBy('id')->get()
            ->each(function (Absence $absence) use (&$absences, $first, $last): void {
                foreach (CarbonPeriod::create($absence->starts_on->max($first), $absence->ends_on->min($last)) as $day) {
                    $absences[$absence->user_id][$day->toDateString()] = $absence->type;
                }
            });

        $cells = [];

        foreach ($people as $person) {
            foreach ($days as $day) {
                $key = $day->toDateString();
                $task = $tasks[$person->id][$key] ?? null;

                $cells[$person->id][$key] = [
                    'departed' => $person->hasDeparted($day),
                    'task' => $task,
                    'detail' => $task?->displayDetail($detail) ?? '',
                    'absence' => $absences[$person->id][$key] ?? null,
                    'punch' => $punches[$person->id][$key] ?? null,
                ];
            }
        }

        return $cells;
    }

    private function monday(): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $this->week) : null;

        return ($parsed ?: CarbonImmutable::today(config('platform.timezone')))->startOfWeek();
    }

    private function performDrop(Task $source, User $target, CarbonImmutable $day, bool $move, CopyTask $copyTask): void
    {
        $copyTask->handle($this->actor(), $source, $target, $day, $move);

        $this->dispatch('toast', message: __($move ? 'tasks.task_moved' : 'tasks.task_added', ['name' => $target->name, 'date' => $day->translatedFormat('d M')]));
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date?->format('Y-m-d') === $value ? $date : null;
    }

    private function isTechnicianView(User $user): bool
    {
        return ! $user->isManager() && $user->isPlanned();
    }

    private function agency(): ?Agency
    {
        return app(EffectiveAgency::class)->for($this->actor());
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
