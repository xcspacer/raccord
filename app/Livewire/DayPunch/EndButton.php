<?php

namespace App\Livewire\DayPunch;

use App\Models\User;
use App\Support\DayPunchRules;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * « Finir la journée » button of the header, shown while today's day is running, and a reminder to declare
 * the end of an earlier day left open.
 */
class EndButton extends Component
{
    #[On('day-punch-changed')]
    public function refreshButton(): void
    {
        // Re-evaluated on render.
    }

    public function openDeclaration(string $date): void
    {
        $this->dispatch('declare-end-open', date: $date)->to(DeclareEndModal::class);
    }

    public function render(DayPunchRules $rules): View
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        return view('livewire.day-punch.end-button', [
            'running' => $user ? $rules->runningToday($user) : null,
            'previous' => $user ? $rules->previousOpen($user) : null,
        ]);
    }
}
