<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\DecideRepairs;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\RepairPlanning;
use App\DesignOfficeWorkflow\SubmitRepairs;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditFolder;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditSubmissionKind;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Role;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditBoxRecheck;
use App\Models\AuditDecision;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditRepairItem;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Models\WorkSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 2 of the design office workflow — mechanical repairs (spec 13 §4.23, 25–28): opening the phase, planning and
 * cancelling interventions, repairs recorded only inside a planned intervention, one repairs request per box with the
 * lock and the deadline of phase 1, a verdict per repair, the global decision and the resubmission of refused ones.
 */
class RepairPhaseTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
        $this->third = $this->person(Role::Technician);
        WorkSite::query()->where('audit_id', $this->audit->id)->sole()->members()->attach($this->third->id, ['role' => 'partner']);
    }

    private function occurrence(AuditBox $box, AuditAnomalyNature $nature, array $attributes = []): AuditOccurrence
    {
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => $nature, 'state' => AuditOccurrenceState::Detected,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', $nature)->value('id'), ...$attributes,
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $this->technician->id]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_photo_id' => $box->photos()->value('id'), 'role' => AuditOccurrencePhotoRole::Before, 'linked_at' => now()]);

        return $occurrence;
    }

    /**
     * A box whose inspection (with its anomalies) was validated in phase 1.
     *
     * @return array{0: AuditBox, 1: list<AuditOccurrence>, 2: list<AuditOccurrence>}
     */
    private function inspectedBox(?User $owner = null, int $mechanical = 1, int $optical = 0): array
    {
        $owner ??= $this->technician;
        $box = $this->readyBox($owner);
        $mechanicals = [];
        $opticals = [];

        for ($i = 0; $i < $mechanical; $i++) {
            $mechanicals[] = $this->occurrence($box, AuditAnomalyNature::Mechanical);
        }

        for ($i = 0; $i < $optical; $i++) {
            $opticals[] = $this->occurrence($box, AuditAnomalyNature::Optical, ['pending_justification' => 'upstream cable cut, no repair possible today']);
        }

        $this->decide($this->submit($box, $owner), $optical > 0 ? 'authorize_continuation' : 'validate', reason: 'go on');

        return [$box->refresh(), array_map(fn (AuditOccurrence $occurrence) => $occurrence->refresh(), $mechanicals), array_map(fn (AuditOccurrence $occurrence) => $occurrence->refresh(), $opticals)];
    }

    private function planning(): RepairPlanning
    {
        return app(RepairPlanning::class);
    }

    private function openPhase(): void
    {
        $this->planning()->openPhase($this->supervisor, $this->audit);
        $this->audit->refresh();
    }

    /**
     * @param  list<User>  $others
     */
    private function plan(AuditBox $box, User $lead, array $others = []): AuditIntervention
    {
        return $this->planning()->plan($this->supervisor, $box, now()->toDateString(), $lead->id, array_map(fn (User $user) => $user->id, $others));
    }

    /**
     * The repair recorded on site: the action and an after photo.
     */
    private function repair(AuditOccurrence $occurrence, User $by): void
    {
        $photo = $this->photo($occurrence->box, AuditFolder::Fixing);
        app(OccurrenceLifecycle::class)->correct($by, $occurrence, 'sealant applied', [$photo->id]);
    }

    private function submitRepairs(AuditBox $box, User $by, ?string $operationId = null, bool $recheck = false): AuditValidationRequest
    {
        return app(SubmitRepairs::class)->handle($by, $box, $operationId ?? $this->operationId(), $recheck)['request'];
    }

    /**
     * @param  array<int, array{accepted: bool, reason?: string|null}>  $verdicts
     * @return array{decision: AuditDecision, accepted: int, refused: int, conformity: AuditConformity}
     */
    private function decideRepairs(AuditValidationRequest $request, array $verdicts, ?User $actor = null): array
    {
        return app(DecideRepairs::class)->handle($actor ?? $this->supervisor, $request, $verdicts);
    }

    private function reason(callable $action): string
    {
        try {
            $action();
        } catch (WorkflowException $exception) {
            return $exception->status.':'.$exception->reason;
        }

        return 'none';
    }

    public function test_the_supervisor_opens_phase_2_once_no_request_is_open(): void
    {
        $box = $this->readyBox();
        $request = $this->submit($box);

        $this->assertSame('403:forbidden', $this->reason(fn () => $this->planning()->openPhase($this->reviewer, $this->audit)));
        $this->assertSame('409:requests_open', $this->reason(fn () => $this->planning()->openPhase($this->supervisor, $this->audit)));

        $this->decide($request, 'validate', reason: null);
        $this->assertSame(['boxes' => 0, 'occurrences' => 0], $this->planning()->openPhase($this->supervisor, $this->audit));
        $this->assertSame(AuditWorkflowPhase::Repairs, $this->audit->refresh()->phase);
        $this->assertSame('409:wrong_phase', $this->reason(fn () => $this->planning()->openPhase($this->supervisor, $this->audit)));

        $standard = $this->plannedAudit([$this->technician], designOffice: false);
        $this->assertSame('409:standard_workflow', $this->reason(fn () => $this->planning()->openPhase($this->supervisor, $standard)));
    }

    public function test_an_intervention_needs_phase_2_a_validated_inspection_a_valid_team_and_none_already_open(): void
    {
        [$box] = $this->inspectedBox();

        $this->assertSame('409:repairs_phase_not_open', $this->reason(fn () => $this->plan($box, $this->partner)));
        $this->openPhase();

        $this->assertSame('403:forbidden', $this->reason(fn () => $this->planning()->plan($this->reviewer, $box, now()->toDateString(), $this->partner->id)));
        $this->assertSame('422:invalid_date', $this->reason(fn () => $this->planning()->plan($this->supervisor, $box, 'yesterday', $this->partner->id)));
        $this->assertSame('422:invalid_date', $this->reason(fn () => $this->planning()->plan($this->supervisor, $box, '2026-02-30', $this->partner->id)));
        $this->assertSame('422:invalid_team', $this->reason(fn () => $this->plan($box, $this->reviewer)));
        $this->assertSame('422:invalid_team', $this->reason(fn () => $this->plan($box, $this->person(Role::Technician))));

        $intervention = $this->plan($box, $this->partner, [$this->third]);

        $this->assertSame('409:intervention_exists', $this->reason(fn () => $this->plan($box, $this->partner)));
        $box->refresh();
        $this->assertSame($this->partner->id, $box->responsible_id);
        $this->assertSame([$this->third->id], $box->participants()->pluck('users.id')->all());
        $this->assertSame(AuditRepairState::Planned, $box->repair_state);
        $this->assertEqualsCanonicalizing([$this->partner->id, $this->third->id], $intervention->members()->pluck('users.id')->all());

        $this->assertSame('422:cancellation_reason_required', $this->reason(fn () => $this->planning()->cancel($this->supervisor, $intervention, 'x')));
        $this->planning()->cancel($this->supervisor, $intervention, 'team not available that day');
        $this->assertSame(AuditInterventionStatus::Cancelled, $intervention->refresh()->status);
        $this->assertSame('team not available that day', $intervention->cancellation_reason);
        $this->assertSame(AuditRepairState::None, $box->refresh()->repair_state);
        $this->assertSame('409:intervention_not_planned', $this->reason(fn () => $this->planning()->cancel($this->supervisor, $intervention, 'again')));

        $this->assertGreaterThan($intervention->id, $this->plan($box, $this->partner)->id);

        $raw = $this->box();
        $this->assertSame('409:inspection_not_validated', $this->reason(fn () => $this->plan($raw, $this->partner)));
    }

    public function test_a_box_without_anything_to_repair_cannot_be_planned(): void
    {
        [$box] = $this->inspectedBox(mechanical: 0);
        $this->openPhase();

        $this->assertSame('409:nothing_to_repair', $this->reason(fn () => $this->plan($box, $this->technician)));
    }

    public function test_a_mechanical_repair_is_recorded_only_in_phase_2_inside_a_planned_intervention_of_the_person(): void
    {
        [$box, [$occurrence]] = $this->inspectedBox();

        $this->assertSame('409:repairs_phase_required', $this->reason(fn () => $this->repair($occurrence, $this->technician)));
        $this->openPhase();
        $this->assertSame('409:no_intervention', $this->reason(fn () => $this->repair($occurrence, $this->technician)));

        $this->plan($box, $this->partner, [$this->third]);
        $this->assertNotSame('none', $this->reason(fn () => $this->repair($occurrence, $this->technician)), 'the team of phase 1 is no longer on the box');

        $this->repair($occurrence, $this->third);
        $this->assertSame(AuditOccurrenceState::Corrected, $occurrence->refresh()->state);
    }

    public function test_one_repairs_request_per_box_from_its_responsible_with_the_lock_and_the_deadline(): void
    {
        [$box, $occurrences] = $this->inspectedBox(mechanical: 2);
        [$other, [$otherOccurrence]] = $this->inspectedBox($this->partner);
        $this->openPhase();
        $this->plan($box, $this->partner, [$this->third]);
        $this->plan($other, $this->partner);

        $this->assertSame('422:nothing_to_submit', $this->reason(fn () => $this->submitRepairs($box, $this->partner)));
        $this->repair($occurrences[0], $this->partner);
        $this->repair($occurrences[1], $this->third);
        $this->assertSame('403:not_responsible', $this->reason(fn () => $this->submitRepairs($box, $this->third)));

        $operationId = $this->operationId();
        $request = $this->submitRepairs($box, $this->partner, $operationId);

        $this->assertEqualsWithDelta(300, $request->queued_at->diffInSeconds($request->deadline_at), 1);
        $this->assertSame($request->id, $this->submitRepairs($box, $this->partner, $operationId)->id);
        $this->assertSame(AuditSubmissionKind::Repair, $request->submission->kind);
        $this->assertSame([$occurrences[0]->reference, $occurrences[1]->reference], array_column($request->submission->snapshot['items'], 'reference'));
        $this->assertSame(AuditRepairState::Submitted, $box->refresh()->repair_state);
        $this->assertSame(AuditInterventionStatus::Submitted, AuditIntervention::query()->where('audit_box_id', $box->id)->sole()->status);
        $this->assertSame('409:no_intervention', $this->reason(fn () => $this->repair($occurrences[0], $this->partner)), 'nothing changes while the repairs are being validated');

        $this->repair($otherOccurrence, $this->partner);
        $this->assertSame('409:blocked_by_pending', $this->reason(fn () => $this->submitRepairs($other, $this->partner)));

        $free = $this->box();
        $this->assertSame($this->third->id, app(BoxTeam::class)->claim($this->third, $free)['responsible_id'], 'the partner is never held');
    }

    public function test_a_repairs_request_is_not_decided_as_an_inspection_and_the_other_way_round(): void
    {
        [$box, [$occurrence]] = $this->inspectedBox();
        $this->openPhase();
        $this->plan($box, $this->partner);
        $this->repair($occurrence, $this->partner);
        $repairs = $this->submitRepairs($box, $this->partner);

        $this->assertSame('409:wrong_kind', $this->reason(fn () => $this->decide($repairs, 'validate', reason: null)));

        $inspection = $this->submit($this->readyBox($this->third), $this->third);
        $this->assertSame('409:not_repairs', $this->reason(fn () => $this->decideRepairs($inspection, [$occurrence->id => ['accepted' => true]])));
    }

    public function test_each_repair_is_decided_an_accepted_one_stays_accepted_and_only_refused_ones_are_submitted_again(): void
    {
        [$box, [$first, $second]] = $this->inspectedBox(mechanical: 2);
        $this->openPhase();
        $intervention = $this->plan($box, $this->partner);
        $this->repair($first, $this->partner);
        $this->repair($second, $this->partner);
        $request = $this->submitRepairs($box, $this->partner);
        $stranger = $request->assigned_to === $this->reviewer->id ? $this->otherReviewer : $this->reviewer;

        $this->assertSame('422:items_incomplete', $this->reason(fn () => $this->decideRepairs($request, [$first->id => ['accepted' => true]])));
        $this->assertSame('422:repair_reason_required', $this->reason(fn () => $this->decideRepairs($request, [$first->id => ['accepted' => true], $second->id => ['accepted' => false]])));
        $this->assertSame('404:not_assigned', $this->reason(fn () => $this->decideRepairs($request, [$first->id => ['accepted' => true], $second->id => ['accepted' => true]], $stranger)));

        $result = $this->decideRepairs($request, [$first->id => ['accepted' => true], $second->id => ['accepted' => false, 'reason' => 'sealant badly applied, joint not continuous']]);

        $this->assertSame('repairs_partial', $result['decision']->type->code);
        $this->assertSame([1, 1], [$result['accepted'], $result['refused']]);
        $this->assertSame(AuditOccurrenceState::Validated, $first->refresh()->state);
        $this->assertSame(AuditOccurrenceState::Refused, $second->refresh()->state);
        $this->assertSame(AuditRepairState::Refused, $box->refresh()->repair_state);
        $this->assertSame(AuditConformity::NotAssessed, $box->conformity);
        $this->assertStringContainsString('joint not continuous', (string) AuditBoxMessage::query()->where('audit_box_id', $box->id)->latest('id')->value('body'));
        $this->assertSame(AuditInterventionStatus::Planned, $intervention->refresh()->status, 'the same team corrects what was refused');
        $this->assertSame(2, AuditRepairItem::query()->count());

        [$held, [$heldOccurrence]] = $this->inspectedBox($this->third);
        $this->plan($held, $this->partner);
        $this->repair($heldOccurrence, $this->partner);
        $this->assertSame('409:blocked_by_pending', $this->reason(fn () => $this->submitRepairs($held, $this->partner)), 'a refused repair keeps its responsible on the box');

        $this->repair($second, $this->partner);
        $again = $this->submitRepairs($box, $this->partner);
        $this->assertSame([$second->reference], array_column($again->submission->snapshot['items'], 'reference'));

        $final = $this->decideRepairs($again, [$second->id => ['accepted' => true]]);

        $this->assertSame('repairs_validated', $final['decision']->type->code);
        $this->assertSame(AuditConformity::Conforming, $final['conformity']);
        $box->refresh();
        $this->assertSame(AuditConformity::Conforming, $box->conformity);
        $this->assertSame(AuditRepairState::Validated, $box->repair_state);
        $this->assertSame(AuditInterventionStatus::Completed, $intervention->refresh()->status);
        $this->assertNotNull($intervention->closed_at);

        $this->expectException(LogicException::class);
        $first->forceFill(['state' => AuditOccurrenceState::Refused])->save();
    }

    public function test_refusing_every_repair_leaves_the_box_non_conforming(): void
    {
        [$box, [$occurrence]] = $this->inspectedBox();
        $this->openPhase();
        $this->plan($box, $this->partner);
        $this->repair($occurrence, $this->partner);

        $result = $this->decideRepairs($this->submitRepairs($box, $this->partner), [$occurrence->id => ['accepted' => false, 'reason' => 'after photo unreadable']]);

        $this->assertSame('repairs_refused', $result['decision']->type->code);
        $this->assertSame('after photo unreadable', $result['decision']->reason === null ? null : str_replace($occurrence->reference.' : ', '', $result['decision']->reason));
        $this->assertSame(AuditConformity::NotAssessed, $box->refresh()->conformity);
    }

    public function test_conformity_waits_for_every_open_anomaly_including_an_optical_one_allowed_to_wait(): void
    {
        [$box, [$mechanical], [$optical]] = $this->inspectedBox(mechanical: 1, optical: 1);
        $this->openPhase();
        $this->plan($box, $this->partner);
        $this->repair($mechanical, $this->partner);

        $result = $this->decideRepairs($this->submitRepairs($box, $this->partner), [$mechanical->id => ['accepted' => true]]);

        $this->assertSame(AuditConformity::NotAssessed, $result['conformity']);
        $this->assertSame(AuditRepairState::None, $box->refresh()->repair_state, 'an optical repair is still to plan');
        $this->assertSame([$box->id], $this->planning()->pool($this->audit)->modelKeys());

        $this->plan($box, $this->partner);
        $this->repair($optical, $this->partner);
        $final = $this->decideRepairs($this->submitRepairs($box, $this->partner), [$optical->id => ['accepted' => true]]);

        $this->assertSame(AuditConformity::Conforming, $final['conformity']);
    }

    public function test_a_second_decision_on_a_repairs_request_is_told_the_first_one(): void
    {
        [$box, [$occurrence]] = $this->inspectedBox();
        $this->openPhase();
        $this->plan($box, $this->partner);
        $this->repair($occurrence, $this->partner);
        $request = $this->submitRepairs($box, $this->partner);

        $this->decideRepairs($request, [$occurrence->id => ['accepted' => true]]);

        $this->assertSame('409:already_decided', $this->reason(fn () => $this->decideRepairs($request, [$occurrence->id => ['accepted' => false, 'reason' => 'concurrent refusal']])));
        $this->assertSame(1, AuditDecision::query()->where('audit_validation_request_id', $request->id)->count());
        $this->assertSame(1, AuditRepairItem::query()->count());
    }

    public function test_the_pool_lists_the_boxes_to_repair_and_technicians_see_only_their_interventions(): void
    {
        [$box] = $this->inspectedBox(mechanical: 2);
        [$other] = $this->inspectedBox(mechanical: 1);
        $this->openPhase();
        $this->plan($box, $this->partner, [$this->third]);

        $pool = $this->planning()->pool($this->audit)->keyBy('id');

        $this->assertEqualsCanonicalizing([$box->id, $other->id], $pool->keys()->all());
        $this->assertSame(2, $pool[$box->id]->getAttribute('repairs')->count());
        $this->assertNotNull($pool[$box->id]->getAttribute('open_intervention'));
        $this->assertNull($pool[$other->id]->getAttribute('open_intervention'));
        $this->assertSame([$box->id], $this->planning()->interventionsOf($this->third)->pluck('audit_box_id')->all());
        $this->assertCount(0, $this->planning()->interventionsOf($this->technician), 'the team of phase 1 does not see the intervention');
    }

    public function test_the_recheck_of_the_box_can_be_attached_to_the_repairs(): void
    {
        [$box, [$occurrence]] = $this->inspectedBox();
        $this->openPhase();
        $this->plan($box, $this->partner);
        $this->repair($occurrence, $this->partner);

        $this->assertSame('422:no_recheck', $this->reason(fn () => $this->submitRepairs($box, $this->partner, recheck: true)));

        $recheck = AuditBoxRecheck::query()->create(['audit_box_id' => $box->id, 'is_conform' => true, 'recnok_name' => 'RECNOK_box.xlsx', 'saved_at' => now(), 'saved_by_name' => $this->partner->name]);
        $request = $this->submitRepairs($box, $this->partner, recheck: true);

        $this->assertSame($recheck->id, $request->submission->snapshot['recheck']['id']);
        $this->assertTrue($request->submission->snapshot['recheck']['is_conform']);
    }

    public function test_the_whole_cycle_is_in_the_hash_chained_journal(): void
    {
        [$box, [$occurrence]] = $this->inspectedBox();
        $this->openPhase();
        $this->plan($box, $this->partner);
        $this->repair($occurrence, $this->partner);
        $this->decideRepairs($this->submitRepairs($box, $this->partner), [$occurrence->id => ['accepted' => true]]);

        $this->assertTrue(app(WorkflowJournal::class)->verify()['intact']);
        $events = Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->pluck('event')->all();

        foreach (['repair_phase_opened', 'intervention_planned', 'repairs_submitted', 'repairs_decided', 'box_conformity_changed'] as $event) {
            $this->assertContains($event, $events, $event);
        }
    }
}
