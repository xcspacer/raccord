<?php

namespace App\Livewire\Planning;

use App\Absences\AbsenceCalendar;
use App\Actions\Absences\RemoveAbsencePeriod;
use App\Enums\AbsenceType;
use App\Enums\Feature;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAgencyPeriod;
use App\Planning\WeekRoster;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * « Modifier — Nom »: change a technician's agency from a week on (administrator) and list their sick leave and
 * holidays, which people with the « Absences et congés » access may also change or remove.
 */
class TechnicianEditor extends Component
{
    public bool $open = false;

    #[Locked]
    public ?int $userId = null;

    /** Monday the agency change applies from. */
    #[Locked]
    public string $week = '';

    public string $agencyId = '';

    public function mount(): void
    {
        $this->authorize('manage', Task::class);
    }

    #[On('technician-open')]
    public function openFor(int $userId, string $week, WeekRoster $roster): void
    {
        $this->authorize('manage', Task::class);

        $person = User::query()->findOrFail($userId);
        $monday = $this->parseMonday($week);

        $this->userId = $person->id;
        $this->week = $monday->toDateString();
        $this->agencyId = (string) $roster->agencyIdFor($person, $monday);
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function applyAgency(): void
    {
        $this->authorize('update', User::class);

        $this->validate(['agencyId' => ['required', 'integer', Rule::exists('agencies', 'id')->whereNull('deleted_at')]]);

        $person = User::query()->findOrFail($this->userId);

        UserAgencyPeriod::query()->updateOrCreate(
            ['user_id' => $person->id, 'starts_on' => $this->week],
            ['agency_id' => (int) $this->agencyId],
        );

        $this->dispatch('task-saved');
        $this->dispatch('toast', message: __('tasks.technician_modal.agency_saved'));
        $this->open = false;
    }

    public function editAbsence(string $start): void
    {
        $this->authorize('manage', Task::class);
        $this->authorize('use-feature', Feature::Absences);

        $this->open = false;
        $this->dispatch('task-open', userId: $this->userId, date: $start)->to(TaskEditor::class);
    }

    public function askRemoveAbsence(string $start, string $end): void
    {
        $this->authorize('manage', Task::class);
        $this->authorize('use-feature', Feature::Absences);

        $this->dispatch('confirm-action', message: __('absences.remove_confirm'), method: 'removeAbsence', params: [$start, $end], componentId: $this->getId());
    }

    public function removeAbsence(RemoveAbsencePeriod $removePeriod, string $start, string $end): void
    {
        $this->authorize('manage', Task::class);
        $this->authorize('use-feature', Feature::Absences);

        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($from->gt($to)) {
            return;
        }

        $removePeriod->handle(User::query()->findOrFail($this->userId), $from, $to);
        $this->dispatch('task-saved');
        $this->dispatch('absences-changed');
    }

    public function render(AbsenceCalendar $calendar): View
    {
        if (! $this->open) {
            return view('livewire.planning.technician-editor', ['person' => null]);
        }

        $monday = CarbonImmutable::parse($this->week);
        $periods = $calendar->periodsForUsers([(int) $this->userId], $monday->subDays(400), $monday->addDays(400))[(int) $this->userId] ?? [];

        return view('livewire.planning.technician-editor', [
            'person' => User::query()->find($this->userId),
            'monday' => $monday,
            'agencies' => Agency::query()->orderBy('sort_order')->get(),
            'canChangeAgency' => auth()->user()?->can('update', User::class) === true,
            'canManageAbsences' => Gate::allows('manage', Absence::class),
            'periods' => collect($periods)->reject(fn (array $period) => $period['type'] === AbsenceType::Absent)->sortByDesc(fn (array $period) => $period['start']->toDateString())->values(),
        ]);
    }

    private function parseMonday(string $week): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $week) : false;
        abort_if($date === false, 404);

        return $date->startOfWeek();
    }
}
