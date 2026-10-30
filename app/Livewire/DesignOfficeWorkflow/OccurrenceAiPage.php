<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\Ai\AiException;
use App\Ai\OccurrenceTextDrafter;
use App\DesignOfficeWorkflow\AiDrafts;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Models\AuditAiDraft;
use App\Models\AuditOccurrence;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Draft with the AI » for one occurrence: shows exactly what would be sent (the photos of the occurrence, its type and
 * description) and the language of the text, asks the AI only when the person clicks, and lists every draft (internal
 * history). A draft can be corrected, approved — the latest approved text is the one that may be published — or
 * rejected.
 */
class OccurrenceAiPage extends Component
{
    #[Locked]
    public int $occurrenceId;

    /** @var array<int|string, string> Texts being corrected, by draft id. */
    public array $texts = [];

    public function mount(AuditOccurrence $occurrence): void
    {
        $this->authorize('viewAny', [AuditAiDraft::class, $occurrence]);
        $this->occurrenceId = $occurrence->id;
    }

    public function generate(OccurrenceTextDrafter $drafter): void
    {
        $occurrence = $this->occurrence();
        $this->authorize('create', [AuditAiDraft::class, $occurrence]);
        $this->resetErrorBag();

        try {
            $draft = $drafter->draft($this->user(), $occurrence);
        } catch (AiException $exception) {
            $this->addError('generate', __('design_office_workflow.ai.errors.'.$exception->reason));

            return;
        }

        $this->texts[$draft->id] = $draft->text;
        $this->dispatch('toast', message: __('design_office_workflow.ai.generated'));
    }

    public function save(AiDrafts $drafts, int $draftId): void
    {
        $draft = $this->draft($draftId);
        $this->authorize('update', $draft);
        $this->validate(['texts.'.$draftId => ['required', 'string', 'min:'.AiDrafts::MIN_TEXT, 'max:'.AiDrafts::MAX_TEXT]]);

        $this->attempt(fn () => $drafts->edit($this->user(), $draft, (string) $this->texts[$draftId]), __('design_office_workflow.ai.saved'));
    }

    public function approve(AiDrafts $drafts, int $draftId): void
    {
        $draft = $this->draft($draftId);
        $this->authorize('update', $draft);

        $this->attempt(fn () => $drafts->approve($this->user(), $draft), __('design_office_workflow.ai.approved'));
    }

    public function reject(AiDrafts $drafts, int $draftId): void
    {
        $draft = $this->draft($draftId);
        $this->authorize('update', $draft);

        $this->attempt(fn () => $drafts->reject($this->user(), $draft), __('design_office_workflow.ai.rejected'));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['texts.*' => __('design_office_workflow.ai.text')];
    }

    public function render(OccurrenceTextDrafter $drafter, AiDrafts $drafts): View
    {
        $occurrence = $this->occurrence()->load(['audit', 'box', 'type']);
        $history = $occurrence->aiDrafts()->with(['requester:id,name', 'editor:id,name', 'approver:id,name'])->latest()->latest('id')->get();

        foreach ($history as $draft) {
            $this->texts[$draft->id] ??= $draft->text;
        }

        return view('livewire.design-office-workflow.occurrence-ai-page', [
            'occurrence' => $occurrence,
            'enabled' => $drafter->isEnabled(),
            'scope' => $drafter->scope($occurrence),
            'locale' => $drafter->locale($occurrence),
            'history' => $history,
            'published' => $drafts->published($occurrence),
        ])->layout('components.layouts.app', ['title' => __('design_office_workflow.ai.title', ['reference' => $occurrence->reference]), 'page' => 'design-office']);
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->addError('generate', $exception->translated());

            return;
        }

        $this->dispatch('toast', message: $done);
    }

    private function occurrence(): AuditOccurrence
    {
        return AuditOccurrence::query()->findOrFail($this->occurrenceId);
    }

    /**
     * A draft of this page's occurrence; any other one does not exist here.
     */
    private function draft(int $draftId): AuditAiDraft
    {
        return AuditAiDraft::query()->where('audit_occurrence_id', $this->occurrenceId)->findOrFail($draftId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
