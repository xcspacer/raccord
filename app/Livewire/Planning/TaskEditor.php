<?php

namespace App\Livewire\Planning;

use App\Absences\AbsenceCalendar;
use App\Actions\Absences\AddAbsence;
use App\Actions\Absences\RemoveAbsencePeriod;
use App\Enums\AbsenceType;
use App\Enums\Feature;
use App\Models\Absence;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Window of one cell of the task grid: a task, or an absence (absent, sick, leave) for that person and day. Absences
 * are offered only to people with the « Absences et congés » access.
 */
class TaskEditor extends Component
{
    public bool $open = false;

    #[Locked]
    public ?int $userId = null;

    #[Locked]
    public string $date = '';

    /** task | absent | sick | leave */
    public string $mode = 'task';

    public string $stateCode = '';

    public string $label = '';

    public string $detail = '';

    public string $note = '';

    public string $from = '';

    public string $to = '';

    /** Task shown when the window was opened, to refuse saving over someone else's change. */
    #[Locked]
    public ?int $taskId = null;

    #[Locked]
    public int $taskVersion = 0;

    public function mount(): void
    {
        $this->authorize('manage', Task::class);
    }

    #[On('task-open')]
    public function openFor(int $userId, string $date): void
    {
        $this->authorize('manage', Task::class);

        $person = User::query()->findOrFail($userId);
        $day = $this->parseDate($date);
        abort_if($day === null || $person->hasDeparted($day), 404);

        $this->reset('stateCode', 'label', 'detail', 'note', 'taskId', 'taskVersion');
        $this->resetValidation();
        $this->userId = $person->id;
        $this->date = $day->toDateString();
        $this->from = $this->to = $this->date;

        $task = Task::query()->where('user_id', $person->id)->whereDate('date', $day)->first();
        $absence = $this->absenceOn($person, $day);
        $this->taskId = $task?->id;
        $this->taskVersion = $task === null ? 0 : $task->version;

        if ($absence !== null) {
            $this->mode = $absence['type']->value;
            $this->from = $absence['start']->toDateString();
            $this->to = $absence['end']->toDateString();
            $this->note = (string) Absence::query()->where('user_id', $person->id)->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->latest('id')->value('note');
        } else {
            $this->mode = 'task';
        }

        if ($task !== null && $absence === null) {
            $this->stateCode = $task->state_code;
            $this->label = (string) $task->label;
            $this->detail = (string) $task->detail;
            $this->note = (string) $task->note;
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(AddAbsence $addAbsence): void
    {
        $person = $this->person();
        $day = CarbonImmutable::parse($this->date);

        $this->validate([
            'mode' => ['required', Rule::in(['task', 'absent', 'sick', 'leave'])],
            'stateCode' => ['nullable', 'string', 'max:8'],
            'label' => ['nullable', 'string', 'max:60'],
            'detail' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->mode === 'task') {
            $this->saveTask($person, $day);

            return;
        }

        $this->authorize('use-feature', Feature::Absences);
        $type = AbsenceType::from($this->mode);
        [$from, $to] = $type === AbsenceType::Absent ? [$day, $day] : $this->period();

        $tasks = $addAbsence->tasksToReassign($person, $from, $to);

        if ($tasks > 0) {
            $this->dispatch('confirm-action', message: trans_choice('absences.tasks_to_reassign', $tasks, ['count' => $tasks]), method: 'confirmAbsence', componentId: $this->getId());

            return;
        }

        $this->storeAbsence($addAbsence, $person, $type, $from, $to);
    }

    public function confirmAbsence(AddAbsence $addAbsence): void
    {
        $person = $this->person();
        $this->authorize('use-feature', Feature::Absences);
        $type = AbsenceType::from($this->mode);
        [$from, $to] = $type === AbsenceType::Absent ? [CarbonImmutable::parse($this->date), CarbonImmutable::parse($this->date)] : $this->period();

        $this->storeAbsence($addAbsence, $person, $type, $from, $to);
    }

    /**
     * Clears the day (task) or the whole displayed period (absence).
     */
    public function clear(RemoveAbsencePeriod $removePeriod): void
    {
        $person = $this->person();
        $day = CarbonImmutable::parse($this->date);

        if ($this->mode === 'task') {
            $task = Task::query()->where('user_id', $person->id)->whereDate('date', $day)->first();

            if ($this->changedMeanwhile($task)) {
                return;
            }

            $task?->delete();
        } else {
            $this->authorize('use-feature', Feature::Absences);
            [$from, $to] = AbsenceType::from($this->mode) === AbsenceType::Absent ? [$day, $day] : $this->period();
            $removePeriod->handle($person, $from, $to);
        }

        $this->finish();
    }

    public function render(): View
    {
        return view('livewire.planning.task-editor', [
            'person' => $this->open ? User::query()->find($this->userId) : null,
            'title' => $this->open && $this->date !== '' ? CarbonImmutable::parse($this->date)->translatedFormat('d M') : '',
            'canManageAbsences' => Gate::allows('manage', Absence::class),
        ]);
    }

    private function saveTask(User $person, CarbonImmutable $day): void
    {
        if ($this->absenceOn($person, $day) !== null) {
            throw ValidationException::withMessages(['mode' => __('tasks.absent_that_day', ['name' => $person->name])]);
        }

        $fields = [
            'state_code' => trim($this->stateCode),
            'label' => trim($this->label) ?: null,
            'detail' => trim($this->detail) ?: null,
            'note' => trim($this->note) ?: null,
        ];

        $task = Task::query()->where('user_id', $person->id)->whereDate('date', $day)->first();

        if ($this->changedMeanwhile($task)) {
            return;
        }

        $empty = $fields['state_code'] === '' && $fields['label'] === null && $fields['detail'] === null && $fields['note'] === null;

        if ($empty) {
            $task?->delete();
        } else {
            $fields['state_code'] = $fields['state_code'] === '' ? 'HO' : $fields['state_code'];
            $task ??= new Task(['user_id' => $person->id, 'date' => $day->toDateString()]);
            $task->fill($fields)->save();
        }

        $this->finish();
    }

    /**
     * Someone else created, changed or removed the task since the window was opened: nothing is saved, the
     * window shows the current task with a message.
     */
    private function changedMeanwhile(?Task $current): bool
    {
        if ($current?->id === $this->taskId && ($current === null || $current->version === $this->taskVersion)) {
            return false;
        }

        $this->openFor((int) $this->userId, $this->date);
        $this->addError('conflict', __('tasks.conflict'));

        return true;
    }

    private function storeAbsence(AddAbsence $addAbsence, User $person, AbsenceType $type, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $addAbsence->handle($actor, $person, $type, $from, $to, trim($this->note) ?: null);
        $this->finish();
    }

    private function finish(): void
    {
        $this->open = false;
        $this->dispatch('task-saved');
        $this->dispatch('absences-changed');
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function period(): array
    {
        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);

        if ($from === null || $to === null || $from->gt($to)) {
            throw ValidationException::withMessages(['from' => __('absences.invalid_period')]);
        }

        return [$from, $to];
    }

    /**
     * Absence covering the day, with the full period it belongs to.
     *
     * @return array{type: AbsenceType, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function absenceOn(User $person, CarbonImmutable $day): ?array
    {
        $periods = app(AbsenceCalendar::class)->periodsForUsers([$person->id], $day, $day)[$person->id] ?? [];

        foreach ($periods as $period) {
            if ($day->between($period['start'], $period['end'])) {
                return ['type' => $period['type'], 'start' => $period['start'], 'end' => $period['end']];
            }
        }

        return null;
    }

    private function person(): User
    {
        $this->authorize('manage', Task::class);

        return User::query()->findOrFail($this->userId);
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date?->format('Y-m-d') === $value ? $date : null;
    }
}
