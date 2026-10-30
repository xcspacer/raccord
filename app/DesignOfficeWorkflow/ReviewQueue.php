<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditRequestState;
use App\Enums\Role;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Settings\DesignOfficeWorkflowSettings;
use App\Support\FeatureAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The design office queue. Requests are handed out first in, first served, each to the active and available reviewer
 * with the fewest open requests (on a tie, the one who received a request longest ago); with nobody available they
 * wait in the central queue and their clock keeps running. There is no cap per reviewer unless one is set in the
 * settings. A reviewer who becomes unavailable gives back the requests they have not opened yet. The scheduler alerts
 * at the alert time (the reviewer, or the supervisors while nobody has the request) and marks a request late at its
 * deadline, alerting the supervisors; running it again changes nothing.
 */
class ReviewQueue
{
    public function __construct(private DesignOfficeWorkflowSettings $settings, private WorkflowJournal $journal, private FeatureAccess $access) {}

    /**
     * @return int Requests handed out.
     */
    public function dispatch(): int
    {
        return DB::transaction(function (): int {
            $queued = AuditValidationRequest::query()->where('state', AuditRequestState::Queued)->orderBy('queued_at')->orderBy('id')->lockForUpdate()->get();

            if ($queued->isEmpty()) {
                return 0;
            }

            $reviewers = $this->availableReviewers();

            if ($reviewers->isEmpty()) {
                return 0;
            }

            $cap = $this->settings->max_open_requests_per_reviewer;
            $handed = 0;

            foreach ($queued as $request) {
                $reviewers = $reviewers->sortBy([['load', 'asc'], ['last_assigned_at', 'asc'], ['id', 'asc']])->values();
                $reviewer = $reviewers->first();

                if ($cap !== null && $reviewer['load'] >= $cap) {
                    break;
                }

                $request->forceFill(['state' => AuditRequestState::Assigned, 'assigned_to' => $reviewer['id'], 'assigned_at' => now()])->save();
                $this->journal->record('request_assigned', $request, null, ['reviewer_id' => $reviewer['id'], 'origin' => 'queue']);
                $reviewers = $reviewers->map(fn (array $row) => $row['id'] === $reviewer['id'] ? [...$row, 'load' => $row['load'] + 1, 'last_assigned_at' => now()->getTimestamp()] : $row);
                $handed++;
            }

            return $handed;
        });
    }

    /**
     * Number of open requests per reviewer (assigned or in review).
     *
     * @param  list<int>  $reviewerIds
     * @return array<int, int>
     */
    public function loads(array $reviewerIds): array
    {
        return AuditValidationRequest::query()
            ->whereIn('assigned_to', $reviewerIds)
            ->whereIn('state', [AuditRequestState::Assigned, AuditRequestState::InReview])
            ->groupBy('assigned_to')
            ->selectRaw('assigned_to, count(*) as open_count')
            ->pluck('open_count', 'assigned_to')
            ->map(fn (mixed $count) => (int) $count)
            ->all();
    }

    /**
     * A reviewer says whether they are available (or a supervisor says it for them). Becoming unavailable gives back
     * the requests not opened yet; the ones being reviewed stay with them.
     *
     * @throws WorkflowException
     */
    public function setAvailability(User $actor, User $reviewer, bool $available): void
    {
        DB::transaction(function () use ($actor, $reviewer, $available): void {
            $reviewer = User::query()->lockForUpdate()->findOrFail($reviewer->id);

            if (! WorkflowRoles::isReviewer($reviewer)) {
                throw new WorkflowException('not_a_reviewer', 404);
            }

            $reviewer->forceFill(['is_available_for_review' => $available])->save();

            if (! $available) {
                AuditValidationRequest::query()
                    ->where('assigned_to', $reviewer->id)->where('state', AuditRequestState::Assigned)->whereNull('opened_at')
                    ->update(['state' => AuditRequestState::Queued->value, 'assigned_to' => null, 'assigned_at' => null]);
            }

            $this->journal->record('reviewer_availability_changed', $reviewer, $actor, ['available' => $available]);
            $this->dispatch();
        });
    }

