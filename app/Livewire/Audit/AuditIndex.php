<?php

namespace App\Livewire\Audit;

use App\Audit\ArchiveAudit;
use App\Audit\AuditOperationException;
use App\Audit\AuditOverview;
use App\Audit\BoxName;
use App\ClientPortal\ClientRemarks;
use App\Enums\AuditMode;
use App\Livewire\Concerns\AcknowledgesClientNotes;
use App\Livewire\DayPunch\DeclareEndModal;
use App\Models\Audit;
use App\Models\User;
use App\Support\DayPunchRules;
use App\Support\Today;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Audit » start page: the cabinets to audit with their state and progress, for the mode chosen in the menu.
 */
class AuditIndex extends Component
{
    use AcknowledgesClientNotes;

    #[Locked]
    public string $mode = 'full';

    public function mount(string $mode): void
    {
        $this->authorize('viewAny', Audit::class);

        $this->mode = in_array($mode, ['optical', 'full'], true) ? $mode : 'full';
    }

    public string $newPm = '';

    /** The « Nouveau PM » window is open. */
    public bool $addingPm = false;

    public function askNewPm(): void
    {
        $this->authorize('manage', Audit::class);
        $this->addingPm = true;
        $this->newPm = '';
        $this->resetErrorBag('newPm');
    }

    public function cancelNewPm(): void
    {
        $this->addingPm = false;
        $this->newPm = '';
        $this->resetErrorBag('newPm');
    }

    /**
     * Opens the audit of a cabinet from its number (« 153 » becomes « PM153 »); a cabinet never audited is opened on
     * its configuration.
     */
    public function openPm(): mixed
    {
        $this->authorize('manage', Audit::class);
        $this->validate(['newPm' => ['required', 'string', 'max:30']]);

        $reference = BoxName::cabinetKey(preg_match('/^\d+$/', trim($this->newPm)) === 1 ? 'PM'.str_pad(trim($this->newPm), 3, '0', STR_PAD_LEFT) : $this->newPm);

        if ($reference === '') {
            $this->addError('newPm', __('audit.errors.invalid_cabinet'));

            return null;
        }

        if (Audit::query()->where('cabinet_key', $reference)->exists()) {
            $this->dispatch('toast', message: __('audit.pm_exists'));
        }

        return $this->redirectRoute('audits.show', ['cabinet' => $reference, 'mode' => $this->mode], navigate: true);
    }

    /**
     * Opens the audit of a card; a technician whose previous day is still open declares its end first.
     */
    public function open(string $cabinet, DayPunchRules $rules): mixed
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $previous = $rules->previousOpen($user);

        if ($previous !== null) {
            $this->dispatch('declare-end-open', date: $previous->date->toDateString(), continueTo: route('audits.show', ['cabinet' => BoxName::cabinetKey($cabinet)], false))->to(DeclareEndModal::class);

            return null;
        }

        return $this->redirectRoute('audits.show', ['cabinet' => BoxName::cabinetKey($cabinet)], navigate: true);
    }

    /** Cabinet whose card asked to be deleted. */
    #[Locked]
    public ?string $archiving = null;

    public string $archiveCode = '';

    public function askArchive(string $cabinet): void
    {
        $this->authorize('manage', Audit::class);
        $this->archiving = BoxName::cabinetKey($cabinet) ?: null;
        $this->archiveCode = '';
        $this->resetErrorBag();
    }

    public function cancelArchive(): void
    {
        $this->archiving = null;
        $this->archiveCode = '';
    }

    /**
     * Archives the audit of a card (as on its page) once the person has typed the reference of the cabinet.
     */
    public function archive(ArchiveAudit $archive): void
    {
        $this->authorize('manage', Audit::class);
        abort_if($this->archiving === null, 404);
        $this->resetErrorBag();

        if (mb_strtoupper(trim($this->archiveCode)) !== mb_strtoupper($this->archiving)) {
            $this->addError('archiveCode', __('audit.archive.mismatch'));

            return;
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $archive->cabinet($user, $this->archiving);
        } catch (AuditOperationException $exception) {
            $this->addError('archiveCode', __('audit.errors.'.$exception->reason));

            return;
        }

        $this->cancelArchive();
        $this->dispatch('toast', message: __('audit.archive.done'));
    }

    public function render(AuditOverview $overview, ClientRemarks $remarks): View
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return view('livewire.audit.audit-index', [
            'cards' => $overview->for($user, AuditMode::from($this->mode), Today::date()),
            'isManager' => $user->can('manage', Audit::class),
            'clientNotes' => $remarks->pendingFor($user),
        ])->layout('components.layouts.app', ['title' => __('navigation.items.audits_'.($this->mode === 'optical' ? 'optical' : 'full').'.label'), 'page' => 'audits']);
    }
}
