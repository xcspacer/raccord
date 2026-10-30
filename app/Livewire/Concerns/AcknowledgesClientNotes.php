<?php

namespace App\Livewire\Concerns;

use App\ClientPortal\ClientRemarks;
use App\Models\ClientRemark;
use App\Models\User;

/**
 * « Prendre en compte » a client portal note from an audit page; the client is told in its portal.
 */
trait AcknowledgesClientNotes
{
    public function acknowledgeClientNote(int $remarkId, ClientRemarks $remarks): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $remarks->acknowledge($user, ClientRemark::query()->with('audit')->findOrFail($remarkId));
        $this->dispatch('toast', message: __('client_portal.staff.acknowledged'));
        $this->dispatch('client-notes-changed');
    }
}
