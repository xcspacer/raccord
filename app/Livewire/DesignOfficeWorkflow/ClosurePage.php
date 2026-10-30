<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\CloseAudit;
use App\DesignOfficeWorkflow\ClosingChecks;
use App\DesignOfficeWorkflow\ReopenAudit;
use App\DesignOfficeWorkflow\Reserves;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Enums\AuditReserveKind;
use App\Enums\AuditWorkflow;
use App\Models\Audit;
use App\Models\AuditOccurrence;
use App\Models\AuditReopening;
use App\Models\AuditReserve;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Closure & dossier » of an audit of the design office workflow: its conformity summary, the closing checks
 * (blocking points and warnings), the open anomalies a reserve can cover (the design office proposes, a supervisor
 * accepts or refuses the proposal with a reason the proposer reads here), the accepted reserves (a supervisor closes the follow-up ones), the closure with its express confirmation,
 * the formal reopening of a closed audit, and the slots where the documents and the official dossier versions show.
 */
class ClosurePage extends Component
{
    #[Url(as: 'pm', except: '')]
    public string $cabinet = '';

    /** Open dialog: « propose », « accept », « refuse », « close_reserve », « close » or « reopen ». */
    public string $dialog = '';

    /** Anomaly (propose, accept, refuse) or reserve (close_reserve) the open dialog is about. */
    public int $targetId = 0;

    public string $reason = '';

    public string $kind = 'follow_up';

    public bool $confirmed = false;

    /** @var list<int> Boxes to take up again on a reopening. */
    public array $reopenBoxes = [];

    /**
     * Without a known audit asked for, the page opens on the first one of the list.
     */
    public function mount(): void
    {
        $this->authorize('viewClosure', Audit::class);

        if (! $this->audits()->where('cabinet_key', $this->cabinet)->exists()) {
            $this->cabinet = (string) $this->audits()->orderBy('cabinet_key')->value('cabinet_key');
        }
    }

    public function updatedCabinet(): void
    {
        $this->cancel();
    }

    public function openDialog(string $dialog, int $targetId = 0): void
    {
        $this->authorize('viewClosure', Audit::class);
        abort_unless(in_array($dialog, ['propose', 'accept', 'refuse', 'close_reserve', 'close', 'reopen'], true), 404);

        $this->cancel();
        $this->dialog = $dialog;
        $this->targetId = $targetId;
    }

    public function cancel(): void
    {
        $this->reset('dialog', 'targetId', 'reason', 'kind', 'confirmed', 'reopenBoxes');
        $this->resetErrorBag();
    }

    public function propose(Reserves $reserves): void
    {
        $this->authorize('propose', AuditReserve::class);
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $occurrence = $this->occurrence();

        $this->attempt(fn () => $reserves->propose($this->user(), $occurrence, $this->reason), __('design_office_workflow.closure.proposed_toast'));
    }

    public function accept(Reserves $reserves): void
    {
        $this->authorize('accept', AuditReserve::class);
        $this->validate(['reason' => ['required', 'string', 'max:2000'], 'kind' => ['required', Rule::enum(AuditReserveKind::class)]]);
        $occurrence = $this->occurrence();

        $this->attempt(function () use ($reserves, $occurrence): string {
            $boxes = $reserves->accept($this->user(), $occurrence, AuditReserveKind::from($this->kind), $this->reason);

            return __('design_office_workflow.closure.accepted_toast', ['boxes' => implode(', ', $boxes)]);
        });
    }

    public function refuse(Reserves $reserves): void
    {
        $this->authorize('accept', AuditReserve::class);
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $occurrence = $this->occurrence();

        $this->attempt(fn () => $reserves->refuse($this->user(), $occurrence, $this->reason), __('design_office_workflow.closure.refused_toast'));
    }

    public function closeReserve(Reserves $reserves): void
    {
        $reserve = AuditReserve::query()->whereKey($this->targetId)->whereHas('box', fn ($boxes) => $boxes->where('audit_id', $this->selected()->id))->firstOrFail();
        $this->authorize('close', $reserve);
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->attempt(fn () => $reserves->close($this->user(), $reserve, $this->reason), __('design_office_workflow.closure.reserve_closed_toast'));
    }

    public function closeAudit(CloseAudit $close): void
    {
        $audit = $this->selected();
        $this->authorize('close', $audit);

        $this->attempt(fn () => $close->handle($this->user(), $audit, $this->confirmed), __('design_office_workflow.closure.closed_toast'));
    }

    public function reopen(ReopenAudit $reopen): void
    {
        $audit = $this->selected();
        $this->authorize('reopen', $audit);
        $this->validate(['reason' => ['required', 'string', 'max:2000'], 'reopenBoxes' => ['array', 'max:5000'], 'reopenBoxes.*' => ['integer']]);

        $this->attempt(fn () => $reopen->handle($this->user(), $audit, $this->reason, array_map('intval', $this->reopenBoxes)), __('design_office_workflow.closure.reopened_toast'));
    }

    public function render(ClosingChecks $checks, Reserves $reserves): View
    {
        $user = $this->user();
        $audit = $this->audits()->where('cabinet_key', $this->cabinet)->with('closer')->first();

        return view('livewire.design-office-workflow.closure-page', [
            'audits' => $this->audits()->orderBy('cabinet_key')->get(['id', 'cabinet_key', 'closed_at']),
            'audit' => $audit,
            'report' => $audit === null ? null : $checks->report($audit),
            'open' => $audit === null ? new Collection : $reserves->openOccurrences($audit),
            'accepted' => $audit === null ? new Collection : $reserves->accepted($audit),
            'reopenings' => $audit === null ? new Collection : AuditReopening::query()->where('audit_id', $audit->id)->with('reopener')->latest('id')->get(),
            'boxes' => $audit !== null && $this->dialog === 'reopen' ? $checks->boxes($audit) : new Collection,
            'kinds' => AuditReserveKind::cases(),
            'isSupervisor' => WorkflowRoles::isSupervisor($user),
            'canPropose' => $user->can('propose', AuditReserve::class),
        ])->layout('components.layouts.app', ['title' => __('design_office_workflow.closure.title'), 'page' => 'design-office']);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'reason' => __('design_office_workflow.closure.reason'),
            'kind' => __('design_office_workflow.closure.accept_kind'),
            'reopenBoxes' => __('design_office_workflow.closure.reopen_boxes'),
            'reopenBoxes.*' => __('design_office_workflow.closure.reopen_boxes'),
        ];
    }

    /**
     * @return Builder<Audit>
     */
    private function audits(): Builder
    {
        return Audit::query()->where('workflow', AuditWorkflow::DesignOffice);
    }

    private function selected(): Audit
    {
        return $this->audits()->where('cabinet_key', $this->cabinet)->first() ?? abort(404);
    }

    private function occurrence(): AuditOccurrence
    {
        return AuditOccurrence::query()->whereKey($this->targetId)->where('audit_id', $this->selected()->id)->firstOrFail();
    }

    /**
     * @param  callable(): mixed  $action  Returns the message to show when it is a string.
     */
    private function attempt(callable $action, ?string $done = null): void
    {
        $this->resetErrorBag();

        try {
            $result = $action();
        } catch (WorkflowException $exception) {
            $this->addError('dialog', $exception->translated());

            return;
        }

        $this->cancel();
        $this->dispatch('toast', message: is_string($result) ? $result : ($done ?? __('design_office_workflow.queue.saved')));
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
