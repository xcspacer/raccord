<?php

namespace App\Livewire\DayPunch;

use App\Actions\DayPunch\RecordDayPunch;
use App\Exceptions\DayPunchException;
use App\Models\DayPunch;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * « Déclarer la fin »: the person types the end time of a day they forgot to finish. Opened before starting an
 * audit, it then carries on to that audit once the day is closed.
 */
class DeclareEndModal extends Component
{
    public bool $open = false;

    #[Locked]
    public string $date = '';

    public string $time = '';

    /** Page of this application to go on to once the day is declared. */
    #[Locked]
    public ?string $continueTo = null;

    #[On('declare-end-open')]
    public function openFor(string $date, ?string $continueTo = null): void
    {
        $this->authorize('update', $this->punch($date));

        $this->date = $date;
        $this->continueTo = $this->isInternalPath($continueTo) ? $continueTo : null;
        $this->time = '';
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function declare(RecordDayPunch $record): void
    {
        $user = $this->user();
        $this->authorize('update', $this->punch($this->date));
        $this->validate(['time' => ['required', 'date_format:H:i']], [], ['time' => __('day_punch.declare.time')]);

        try {
            $record->declareEnd($user, $this->date, $this->time);
        } catch (DayPunchException $exception) {
            $this->addError('time', __('day_punch.errors.'.$exception->reason));

            return;
        }

        $this->open = false;
        $this->dispatch('day-punch-changed');
        $this->dispatch('toast', message: __('day_punch.toast.declared'));

        if ($this->continueTo !== null) {
            $this->redirect($this->continueTo, navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.day-punch.declare-end-modal', ['punch' => $this->open ? $this->punch($this->date) : null]);
    }

    private function punch(string $date): DayPunch
    {
        return DayPunch::query()->where('user_id', $this->user()->id)->whereDate('date', $date)->whereNull('end_at')->firstOrFail();
    }

    private function isInternalPath(?string $path): bool
    {
        return $path !== null && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
