<?php

namespace App\Livewire\Absences;

use App\Absences\AbsenceCalendar;
use App\Actions\Absences\AddAbsence;
use App\Actions\Absences\RemoveAbsencePeriod;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Absences block of one person (month summary, periods with ✕, add row), shown on the absences page
 * and on the user cards.
 */
class AbsenceManager extends Component
{
    #[Locked]
    public int $userId;

    /** Displayed month, "Y-m". */
    #[Reactive]
    public string $month = '';

    public string $type = AbsenceType::Absent->value;

    public string $from = '';

    public string $to = '';

    public function mount(User $user, string $month = ''): void
    {
        $this->authorize('manage', Absence::class);

        $this->userId = $user->id;
        $this->month = $month;
    }

    public function add(AddAbsence $addAbsence): void
    {
        [$user, $type, $from, $to] = $this->validatedPeriod();

        $tasks = $addAbsence->tasksToReassign($user, $from, $to);

        if ($tasks > 0) {
            $this->dispatch('confirm-action',
                message: trans_choice('absences.tasks_to_reassign', $tasks, ['count' => $tasks]),
                method: 'confirmAdd',
                params: [$type->value, $from->toDateString(), $to->toDateString()],
                componentId: $this->getId(),
            );

            return;
        }

        $this->store($addAbsence, $user, $type, $from, $to);
    }

    public function confirmAdd(AddAbsence $addAbsence, string $type, string $from, string $to): void
    {
        $this->type = $type;
        $this->from = $from;
        $this->to = $to;

        [$user, $type, $from, $to] = $this->validatedPeriod();

        $this->store($addAbsence, $user, $type, $from, $to);
    }

    public function askRemove(string $start, string $end): void
    {
        $this->authorize('manage', Absence::class);

        $this->dispatch('confirm-action',
            message: __('absences.remove_confirm'),
            method: 'remove',
            params: [$start, $end],
            componentId: $this->getId(),
        );
    }

    public function remove(RemoveAbsencePeriod $removePeriod, string $start, string $end): void
    {
        $this->authorize('manage', Absence::class);

        $from = $this->parseDate($start);
        $to = $this->parseDate($end);

        if ($from === null || $to === null || $from->gt($to)) {
            return;
        }

        $removePeriod->handle($this->user(), $from, $to);
        $this->dispatch('absences-changed');
    }

    public function render(AbsenceCalendar $calendar): View
    {
        $month = $this->displayedMonth();
        $isCurrentMonth = $month->isSameMonth(CarbonImmutable::today(config('platform.timezone')));

        return view('livewire.absences.absence-manager', [
            'calendar' => $calendar->forMonth($this->userId, $month),
            'summaryTitle' => $isCurrentMonth
                ? __('absences.summary_current')
                : __('absences.summary_month', ['month' => mb_strtolower($month->translatedFormat('F Y'))]),
            'types' => AbsenceType::cases(),
        ]);
    }

    /**
     * @return array{User, AbsenceType, CarbonImmutable, CarbonImmutable}
     */
    private function validatedPeriod(): array
    {
        $this->authorize('manage', Absence::class);

        $this->validate(['type' => ['required', Rule::enum(AbsenceType::class)]]);

        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);

        if ($from === null || $to === null || $from->gt($to)) {
            throw ValidationException::withMessages(['from' => __('absences.invalid_period')]);
        }

        return [$this->user(), AbsenceType::from($this->type), $from, $to];
    }

    private function store(AddAbsence $addAbsence, User $user, AbsenceType $type, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $addAbsence->handle($actor, $user, $type, $from, $to);

        $this->reset('from', 'to');
        $this->dispatch('absences-changed');
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date?->format('Y-m-d') === $value ? $date : null;
    }

    private function displayedMonth(): CarbonImmutable
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $this->month) : null;

        return $month !== null ? $month : CarbonImmutable::today(config('platform.timezone'))->startOfMonth();
    }

    private function user(): User
    {
        $user = User::query()->findOrFail($this->userId);
        abort_unless($user->isPlanned(), 403);

        return $user;
    }
}
