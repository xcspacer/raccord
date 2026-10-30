<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxPositions;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Models\AuditBox;
use App\Models\AuditBoxPosition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Positions of a box for the design office: the official one (with « J'y vais », through the same choice of
 * navigation app as everywhere else), the one of the map file, the one taken on site and the corrections proposed
 * from the field. The design office or a supervisor makes any of them official, or rejects a proposal with a reason.
 */
class BoxPositionsPanel extends Component
{
    #[Locked]
    public int $boxId;

    /** Proposal being rejected (its reason is asked for). */
    public int $rejectingId = 0;

    public string $rejectionReason = '';

    public bool $navigating = false;

    public function mount(int $boxId): void
    {
        $this->boxId = $boxId;
        $this->authorize('viewWorkflow', $this->box());
    }

    public function approve(int $positionId, BoxPositions $positions): void
    {
        $box = $this->box();
        $this->authorize('decidePosition', $box);
        $this->resetErrorBag();

        try {
            $positions->approve($this->user(), $this->position($box, $positionId));
        } catch (WorkflowException $exception) {
            $this->addError('positions', $exception->translated());

            return;
        }

        $this->dispatch('toast', message: __('design_office_workflow.positions.approved'));
    }

    public function startRejecting(int $positionId): void
    {
        $this->rejectingId = $positionId;
        $this->rejectionReason = '';
        $this->resetErrorBag();
    }

    public function cancelRejecting(): void
    {
        $this->reset('rejectingId', 'rejectionReason');
    }

    public function reject(BoxPositions $positions): void
    {
        $box = $this->box();
        $this->authorize('decidePosition', $box);
        $this->validate(['rejectionReason' => ['required', 'string', 'min:'.BoxPositions::MIN_REJECTION_REASON, 'max:500']]);

        try {
            $positions->reject($this->user(), $this->position($box, $this->rejectingId), $this->rejectionReason);
        } catch (WorkflowException $exception) {
            $this->addError('positions', $exception->translated());

            return;
        }

        $this->reset('rejectingId', 'rejectionReason');
        $this->dispatch('toast', message: __('design_office_workflow.positions.rejected'));
    }

    public function openNavigation(): void
    {
        $this->navigating = true;
    }

    public function closeNavigation(): void
    {
        $this->navigating = false;
    }

    public function render(BoxPositions $positions): View
    {
        $box = $this->box();
        $summary = $positions->summary($box);

        return view('livewire.design-office-workflow.box-positions-panel', [
            'box' => $box,
            'summary' => $summary,
            'canDecide' => $this->user()->can('decidePosition', $box) && ! $box->audit->isClosed(),
            'navigationLinks' => $summary['official'] === null ? [] : BoxPositions::navigationLinks($summary['official']),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['rejectionReason' => __('design_office_workflow.positions.rejection_reason')];
    }

    private function position(AuditBox $box, int $positionId): AuditBoxPosition
    {
        return AuditBoxPosition::query()->where('audit_box_id', $box->id)->findOrFail($positionId);
    }

    private function box(): AuditBox
    {
        return AuditBox::query()->with('audit')->findOrFail($this->boxId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
