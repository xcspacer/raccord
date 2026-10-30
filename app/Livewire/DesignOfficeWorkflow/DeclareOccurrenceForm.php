<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditWorkflow;
use App\Models\Audit;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Declare an occurrence » on the occurrences page and on the page of an occurrence (supervisors): a box of an open
 * PM in the design office workflow, a type of the catalogue, a description, and at least one photo already received
 * for that box as « before » photo. The same rules as on the phone apply (OccurrenceLifecycle::create); the new
 * occurrence opens on its detail page. From an occurrence, the form starts on its PM and box.
 */
class DeclareOccurrenceForm extends Component
{
    public bool $open = false;

    /** PM and box the form starts on (the occurrence whose page it sits on). */
    #[Locked]
    public int $presetAuditId = 0;

    #[Locked]
    public int $presetBoxId = 0;

    public int $auditId = 0;

    public int $boxId = 0;

    public string $typeId = '';

    public string $description = '';

    public string $locationNote = '';

    /** @var list<int|string> */
    public array $photoIds = [];

    public function show(): void
    {
        $this->authorize('createAnyOccurrence', AuditBox::class);
        $this->reset('auditId', 'boxId', 'typeId', 'description', 'locationNote', 'photoIds');
        $this->resetErrorBag();
        $this->open = true;

        if ($this->presetAuditId > 0 && $this->audits()->contains('id', $this->presetAuditId)) {
            $this->auditId = $this->presetAuditId;
            $this->boxId = AuditBox::query()->where('audit_id', $this->presetAuditId)->whereNull('removed_at')->whereKey($this->presetBoxId)->exists() ? $this->presetBoxId : 0;
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetErrorBag();
    }

    public function updatedAuditId(): void
    {
        $this->boxId = 0;
        $this->photoIds = [];
    }

    public function updatedBoxId(): void
    {
        $this->photoIds = [];
    }

    public function save(OccurrenceLifecycle $lifecycle): void
    {
        $this->validate([
            'auditId' => ['required', 'integer', 'min:1'],
            'boxId' => ['required', 'integer', 'min:1'],
            'typeId' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'min:'.OccurrenceLifecycle::MIN_DESCRIPTION, 'max:2000'],
            'locationNote' => ['nullable', 'string', 'max:500'],
            'photoIds' => ['required', 'array', 'min:1', 'max:50'],
            'photoIds.*' => ['integer', 'min:1'],
        ]);

        $audit = $this->audits()->firstWhere('id', $this->auditId) ?? abort(404);
        $box = AuditBox::query()->where('audit_id', $audit->id)->whereNull('removed_at')->findOrFail($this->boxId);
        $this->authorize('createOccurrence', $box);

        try {
            $occurrence = $lifecycle->create($this->user(), $box, (int) $this->typeId, $this->description, array_map(intval(...), $this->photoIds), $this->locationNote);
        } catch (WorkflowException $exception) {
            $this->addError('form', $exception->translated());

            return;
        }

        $this->redirectRoute('design-office.occurrences.show', ['occurrence' => $occurrence->id], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $canDeclare = $user->can('createAnyOccurrence', AuditBox::class);
        $audit = $this->open ? $this->audits()->firstWhere('id', $this->auditId) : null;

        return view('livewire.design-office-workflow.declare-occurrence-form', [
            'canDeclare' => $canDeclare,
            'audits' => $this->open ? $this->audits() : new Collection,
            'boxes' => $audit === null ? new Collection : $audit->boxes()->whereNull('removed_at')->orderBy('name')->get(['id', 'name']),
            'types' => $this->open ? AuditAnomalyType::query()->where('is_active', true)->orderBy('nature')->orderBy('position')->orderBy('id')->get() : new Collection,
            'photos' => $audit === null || $this->boxId === 0 ? new Collection
                : AuditPhoto::query()->where('audit_id', $audit->id)->where('audit_box_id', $this->boxId)->orderBy('folder')->orderBy('captured_at')->get(['id', 'folder', 'subfolder', 'captured_at']),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'auditId' => __('design_office_workflow.occurrences.audit'),
            'boxId' => __('design_office_workflow.occurrences.box'),
            'typeId' => __('design_office_workflow.occurrences.type'),
            'description' => __('design_office_workflow.declare.description'),
            'locationNote' => __('design_office_workflow.declare.location'),
            'photoIds' => __('design_office_workflow.declare.photos'),
            'photoIds.*' => __('design_office_workflow.declare.photos'),
        ];
    }

    /**
     * Open audits of the design office workflow.
     *
     * @return Collection<int, Audit>
     */
    private function audits(): Collection
    {
        return Audit::query()->where('workflow', AuditWorkflow::DesignOffice)->whereNull('closed_at')->orderBy('cabinet_key')->get(['id', 'cabinet_key', 'workflow', 'closed_at']);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
