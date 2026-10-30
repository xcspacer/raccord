<?php

namespace App\Livewire\ClientPortal;

use App\ClientPortal\ClientRemarks;
use App\Enums\ClientRemarkStatus;
use App\Enums\Feature;
use App\Models\ClientRemark;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Keeps the menu up to date with the client notes: how many wait for this person to take them into account (the
 * audit entries), how many are still untreated (the « Contrôle client » entry), and a toast when new ones arrive.
 */
class PendingNotesAlert extends Component
{
    /** @var list<int>|null */
    #[Locked]
    public ?array $known = null;

    #[On('client-notes-changed')]
    public function refresh(): void {}

    public function render(ClientRemarks $remarks): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $pending = $remarks->pendingFor($user)->pluck('id')->all();
        $fresh = $this->known === null ? [] : array_diff($pending, $this->known);
        $this->known = $pending;

        $control = $user->can('open-feature', Feature::ClientControl)
            ? ClientRemark::query()->where('status', '!=', ClientRemarkStatus::Closed->value)->whereNull('acknowledged_at')->count()
            : 0;

        $this->dispatch('client-notes',
            pending: count($pending),
            control: $control,
            fresh: $fresh === [] ? null : '📝 '.__('client_portal.staff.new', ['count' => count($fresh)]),
        );

        return view('livewire.client-portal.pending-notes-alert');
    }
}