    /**
     * The reviewer (or a supervisor) opens the request: it is now in review. A reviewer only ever opens their own.
     *
     * @throws WorkflowException
     */
    public function open(User $actor, AuditValidationRequest $request): AuditValidationRequest
    {
        return DB::transaction(function () use ($actor, $request): AuditValidationRequest {
            $request = AuditValidationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->isDecided()) {
                return $request;
            }

            $supervisor = WorkflowRoles::isSupervisor($actor);

            if (! $supervisor && $request->assigned_to !== $actor->id) {
                throw new WorkflowException('not_assigned', 404);
            }

            $wasOpened = $request->opened_at !== null;
            $request->forceFill(['state' => AuditRequestState::InReview, 'opened_at' => $request->opened_at ?? now()])->save();

            if (! $wasOpened) {
                $this->journal->record('request_opened', $request, $actor);
            }

            return $request;
        });
    }

    /**
     * A supervisor gives an undecided request to an active reviewer (available or not); an opened one stays in review.
     *
     * @throws WorkflowException
     */
    public function reassign(User $supervisor, AuditValidationRequest $request, User $reviewer): void
    {
        DB::transaction(function () use ($supervisor, $request, $reviewer): void {
            $request = AuditValidationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->isDecided()) {
                throw new WorkflowException('already_decided', 409);
            }

            if (! WorkflowRoles::isReviewer($reviewer) || $reviewer->hasDeparted() || ! $this->access->allowsDesignOfficeWorkflow($reviewer)) {
                throw new WorkflowException('not_a_reviewer', 404);
            }

            $previous = $request->assigned_to;
            $request->forceFill([
                'state' => $request->opened_at === null ? AuditRequestState::Assigned : AuditRequestState::InReview,
                'assigned_to' => $reviewer->id,
                'assigned_at' => now(),
            ])->save();
            $this->journal->record('request_reassigned', $request, $supervisor, ['old' => $previous, 'new' => $reviewer->id]);
        });
    }

    /**
     * Alerts and lateness, then hands out what waits in the central queue. Each request is alerted once and marked
     * late once.
     *
     * @return array{alerted: int, late: int}
     */
    public function checkDeadlines(): array
    {
        $result = DB::transaction(function (): array {
            $alertBefore = now()->subSeconds($this->settings->review_alert_seconds);
            $toAlert = AuditValidationRequest::query()->whereIn('state', AuditRequestState::open())->whereNull('alerted_at')->where('queued_at', '<=', $alertBefore)->lockForUpdate()->get();

            foreach ($toAlert as $request) {
                $request->forceFill(['alerted_at' => now()])->save();
            }

            $toMarkLate = AuditValidationRequest::query()->whereIn('state', AuditRequestState::open())->whereNull('late_at')->where('deadline_at', '<=', now())->with('box')->lockForUpdate()->get();

            foreach ($toMarkLate as $request) {
                $request->forceFill(['late_at' => now(), 'supervisor_alerted_at' => now()])->save();
                $this->journal->record('request_late', $request, null, ['box' => $request->box->name, 'reviewer_id' => $request->assigned_to, 'origin' => 'scheduler']);
            }

            return ['alerted' => $toAlert->count(), 'late' => $toMarkLate->count()];
        });

        $this->dispatch();

        return $result;
    }

    /**
     * Active reviewers who said they are available and may use the workflow, with their load.
     *
     * @return Collection<int, array{id: int, load: int, last_assigned_at: int}>
     */
    private function availableReviewers(): Collection
    {
        $reviewers = User::query()
            ->role(Role::DesignOffice->value)
            ->where('is_available_for_review', true)
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhere('departure_date', '>', now()->toDateString()))
            ->with(['roles', 'agency'])
            ->get()
            ->filter(fn (User $user) => $this->access->allowsDesignOfficeWorkflow($user))
            ->values();

        $ids = $reviewers->pluck('id')->all();
        $loads = $this->loads($ids);
        $lastAssigned = AuditValidationRequest::query()->whereIn('assigned_to', $ids)->groupBy('assigned_to')->selectRaw('assigned_to, max(assigned_at) as last_at')->pluck('last_at', 'assigned_to');

        return $reviewers->map(fn (User $user) => [
            'id' => $user->id,
            'load' => $loads[$user->id] ?? 0,
            'last_assigned_at' => $lastAssigned->has($user->id) ? (int) strtotime((string) $lastAssigned[$user->id]) : 0,
        ]);
    }
}
