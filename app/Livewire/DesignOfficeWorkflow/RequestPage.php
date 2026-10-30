<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\DecideInspection;
use App\DesignOfficeWorkflow\ReviewQueue;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditFolder;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditDecisionType;
use App\Models\AuditMissingProof;
use App\Models\AuditPhoto;
use App\Models\AuditValidationRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Decision screen of the inspection of a box: the photos sent (by folder), the occurrences with their before and after
 * photos, the justifications of missing photos to accept or refuse one by one, the decisions of the catalogue, the
 * reason and, for a refusal, what must be corrected. Opening it puts the request in review. Once decided, the screen
 * shows the decision (the first one recorded wins).
 */
class RequestPage extends Component
{
    #[Locked]
    public int $requestId;

    public string $decision = '';

    public string $reason = '';

    public string $targets = '';

    /** @var array<string, string> Verdict per justified folder: « accepted » or « refused ». */
    public array $proofs = [];

    public function mount(AuditValidationRequest $validationRequest, ReviewQueue $queue): void
    {
        $this->authorize('view', $validationRequest);
        $this->requestId = $validationRequest->id;

        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $queue->open($user, $validationRequest);
    }

    public function decide(DecideInspection $decide): void
    {
        $request = $this->request();
        $this->authorize('decide', $request);
        $this->resetErrorBag();

        $this->validate([
            'decision' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'targets' => ['nullable', 'string', 'max:5000'],
            'proofs' => ['array'],
            'proofs.*' => ['in:accepted,refused'],
        ]);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $decide->handle(
                $user,
                $request,
                $this->decision,
                $this->reason,
                preg_split('/\R/', $this->targets) ?: [],
                collect($this->proofs)->map(fn (string $verdict) => $verdict === 'accepted')->all(),
            );
        } catch (WorkflowException $exception) {
            $this->addError('decision', $exception->translated());

            return;
        }

        $this->dispatch('toast', message: __('design_office_workflow.request.decided'));
    }

    public function render(): View
    {
        $request = $this->request()->load(['box.audit', 'submission.submitter', 'reviewer', 'decision.type', 'decision.decider']);
        $snapshot = $request->submission->snapshot;
        /** @var list<array{id: int, folder: string}> $sent */
        $sent = $snapshot['photos'] ?? [];
        /** @var list<array{before: list<int>, after: list<int>}> $occurrences */
        $occurrences = $snapshot['occurrences'] ?? [];
        $photoIds = array_values(array_unique([...array_column($sent, 'id'), ...array_merge([], ...array_map(fn (array $occurrence) => [...$occurrence['before'], ...$occurrence['after']], $occurrences))]));
        $types = AuditDecisionType::query()->where('submission_kind', AuditSubmissionKind::Inspection)->where('is_active', true)->orderBy('position')->orderBy('id')->get();
        $chosen = $types->firstWhere('code', $this->decision);

        return view('livewire.design-office-workflow.request-page', [
            'request' => $request,
            'snapshot' => $snapshot,
            'photos' => AuditPhoto::query()->whereIn('id', $photoIds)->get(['id', 'folder', 'subfolder'])->keyBy('id'),
            'folders' => $this->photosByFolder($sent),
            'proofRows' => AuditMissingProof::query()->where('audit_submission_id', $request->audit_submission_id)->orderBy('id')->get(),
            'types' => $types,
            'needsTargets' => $chosen !== null && ($chosen->requires_targets || $chosen->outcome === AuditDecisionOutcome::Refuse),
            'needsReason' => $chosen !== null && $chosen->requires_reason,
        ])->layout('components.layouts.app', ['title' => __('design_office_workflow.request.title', ['box' => $request->box->name]), 'page' => 'design-office']);
    }

    /**
     * Photo ids of the submission grouped by folder label, in the order they were sent.
     *
     * @param  list<array{id: int, folder: string}>  $sent
     * @return array<string, list<int>>
     */
    private function photosByFolder(array $sent): array
    {
        $folders = [];

        foreach ($sent as $photo) {
            $folders[AuditFolder::tryFrom($photo['folder'])?->label() ?? $photo['folder']][] = $photo['id'];
        }

        return $folders;
    }

    private function request(): AuditValidationRequest
    {
        return AuditValidationRequest::query()->findOrFail($this->requestId);
    }
}
