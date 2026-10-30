<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\AnomalyCatalogue;
use App\DesignOfficeWorkflow\DecisionCatalogue;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditAnomalyType;
use App\Models\AuditDecisionType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Catalogues of the design office workflow, kept by the supervisors: the anomaly types (label, nature fixed once
 * created, switched off instead of deleted) and the decisions of the design office (label, effect fixed once created,
 * whether a reason and points to correct are required, switched off instead of deleted). Built-in entries show their
 * translated label until a label is typed.
 */
class CataloguesPage extends Component
{
    public string $anomalyLabel = '';

    public string $anomalyNature = '';

    #[Locked]
    public ?int $editingAnomalyId = null;

    public string $editingAnomalyLabel = '';

    public string $decisionLabel = '';

    public string $decisionOutcome = '';

    public bool $decisionRequiresReason = true;

    public bool $decisionRequiresTargets = false;

    #[Locked]
    public ?int $editingDecisionId = null;

    public string $editingDecisionLabel = '';

    public bool $editingDecisionRequiresReason = true;

    public bool $editingDecisionRequiresTargets = false;

    public function mount(): void
    {
        $this->authorize('viewAny', AuditAnomalyType::class);
        $this->authorize('viewAny', AuditDecisionType::class);
    }

    public function addAnomalyType(AnomalyCatalogue $catalogue): void
    {
        $this->authorize('create', AuditAnomalyType::class);
        $this->validate([
            'anomalyLabel' => ['required', 'string', 'max:160'],
            'anomalyNature' => ['required', Rule::enum(AuditAnomalyNature::class)],
        ]);

        if ($this->attempt(fn () => $catalogue->create($this->user(), $this->anomalyLabel, AuditAnomalyNature::from($this->anomalyNature)), 'anomaly')) {
            $this->reset('anomalyLabel', 'anomalyNature');
        }
    }

    public function editAnomalyType(int $typeId): void
    {
        $type = AuditAnomalyType::query()->findOrFail($typeId);
        $this->authorize('update', $type);
        $this->editingAnomalyId = $type->id;
        $this->editingAnomalyLabel = (string) $type->label;
        $this->resetErrorBag();
    }

    public function renameAnomalyType(AnomalyCatalogue $catalogue): void
    {
        $type = AuditAnomalyType::query()->findOrFail((int) $this->editingAnomalyId);
        $this->authorize('update', $type);
        $this->validate(['editingAnomalyLabel' => ['nullable', 'string', 'max:160']]);

        if ($this->attempt(fn () => $catalogue->rename($this->user(), $type, $this->editingAnomalyLabel), 'anomaly')) {
            $this->cancelAnomalyEdit();
        }
    }

    public function cancelAnomalyEdit(): void
    {
        $this->editingAnomalyId = null;
        $this->editingAnomalyLabel = '';
    }

    public function toggleAnomalyType(AnomalyCatalogue $catalogue, int $typeId): void
    {
        $type = AuditAnomalyType::query()->findOrFail($typeId);
        $this->authorize('update', $type);

        $this->attempt(fn () => $catalogue->setActive($this->user(), $type, ! $type->is_active), 'anomaly');
    }

    public function addDecisionType(DecisionCatalogue $catalogue): void
    {
        $this->authorize('create', AuditDecisionType::class);
        $this->validate([
            'decisionLabel' => ['required', 'string', 'max:120'],
            'decisionOutcome' => ['required', Rule::enum(AuditDecisionOutcome::class)],
            'decisionRequiresReason' => ['boolean'],
            'decisionRequiresTargets' => ['boolean'],
        ]);

        $created = $this->attempt(fn () => $catalogue->create(
            $this->user(), $this->decisionLabel, AuditDecisionOutcome::from($this->decisionOutcome), $this->decisionRequiresReason, $this->decisionRequiresTargets,
        ), 'decision');

        if ($created) {
            $this->reset('decisionLabel', 'decisionOutcome', 'decisionRequiresReason', 'decisionRequiresTargets');
        }
    }

    public function editDecisionType(int $typeId): void
    {
        $type = AuditDecisionType::query()->findOrFail($typeId);
        $this->authorize('update', $type);
        $this->editingDecisionId = $type->id;
        $this->editingDecisionLabel = (string) $type->label;
        $this->editingDecisionRequiresReason = $type->requires_reason;
        $this->editingDecisionRequiresTargets = $type->requires_targets;
        $this->resetErrorBag();
    }

    public function updateDecisionType(DecisionCatalogue $catalogue): void
    {
        $type = AuditDecisionType::query()->findOrFail((int) $this->editingDecisionId);
        $this->authorize('update', $type);
        $this->validate([
            'editingDecisionLabel' => ['nullable', 'string', 'max:120'],
            'editingDecisionRequiresReason' => ['boolean'],
            'editingDecisionRequiresTargets' => ['boolean'],
        ]);

        $updated = $this->attempt(fn () => $catalogue->update(
            $this->user(), $type, $this->editingDecisionLabel, $this->editingDecisionRequiresReason, $this->editingDecisionRequiresTargets,
        ), 'decision');

        if ($updated) {
            $this->cancelDecisionEdit();
        }
    }

    public function cancelDecisionEdit(): void
    {
        $this->editingDecisionId = null;
        $this->editingDecisionLabel = '';
    }

    public function toggleDecisionType(DecisionCatalogue $catalogue, int $typeId): void
    {
        $type = AuditDecisionType::query()->findOrFail($typeId);
        $this->authorize('update', $type);

        $this->attempt(fn () => $catalogue->setActive($this->user(), $type, ! $type->is_active), 'decision');
    }

    public function render(DecisionCatalogue $decisions): View
    {
        return view('livewire.design-office-workflow.catalogues-page', [
            'anomalyTypes' => AuditAnomalyType::query()->orderBy('nature')->orderBy('position')->orderBy('id')->get(),
            'decisionTypes' => AuditDecisionType::query()->orderBy('submission_kind')->orderBy('position')->orderBy('id')->get(),
            'natures' => AuditAnomalyNature::cases(),
            'outcomes' => AuditDecisionOutcome::cases(),
            'kinds' => AuditSubmissionKind::cases(),
            'builtIn' => fn (AuditDecisionType $type) => $decisions->isBuiltIn($type),
        ])->layout('components.layouts.app', ['title' => __('navigation.items.design_office_catalogues.label'), 'page' => 'design-office']);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'anomalyLabel' => __('design_office_workflow.catalogues.label'),
            'anomalyNature' => __('design_office_workflow.catalogues.nature'),
            'editingAnomalyLabel' => __('design_office_workflow.catalogues.label'),
            'decisionLabel' => __('design_office_workflow.catalogues.label'),
            'decisionOutcome' => __('design_office_workflow.catalogues.outcome'),
            'decisionRequiresReason' => __('design_office_workflow.catalogues.requires_reason'),
            'decisionRequiresTargets' => __('design_office_workflow.catalogues.requires_targets'),
            'editingDecisionLabel' => __('design_office_workflow.catalogues.label'),
            'editingDecisionRequiresReason' => __('design_office_workflow.catalogues.requires_reason'),
            'editingDecisionRequiresTargets' => __('design_office_workflow.catalogues.requires_targets'),
        ];
    }

    /**
     * @param  callable(): mixed  $action
     * @param  'anomaly'|'decision'  $section
     */
    private function attempt(callable $action, string $section): bool
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->addError($section, $exception->translated());

            return false;
        }

        $this->dispatch('toast', message: __('design_office_workflow.catalogues.saved'));

        return true;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
