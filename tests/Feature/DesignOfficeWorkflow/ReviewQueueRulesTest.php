<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxMessages;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\ReviewQueue;
use App\DesignOfficeWorkflow\SubmitInspection;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditBoxMessageKind;
use App\Enums\AuditFolder;
use App\Enums\AuditInspectionState;
use App\Enums\AuditRequestState;
use App\Enums\Role;
use App\Models\AuditBoxMessage;
use App\Models\AuditDecision;
use App\Models\AuditMissingProof;
use App\Models\AuditSubmission;
use App\Models\AuditValidationRequest;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Rules of phase 1 of the design office workflow (spec 13 §4.1–16): submission, queue, deadlines, single decision,
 * locks and hand-over.
 */
class ReviewQueueRulesTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    /**
     * @param  callable(): mixed  $action
     * @return array<string, mixed>
     */
    private function refusal(callable $action, int $status, string $reason): array
    {
        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->assertSame([$status, $reason], [$exception->status, $exception->reason]);

            return $exception->context;
        }

        $this->fail("Expected a refusal {$status} {$reason}.");
    }

    public function test_an_incomplete_submission_is_refused_and_starts_no_clock(): void
    {
        $box = $this->box();
        app(BoxTeam::class)->claim($this->technician, $box);
        $this->fillPhotos($box, [AuditFolder::Labels, AuditFolder::Cassettes]);

        $context = $this->refusal(fn () => $this->submit($box), 422, 'missing_proofs');

        $this->assertEqualsCanonicalizing(['labels', 'cassettes'], $context['folders']);
        $this->assertSame(0, AuditValidationRequest::query()->count());
        $this->assertSame(AuditInspectionState::InProgress, $box->refresh()->inspection_state);
    }

    public function test_a_missing_photo_justified_in_writing_must_be_accepted_or_refused_by_the_design_office(): void
    {
        $box = $this->box();
        app(BoxTeam::class)->claim($this->technician, $box);
        $this->fillPhotos($box, [AuditFolder::Labels]);
        $request = $this->submit($box, justifications: ['labels' => 'label unreadable, cable under water']);

        $this->refusal(fn () => $this->decide($request, 'validate', reason: null), 422, 'proofs_undecided');
        $this->refusal(fn () => $this->decide($request, 'validate', reason: null, proofs: ['labels' => false]), 422, 'proof_refused');
        $this->decide($request, 'validate', reason: null, proofs: ['labels' => true]);

        $proof = AuditMissingProof::query()->sole();
        $this->assertTrue($proof->is_accepted);
        $this->assertSame($this->supervisor->id, $proof->decided_by);
    }

    public function test_a_refused_justification_still_allows_validating_with_reserves(): void
    {
        $box = $this->box();
        app(BoxTeam::class)->claim($this->technician, $box);
        $this->fillPhotos($box, [AuditFolder::Labels]);
        $request = $this->submit($box, justifications: ['labels' => 'label unreadable']);

        $this->decide($request, 'validate_with_reserves', proofs: ['labels' => false]);

        $this->assertFalse(AuditMissingProof::query()->sole()->is_accepted);
        $this->assertTrue($box->refresh()->are_reserves_authorized);
    }

    public function test_the_clock_starts_on_reception_and_the_request_goes_to_a_reviewer(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $box = $this->readyBox();

        $request = $this->submit($box);

        $this->assertTrue($request->deadline_at->equalTo(Carbon::parse('2026-10-30 10:05:00')));
        $this->assertSame(AuditRequestState::Assigned, $request->state);
        $this->assertContains($request->assigned_to, [$this->reviewer->id, $this->otherReviewer->id]);
        $this->assertSame(AuditInspectionState::Submitted, $box->refresh()->inspection_state);
    }

    public function test_the_deadline_follows_the_settings(): void
    {
        $settings = app(DesignOfficeWorkflowSettings::class);
        $settings->review_deadline_seconds = 600;
        $settings->save();
        Carbon::setTestNow('2026-10-30 10:00:00');

        $request = $this->submit($this->readyBox());

        $this->assertTrue($request->deadline_at->equalTo(Carbon::parse('2026-10-30 10:10:00')));
    }

    public function test_a_submission_resent_by_the_offline_queue_is_not_duplicated(): void
    {
        $box = $this->readyBox();
        $operation = $this->operationId();

        $first = app(SubmitInspection::class)->handle($this->technician, $box, $operation);
        $again = app(SubmitInspection::class)->handle($this->technician, $box, $operation);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame($first['request']->id, $again['request']->id);
        $this->assertSame(1, AuditSubmission::query()->count());
    }

    public function test_the_submission_is_an_immutable_snapshot(): void
    {
        $box = $this->readyBox();
        $this->submit($box);
        $submission = AuditSubmission::query()->sole();

        $this->assertSame($box->name, $submission->snapshot['box']['name']);
        $this->assertCount($box->photos()->count(), $submission->snapshot['photos']);

        $this->expectException(LogicException::class);
        $submission->update(['snapshot' => []]);
    }

    public function test_the_responsible_is_held_until_the_decision_even_without_the_claim(): void
    {
        $this->submit($this->readyBox());
        $second = $this->box();
        $this->fillPhotos($second);

        $context = $this->refusal(fn () => app(BoxTeam::class)->claim($this->technician, $second), 409, 'blocked_by_pending');
        $this->assertSame('submitted', $context['state']);

        $second->forceFill(['responsible_id' => $this->technician->id, 'inspection_state' => AuditInspectionState::InProgress])->save();
        $this->refusal(fn () => $this->submit($second), 409, 'blocked_by_pending');
    }

    public function test_the_partner_is_not_held_and_can_start_the_next_box(): void
    {
        $first = $this->readyBox();
        app(BoxTeam::class)->claim($this->partner, $first);
        $this->submit($first);

        $result = app(BoxTeam::class)->claim($this->partner, $this->box());

        $this->assertSame(['responsible_id' => $this->partner->id, 'joined' => false], $result);
    }

    public function test_the_first_to_start_is_responsible_and_the_next_one_joins(): void
    {
        $box = $this->box();

        $first = app(BoxTeam::class)->claim($this->technician, $box);
        $second = app(BoxTeam::class)->claim($this->partner, $box);

        $this->assertSame(['responsible_id' => $this->technician->id, 'joined' => false], $first);
        $this->assertSame(['responsible_id' => $this->technician->id, 'joined' => true], $second);
        $this->assertSame([$this->partner->id], $box->participants()->pluck('users.id')->all());
        $this->assertNotNull($this->audit->refresh()->workflow_locked_at);
    }

    public function test_only_the_responsible_submits(): void
    {
        $box = $this->readyBox();
        app(BoxTeam::class)->claim($this->partner, $box);

        $this->refusal(fn () => $this->submit($box, $this->partner), 403, 'not_responsible');
    }

    public function test_requests_go_to_the_least_loaded_reviewer_or_wait_in_the_central_queue(): void
    {
        $queue = app(ReviewQueue::class);
        $queue->setAvailability($this->supervisor, $this->reviewer, false);
        $queue->setAvailability($this->supervisor, $this->otherReviewer, false);

        $requests = collect(range(1, 3))->map(function () {
            $technician = $this->person(Role::Technician);

            return $this->submit($this->readyBox($technician), $technician);
        });

        $this->assertTrue($requests->every(fn (AuditValidationRequest $request) => $request->refresh()->state === AuditRequestState::Queued && $request->assigned_to === null));

        $queue->setAvailability($this->supervisor, $this->reviewer, true);
        $this->assertTrue($requests->every(fn (AuditValidationRequest $request) => $request->refresh()->assigned_to === $this->reviewer->id), 'no cap: everything goes to the only reviewer');

        $queue->setAvailability($this->supervisor, $this->otherReviewer, true);
        $technician = $this->person(Role::Technician);
        $this->assertSame($this->otherReviewer->id, $this->submit($this->readyBox($technician), $technician)->assigned_to);
    }

    public function test_the_optional_cap_keeps_requests_in_the_central_queue(): void
    {
        $settings = app(DesignOfficeWorkflowSettings::class);
        $settings->max_open_requests_per_reviewer = 1;
        $settings->save();
        app(ReviewQueue::class)->setAvailability($this->supervisor, $this->otherReviewer, false);

        $requests = collect(range(1, 2))->map(function () {
            $technician = $this->person(Role::Technician);

            return $this->submit($this->readyBox($technician), $technician);
        });

        $this->assertSame($this->reviewer->id, $requests[0]->refresh()->assigned_to);
        $this->assertSame(AuditRequestState::Queued, $requests[1]->refresh()->state);
    }

    public function test_an_unavailable_reviewer_gives_back_unopened_requests_and_keeps_opened_ones(): void
    {
        $queue = app(ReviewQueue::class);
        $queue->setAvailability($this->supervisor, $this->otherReviewer, false);
        [$opened, $unopened] = collect(range(1, 2))->map(function () {
            $technician = $this->person(Role::Technician);

            return $this->submit($this->readyBox($technician), $technician);
        })->all();
        $queue->open($this->reviewer, $opened);
        $queue->setAvailability($this->supervisor, $this->otherReviewer, true);

        $queue->setAvailability($this->reviewer, $this->reviewer, false);

        $this->assertSame($this->reviewer->id, $opened->refresh()->assigned_to);
        $this->assertSame(AuditRequestState::InReview, $opened->state);
        $this->assertSame($this->otherReviewer->id, $unopened->refresh()->assigned_to);
    }

    public function test_a_reviewer_never_opens_a_request_of_someone_else(): void
    {
        $request = $this->submit($this->readyBox());
        $other = $request->assigned_to === $this->reviewer->id ? $this->otherReviewer : $this->reviewer;

        $this->refusal(fn () => app(ReviewQueue::class)->open($other, $request), 404, 'not_assigned');
        $this->refusal(fn () => $this->decide($request, 'validate', $other), 404, 'not_assigned');
    }

    public function test_a_supervisor_reassigns_an_undecided_request(): void
    {
        $request = $this->submit($this->readyBox());
        $other = $request->assigned_to === $this->reviewer->id ? $this->otherReviewer : $this->reviewer;

        app(ReviewQueue::class)->reassign($this->supervisor, $request, $other);

        $this->assertSame($other->id, $request->refresh()->assigned_to);
        $this->decide($request, 'validate', $other, null);
        $this->refusal(fn () => app(ReviewQueue::class)->reassign($this->supervisor, $request, $this->reviewer), 409, 'already_decided');
    }

    public function test_only_one_decision_per_request_and_the_second_is_told_who_decided(): void
    {
        $request = $this->submit($this->readyBox());
        $reviewer = $request->assigned_to === $this->reviewer->id ? $this->reviewer : $this->otherReviewer;

        $this->decide($request, 'validate', $reviewer, null);
        $context = $this->refusal(fn () => $this->decide($request, 'refuse', $this->supervisor, 'blurred photos', ['labels']), 409, 'already_decided');

        $this->assertSame($reviewer->name, $context['by']);
        $this->assertSame(1, AuditDecision::query()->count());
        $this->assertSame(AuditInspectionState::Validated, $request->box->refresh()->inspection_state);

        $this->expectException(UniqueConstraintViolationException::class);
        AuditDecision::query()->create(['audit_validation_request_id' => $request->id, 'audit_decision_type_id' => AuditDecision::query()->value('audit_decision_type_id'), 'decided_by' => $this->supervisor->id, 'decided_at' => now()]);
    }

    public function test_a_decided_request_is_never_reopened(): void
    {
        $request = $this->submit($this->readyBox());
        $this->decide($request, 'validate', reason: null);

        $this->expectException(LogicException::class);
        $request->refresh()->update(['state' => AuditRequestState::Assigned]);
    }

    public function test_the_reason_is_mandatory_except_to_validate_and_a_refusal_says_what_to_correct(): void
    {
        $request = $this->submit($this->readyBox());

        $this->refusal(fn () => $this->decide($request, 'refuse', reason: 'valid reason', targets: []), 422, 'targets_required');
        $this->refusal(fn () => $this->decide($request, 'validate_with_reserves', reason: ''), 422, 'reason_required');
        $this->refusal(fn () => $this->decide($request, 'unknown'), 422, 'unknown_decision');
        $this->decide($request, 'refuse', reason: 'labels unreadable', targets: ['take the label photos again']);

        $this->assertSame(AuditInspectionState::Refused, $request->box->refresh()->inspection_state);
        $this->assertSame(['take the label photos again'], AuditDecision::query()->sole()->targets);
    }

    public function test_effects_of_the_decisions_on_the_box(): void
    {
        $run = function (string $decision, array $targets = []) {
            $technician = $this->person(Role::Technician);
            $box = $this->readyBox($technician);
            $this->decide($this->submit($box, $technician), $decision, targets: $targets);

            return $box->refresh();
        };

        $this->assertSame(AuditInspectionState::Validated, $run('validate')->inspection_state);
        $reserves = $run('validate_with_reserves');
        $this->assertSame(AuditInspectionState::Validated, $reserves->inspection_state);
        $this->assertTrue($reserves->are_reserves_authorized);
        $this->assertSame(AuditInspectionState::Refused, $run('refuse', ['photos'])->inspection_state);
        $control = $run('request_control');
        $this->assertSame(AuditInspectionState::ControlRequested, $control->inspection_state);
        $this->assertTrue(AuditBoxMessage::query()->where('audit_box_id', $control->id)->where('kind', AuditBoxMessageKind::ControlRequest)->sole()->is_blocking);
        $this->refusal(fn () => $run('authorize_continuation'), 422, 'nothing_pending');
    }

    public function test_an_additional_control_blocks_a_new_submission_until_the_design_office_resolves_it(): void
    {
        $box = $this->readyBox();
        $first = $this->submit($box);
        $this->decide($first, 'request_control', reason: 'continuity measure asked');
        $message = AuditBoxMessage::query()->sole();

        $this->refusal(fn () => $this->submit($box), 409, 'blocking_messages');
        $this->refusal(fn () => app(BoxMessages::class)->resolve($this->technician, $message), 403, 'forbidden');

        app(BoxMessages::class)->resolve($this->reviewer, $message);
        $again = $this->submit($box);

        $this->assertGreaterThan($first->id, $again->id);
    }

    public function test_after_a_refusal_the_responsible_stays_and_is_released_once_validated(): void
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'refuse', targets: ['photos']);
        $next = $this->box();

        $this->refusal(fn () => app(BoxTeam::class)->claim($this->technician, $next), 409, 'blocked_by_pending');
        $this->decide($this->submit($box), 'validate', reason: null);

        $this->assertSame($this->technician->id, app(BoxTeam::class)->claim($this->technician, $next)['responsible_id']);
    }

    public function test_alert_at_three_minutes_then_late_at_five_and_the_check_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $request = $this->submit($this->readyBox());
        $queue = app(ReviewQueue::class);

        Carbon::setTestNow('2026-10-30 10:02:59');
        $this->assertSame(['alerted' => 0, 'late' => 0], $queue->checkDeadlines());
        Carbon::setTestNow('2026-10-30 10:03:00');
        $this->assertSame(['alerted' => 1, 'late' => 0], $queue->checkDeadlines());
        $this->assertSame(['alerted' => 0, 'late' => 0], $queue->checkDeadlines());
        Carbon::setTestNow('2026-10-30 10:05:00');
        $this->assertSame(['alerted' => 0, 'late' => 1], $queue->checkDeadlines());
        $this->assertSame(['alerted' => 0, 'late' => 0], $queue->checkDeadlines());

        $request->refresh();
        $this->assertNotNull($request->late_at);
        $this->assertNotNull($request->supervisor_alerted_at);
        $this->assertSame(1, Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('event', 'request_late')->count());

        $this->decide($request, 'validate', reason: null);
        $this->assertTrue($request->refresh()->isDecided());
    }

    public function test_the_scheduled_command_runs_the_deadline_check(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $request = $this->submit($this->readyBox());
        Carbon::setTestNow('2026-10-30 10:06:00');

        $this->artisan('design-office:check-deadlines')->assertSuccessful();

        $this->assertNotNull($request->refresh()->late_at);
    }

    public function test_handing_over_keeps_the_clock_and_the_review_untouched(): void
    {
        $box = $this->readyBox();
        app(BoxTeam::class)->claim($this->partner, $box);
        $request = $this->submit($box);
        $before = [$request->deadline_at->toIso8601String(), $request->state];

        $result = app(BoxTeam::class)->handOver($this->technician, $box);

        $request->refresh();
        $this->assertSame($this->partner->id, $result['responsible_id']);
        $this->assertSame($before, [$request->deadline_at->toIso8601String(), $request->state]);
        $this->assertSame([$this->technician->id], $box->participants()->pluck('users.id')->all());
        $this->refusal(fn () => app(BoxTeam::class)->handOver($this->technician, $box), 403, 'not_responsible');
    }

    public function test_without_a_participant_a_supervisor_is_asked_to_name_the_responsible(): void
    {
        $box = $this->readyBox();

        $this->assertSame(['responsible_id' => null], app(BoxTeam::class)->handOver($this->technician, $box));
        $box->refresh();
        $this->assertSame($this->technician->id, $box->responsible_id);
        $this->assertNotNull($box->handover_requested_at);

        app(BoxTeam::class)->designate($this->supervisor, $box, $this->partner);

        $box->refresh();
        $this->assertSame($this->partner->id, $box->responsible_id);
        $this->assertNull($box->handover_requested_at);
    }

    public function test_every_action_is_refused_on_a_standard_audit(): void
    {
        $audit = $this->plannedAudit([$this->technician], designOffice: false);
        $box = $this->box($audit);

        $this->refusal(fn () => app(BoxTeam::class)->claim($this->technician, $box), 409, 'standard_workflow');
        $this->refusal(fn () => $this->submit($box), 409, 'standard_workflow');
    }
}
