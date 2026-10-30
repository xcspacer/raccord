<?php

namespace App\Livewire\Absences;

use App\Absences\AbsenceCalendar;
use App\Absences\AbsenceExport;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Absences et Congés": one card per person still in the planning, for a month of the current year.
 */
class AbsenceIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $month = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Absence::class);

        $this->month = $this->selectedMonth(CarbonImmutable::today(config('platform.timezone')))->format('Y-m');
    }

    #[On('absences-changed')]
    public function refreshList(): void
    {
        // Re-render the counts and today's badges.
    }

    /**
     * « Exporter Excel » of the selected month; a notice instead when nobody was absent that month.
     */
    public function export(AbsenceExport $export): ?StreamedResponse
    {
        $this->authorize('viewAny', Absence::class);

        $month = $this->selectedMonth(CarbonImmutable::today(config('platform.timezone')));
        $rows = $export->rows($month);

        if ($rows === []) {
            $this->dispatch('toast', message: __('absences.export.empty', ['month' => mb_strtolower($month->translatedFormat('F Y'))]));

            return null;
        }

        $spreadsheet = $export->build($month, $rows);

        return response()->streamDownload(function () use ($spreadsheet): void {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $export->fileName($month), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render(AbsenceCalendar $calendar): View
    {
        $today = CarbonImmutable::today(config('platform.timezone'));
        $month = $this->selectedMonth($today);
        $search = trim($this->search);

        $people = User::query()
            ->where(fn ($query) => $query->where('is_plannable', true)->orWhereHas('roles', fn ($query) => $query->where('name', Role::Technician->value)))
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>', $today))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('username', 'like', '%'.$search.'%')))
            ->orderBy('planning_order')
            ->orderBy('name')
            ->get();

        $calendars = $people->mapWithKeys(fn (User $user) => [$user->id => $calendar->forMonth($user->id, $month)]);
        $monthName = mb_strtolower($month->translatedFormat('F Y'));

        return view('livewire.absences.absence-index', [
            'people' => $people,
            'calendars' => $calendars,
            'isCurrentMonth' => $month->isSameMonth($today),
            'monthValue' => $month->format('Y-m'),
            'months' => collect(range(1, 12))->mapWithKeys(function (int $number) use ($today) {
                $option = $today->setDate($today->year, $number, 1);

                return [$option->format('Y-m') => mb_convert_case($option->translatedFormat('F Y'), MB_CASE_TITLE)];
            }),
            'countText' => trans_choice($search !== '' ? 'absences.count_found' : 'absences.count', $people->count(), [
                'count' => $people->count(),
                'with' => $calendars->filter(fn (array $data) => $data['total'] > 0)->count(),
                'month' => $monthName,
            ]),
        ])->layout('components.layouts.app', ['title' => __('absences.title'), 'page' => 'absences']);
    }

    private function selectedMonth(CarbonImmutable $today): CarbonImmutable
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $this->month) : null;

        return $month !== null && $month->year === $today->year ? $month : $today->startOfMonth();
    }
}
