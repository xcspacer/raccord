<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\DecideRepairs;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditPhoto;
use App\Models\AuditValidationRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Decision screen of a repairs request (phase 2), inside the request page: each repair sent (before and after photos,
 * action, checks, observations), the recheck attached if any, and the verdict per repair — accepted, or refused with a
 * reason. Once decided, it shows the verdicts (the first decision recorded wins).
 */
class RepairDecisionForm extends Component
{
    #[Locked]
    public int $requestId;

    /** @var array<int|string, string> Verdict per occurrence id: « accepted » or « refused ». */
    public array $verdicts = [];

    /** @var array<int|string, string> Reason per refused occurrence id. */
    public array $reasons = [];

    public string $comment = '';

    public function mount(int $requestId): void
    {
        $request = AuditValidationRequest::query()->with('submission')->findOrFail($requestId);
        $this->authorize('view', $request);
        abort_unless($request->submission->kind === AuditSubmissionKind::Repair, 404);
        $this->requestId = $request->id;
    }

    public function decide(DecideRepairs $decide): void
    {
        $request = $this->request();
        $this->authorize('decide', $request);
        $this->resetErrorBag();

        $this->validate([
            'verdicts' => ['array'],
            'verdicts.*' => ['in:accepted,refused'],
            'reasons' => ['array'],
            'reasons.*' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $verdicts = [];

        foreach ($this->verdicts as $id => $verdict) {
            $verdicts[(int) $id] = ['accepted' => $verdict === 'accepted', 'reason' => (string) ($this->reasons[$id] ?? '')];
        }

        try {
            $decide->handle($user, $request, $verdicts, $this->comment);
        } catch (WorkflowException $exception) {
            $this->addError('decision', $exception->translated());

            return;
        }

        $this->dispatch('toast', message: __('design_office_workflow.repairs.decided'));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'verdicts' => __('design_office_workflow.repairs.verdicts'),
            'verdicts.*' => __('design_office_workflow.repairs.verdicts'),
            'reasons' => __('design_office_workflow.repairs.item_reason'),
            'reasons.*' => __('design_office_workflow.repairs.item_reason'),
            'comment' => __('design_office_workflow.repairs.global_reason'),
        ];
    }

    public function render(): View
    {
        $request = $this->request()->load(['box.audit', 'submission', 'decision.type', 'decision.decider', 'decision.repairItems']);
        /** @var list<array{id: int, reference: string, nature: string, type: string, description: string, action: string|null, checks: string|null, observations: string|null, before: list<int>, after: list<int>}> $items */
        $items = $request->submission->snapshot['items'] ?? [];
        $photoIds = array_values(array_unique(array_merge([], ...array_map(fn (array $item) => [...$item['before'], ...$item['after']], $items))));

        return view('livewire.design-office-workflow.repair-decision-form', [
            'request' => $request,
            'items' => $items,
            'recheck' => $request->submission->snapshot['recheck'] ?? null,
            'photos' => AuditPhoto::query()->whereIn('id', $photoIds)->get(['id'])->keyBy('id'),
            'verdictsByItem' => $request->decision?->repairItems->keyBy('audit_occurrence_id') ?? collect(),
        ]);
    }

    private function request(): AuditValidationRequest
    {
        return AuditValidationRequest::query()->findOrFail($this->requestId);
    }
}
