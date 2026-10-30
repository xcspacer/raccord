<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxMessages;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\ReviewQueue;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Enums\AuditRequestState;
use App\Enums\Role;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditDecision;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Design office queue, refreshed by polling. A reviewer sees the requests given to them with their countdown (alert at
 * the alert time, « late » past the deadline) and says whether they are available. Supervisors see every open request,
 * the central queue included, give requests to a reviewer, set the reviewers' availability, name a responsible when a
 * technician asked to hand a box over, and follow the latest decisions. Both resolve the additional controls asked.
 */
class QueuePage extends Component
{
    /** Hours of decisions listed for the supervisors. */
    private const int RECENT_DECISION_HOURS = 24;

    public function mount(): void
    {
        $this->authorize('viewAny', AuditValidationRequest::class);
    }

    public function toggleAvailability(ReviewQueue $queue, ?int $reviewerId = null): void
    {
        $this->authorize('viewAny', AuditValidationRequest::class);
        $reviewer = $reviewerId === null ? $this->user() : User::query()->findOrFail($reviewerId);
        $this->authorize('setAvailability', [AuditValidationRequest::class, $reviewer]);

        $this->attempt(fn () => $queue->setAvailability($this->user(), $reviewer, ! $reviewer->is_available_for_review));
    }

    public function reassign(ReviewQueue $queue, int $requestId, int $reviewerId): void
    {
        $request = AuditValidationRequest::query()->findOrFail($requestId);
        $this->authorize('reassign', $request);

        $this->attempt(fn () => $queue->reassign($this->user(), $request, User::query()->findOrFail($reviewerId)), __('design_office_workflow.queue.reassigned'));
    }

    public function designate(BoxTeam $team, int $boxId, int $technicianId): void
    {
        $box = AuditBox::query()->findOrFail($boxId);
        $this->authorize('designateResponsible', $box);

        $this->attempt(fn () => $team->designate($this->user(), $box, User::query()->findOrFail($technicianId)), __('design_office_workflow.queue.designated'));
    }

    public function resolveMessage(BoxMessages $messages, int $messageId): void
    {
        $message = AuditBoxMessage::query()->findOrFail($messageId);
        $this->authorize('resolve', $message);

        $this->attempt(fn () => $messages->resolve($this->user(), $message), __('design_office_workflow.queue.resolved'));
    }

    public function render(ReviewQueue $queue, FeatureAccess $access): View
    {
        $user = $this->user();
        $supervisor = WorkflowRoles::isSupervisor($user);
        $reviewers = $supervisor ? $this->reviewers($access) : new Collection;

        return view('livewire.design-office-workflow.queue-page', [
            'user' => $user,
            'isSupervisor' => $supervisor,
            'isReviewer' => WorkflowRoles::isReviewer($user),
            'requests' => $this->openRequests($user, $supervisor),
            'reviewers' => $reviewers,
            'loads' => $queue->loads($reviewers->pluck('id')->all()),
            'handovers' => $supervisor ? $this->handovers() : new Collection,
            'technicians' => $supervisor ? User::query()->role(Role::Technician->value)->where(fn ($query) => $query->whereNull('departure_date')->orWhere('departure_date', '>', now()->toDateString()))->orderBy('name')->get(['id', 'name']) : new Collection,
            'controls' => AuditBoxMessage::query()->where('is_blocking', true)->whereNull('resolved_at')
                ->whereHas('box.audit', fn ($audits) => $audits->where('workflow', 'design_office'))
                ->with(['box.audit', 'author'])->orderBy('id')->get(),
            'decisions' => $supervisor ? AuditDecision::query()->where('decided_at', '>=', now()->subHours(self::RECENT_DECISION_HOURS))
                ->with(['type', 'decider', 'request.box.audit', 'request.submission.submitter'])->latest('decided_at')->limit(30)->get() : new Collection,
        ])->layout('components.layouts.app', ['title' => __('navigation.items.design_office_queue.label'), 'page' => 'design-office']);
    }

    /**
     * @return Collection<int, AuditValidationRequest>
     */
    private function openRequests(User $user, bool $supervisor): Collection
    {
        return AuditValidationRequest::query()
            ->whereIn('state', AuditRequestState::open())
            ->when(! $supervisor, fn ($query) => $query->where('assigned_to', $user->id))
            ->with(['box.audit', 'reviewer', 'submission.submitter'])
            ->orderBy('queued_at')->orderBy('id')
            ->get();
    }

    /**
     * Active design office users who may use the workflow.
     *
     * @return Collection<int, User>
     */
    private function reviewers(FeatureAccess $access): Collection
    {
        return User::query()->role(Role::DesignOffice->value)
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhere('departure_date', '>', now()->toDateString()))
            ->with(['roles', 'agency'])->orderBy('name')->get()
            ->filter(fn (User $reviewer) => $access->allowsDesignOfficeWorkflow($reviewer))
            ->values();
    }

    /**
     * Boxes whose responsible asked a supervisor to name a replacement.
     *
     * @return Collection<int, AuditBox>
     */
    private function handovers(): Collection
    {
        return AuditBox::query()->whereNotNull('handover_requested_at')
            ->whereHas('audit', fn ($audits) => $audits->where('workflow', 'design_office')->whereNull('closed_at'))
            ->with(['audit', 'responsible', 'participants'])->orderBy('handover_requested_at')->get();
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action, ?string $done = null): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->addError('action', $exception->translated());

            return;
        }

        $this->dispatch('toast', message: $done ?? __('design_office_workflow.queue.saved'));
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
