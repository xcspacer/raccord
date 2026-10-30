<?php

namespace App\Livewire\DayPunch;

use App\Models\User;
use App\Support\DayPunchRules;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Blocking « Commencer la journée » window shown to a technician who has not started a working day.
 * The GPS and the request itself are handled by day-punch.js.
 */
class Gate extends Component
{
    #[On('day-punch-changed')]
    public function refreshGate(): void
    {
        // Re-evaluated on render.
    }

    public function render(DayPunchRules $rules): View
    {
        $user = auth()->user();

        return view('livewire.day-punch.gate', [
            'visible' => $user instanceof User && $rules->mustStart($user),
            'now' => $rules->today()->setTimezone(config('platform.timezone')),
        ]);
    }
}
