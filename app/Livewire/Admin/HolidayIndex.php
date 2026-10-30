<?php

namespace App\Livewire\Admin;

use App\Enums\HolidayRule;
use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Jours fériés »: the public holidays of the installation (non-working days for absences and planning).
 */
class HolidayIndex extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $rule = HolidayRule::FixedDate->value;

    public string $month = '';

    public string $day = '';

    public string $easterOffset = '';

    public string $date = '';

    public function mount(): void
    {
        $this->authorize('manage-platform');
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-platform');

        $holiday = PublicHoliday::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $holiday->id;
        $this->name = $holiday->name;
        $this->rule = $holiday->rule->value;
        $this->month = (string) $holiday->month;
        $this->day = (string) $holiday->day;
        $this->easterOffset = (string) $holiday->easter_offset;
        $this->date = (string) $holiday->date?->toDateString();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('manage-platform');

        $rule = HolidayRule::tryFrom($this->rule);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'rule' => ['required', Rule::enum(HolidayRule::class)],
            'month' => [Rule::requiredIf($rule === HolidayRule::FixedDate), 'nullable', 'integer', 'between:1,12'],
            'day' => [Rule::requiredIf($rule === HolidayRule::FixedDate), 'nullable', 'integer', 'between:1,31'],
            'easterOffset' => [Rule::requiredIf($rule === HolidayRule::EasterOffset), 'nullable', 'integer', 'between:-100,100'],
            'date' => [Rule::requiredIf($rule === HolidayRule::SingleDate), 'nullable', 'date_format:Y-m-d'],
        ]);

        if ($rule === HolidayRule::FixedDate && ! checkdate((int) $this->month, (int) $this->day, 2024)) {
            throw ValidationException::withMessages(['day' => __('holidays.invalid_day')]);
        }

        $attributes = [
            'name' => trim($this->name),
            'rule' => $rule,
            'month' => $rule === HolidayRule::FixedDate ? (int) $this->month : null,
            'day' => $rule === HolidayRule::FixedDate ? (int) $this->day : null,
            'easter_offset' => $rule === HolidayRule::EasterOffset ? (int) $this->easterOffset : null,
            'date' => $rule === HolidayRule::SingleDate ? $this->date : null,
        ];

        if ($this->editingId !== null) {
            PublicHoliday::query()->findOrFail($this->editingId)->update($attributes);
        } else {
            PublicHoliday::query()->create($attributes);
        }

        $this->resetForm();
        $this->dispatch('toast', message: __('holidays.saved'));
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-platform');

        PublicHoliday::query()->findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->dispatch('toast', message: __('holidays.deleted'));
    }

    public function render(): View
    {
        $year = CarbonImmutable::today(config('platform.timezone'))->year;

        $holidays = PublicHoliday::query()->get()
            ->map(fn (PublicHoliday $holiday) => ['holiday' => $holiday, 'occurrence' => $holiday->occurrenceIn($year)])
            ->sortBy(fn (array $row) => $row['occurrence']?->toDateString() ?? '9999')
            ->values();

        return view('livewire.admin.holiday-index', [
            'holidays' => $holidays,
            'year' => $year,
            'rules' => HolidayRule::cases(),
            'months' => collect(range(1, 12))->mapWithKeys(fn (int $number) => [$number => mb_convert_case(CarbonImmutable::create(2024, $number, 1)->translatedFormat('F'), MB_CASE_TITLE)]),
        ])->layout('components.layouts.app', ['title' => __('holidays.title'), 'page' => 'holidays']);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'rule', 'month', 'day', 'easterOffset', 'date');
        $this->resetValidation();
    }
}
