<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\RepairPlanning;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditWorkflow;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Phase 2 — Reprises » (supervisors): for one audit in the design office workflow, its phase and the button that
 * opens phase 2, the boxes with something to repair and their intervention, the planning form (date, responsible,
 * other technicians, note) and the interventions with their cancellation (reason required).
 */
class RepairsPage extends Component
{
    #[Url(as: 'audit', except: 0)]
    public int $auditId = 0;

    #[Locked]
    public ?int $planningBoxId = null;

    public string $plannedOn = '';

    public string $responsibleId = '';

    /** @var list<string> */
    public array $memberIds = [];

    public string $note = '';

    #[Locked]
    public ?int $cancellingId = null;

    public string $cancellationReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditIntervention::class);

        if ($this->audit() === null) {
            $this->auditId = (int) ($this->audits()->first()->id ?? 0);
        }
    }

    public function updatedAuditId(): void
    {
        $this->authorize('viewAny', AuditIntervention::class);
        $this->stopPlanning();
        $this->closeCancel();
    }

    public function openPhase(RepairPlanning $planning): void
    {
        $audit = $this->audit() ?? abort(404);
        $this->authorize('openPhase', [AuditIntervention::class, $audit]);

        $this->attempt(function () use ($planning, $audit): void {
            $summary = $planning->openPhase($this->user(), $audit);
            $this->dispatch('toast', message: __('design_office_workflow.repairs.phase_opened', $summary));
        });
    }

    public function startPlanning(int $boxId): void
    {
        $audit = $this->audit() ?? abort(404);
        $this->authorize('plan', [AuditIntervention::class, $audit]);
        $box = AuditBox::query()->where('audit_id', $audit->id)->findOrFail($boxId);

        $this->closeCancel();
        $this->planningBoxId = $box->id;
        $this->plannedOn = now()->timezone((string) config('platform.timezone'))->toDateString();
        $this->responsibleId = '';
        $this->memberIds = [];
        $this->note = '';
        $this->resetErrorBag();
    }

    public function stopPlanning(): void
    {
        $this->planningBoxId = null;
        $this->resetErrorBag();
    }

    public function plan(RepairPlanning $planning): void
    {
        $audit = $this->audit() ?? abort(404);
        $this->authorize('plan', [AuditIntervention::class, $audit]);
        $box = AuditBox::query()->where('audit_id', $audit->id)->findOrFail((int) $this->planningBoxId);

        $this->validate([
            'plannedOn' => ['required', 'date_format:Y-m-d'],
            'responsibleId' => ['required', 'integer', 'min:1'],
            'memberIds' => ['array', 'max:20'],
            'memberIds.*' => ['integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->attempt(function () use ($planning, $box): void {
            $planning->plan($this->user(), $box, $this->plannedOn, (int) $this->responsibleId, array_map(intval(...), $this->memberIds), $this->note);
            $this->planningBoxId = null;
            $this->dispatch('toast', message: __('design_office_workflow.repairs.planned'));
        });
    }

    public function askCancel(int $interventionId): void
    {
        $intervention = $this->intervention($interventionId);
        $this->authorize('cancel', $intervention);

        $this->stopPlanning();
        $this->cancellingId = $intervention->id;
        $this->cancellationReason = '';
    }

    public function closeCancel(): void
    {
        $this->cancellingId = null;
        $this->cancellationReason = '';
    }

    public function cancel(RepairPlanning $planning): void
    {
        $intervention = $this->intervention((int) $this->cancellingId);
        $this->authorize('cancel', $intervention);

        $this->validate(['cancellationReason' => ['required', 'string', 'min:'.RepairPlanning::MIN_REASON, 'max:2000']]);

        $this->attempt(function () use ($planning, $intervention): void {
            $planning->cancel($this->user(), $intervention, $this->cancellationReason);
            $this->closeCancel();
            $this->dispatch('toast', message: __('design_office_workflow.repairs.cancelled'));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'plannedOn' => __('design_office_workflow.repairs.date'),
            'responsibleId' => __('design_office_workflow.repairs.responsible'),
            'memberIds' => __('design_office_workflow.repairs.members'),
            'memberIds.*' => __('design_office_workflow.repairs.members'),
            'note' => __('design_office_workflow.repairs.note'),
            'cancellationReason' => __('design_office_workflow.repairs.cancellation_reason'),
        ];
    }

    public function render(RepairPlanning $planning): View
    {
        $audit = $this->audit();
        $pool = $audit === null ? new Collection : $planning->pool($audit);

        return view('livewire.design-office-workflow.repairs-page', [
            'audits' => $this->audits(),
            'audit' => $audit,
            'boxCount' => $audit === null ? 0 : $audit->boxes()->whereNull('removed_at')->count(),
            'pool' => $pool,
            'planningBox' => $this->planningBoxId === null ? null : $pool->firstWhere('id', $this->planningBoxId),
            'technicians' => $audit === null || $this->planningBoxId === null ? new Collection : $planning->eligibleTechnicians($audit),
            'interventions' => $audit === null ? new Collection : AuditIntervention::query()->where('audit_id', $audit->id)
                ->with(['box', 'responsible', 'members'])->orderByDesc('planned_on')->orderByDesc('id')->limit(300)->get(),
            'cancelling' => $this->cancellingId === null ? null : AuditIntervention::query()->with('box')->find($this->cancellingId),
        ])->layout('components.layouts.app', ['title' => __('design_office_workflow.repairs.title'), 'page' => 'design-office']);
    }

    /**
     * Audits in the design office workflow not closed yet, the closed ones with an occurrence declared by their client
     * still to repair, plus the one shown.
     *
     * @return Collection<int, Audit>
     */
    private function audits(): Collection
    {
        return Audit::query()->where('workflow', AuditWorkflow::DesignOffice)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhereKey($this->auditId)
                ->orWhereHas('occurrences', fn ($occurrences) => RepairPlanning::repairable($occurrences->where('source', AuditOccurrenceSource::Client))))
            ->orderBy('cabinet_key')->get(['id', 'cabinet_key', 'phase', 'closed_at']);
    }

    private function audit(): ?Audit
    {
        return $this->auditId === 0 ? null : Audit::query()->where('workflow', AuditWorkflow::DesignOffice)->find($this->auditId);
    }

    private function intervention(int $id): AuditIntervention
    {
        $audit = $this->audit() ?? abort(404);

        return AuditIntervention::query()->where('audit_id', $audit->id)->findOrFail($id);
    }

    /**
     * @param  callable(): void  $action
     */
    private function attempt(callable $action): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->addError('action', $exception->translated());
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
