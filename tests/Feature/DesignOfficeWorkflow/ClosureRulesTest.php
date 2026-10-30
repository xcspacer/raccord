<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\CloseAudit;
use App\DesignOfficeWorkflow\ClosingChecks;
use App\DesignOfficeWorkflow\Conformity;
use App\DesignOfficeWorkflow\ReopenAudit;
use App\DesignOfficeWorkflow\Reserves;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditInspectionState;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditReserveKind;
use App\Enums\AuditReserveProposalState;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Role;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditDecision;
use App\Models\AuditDossierVersion;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditReopening;
use App\Models\AuditReserve;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Phase 3 of the design office workflow (spec 13 §4.29–31, §4.34): derived conformity, reserves proposed by the
 * design office and accepted or closed by a supervisor only, the closing checks, the irreversible closure with its
 * express confirmation, and the formal reopening by a supervisor.
 */
class ClosureRulesTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    /**
     * @return array{int, string}
     */
    private function refusal(callable $action): array
    {
        try {
            $action();
        } catch (WorkflowException $exception) {
            return [$exception->status, $exception->reason];
        }

        $this->fail('Expected a refusal.');
    }

    private function validatedBox(?User $technician = null): AuditBox
    {
        $box = $this->readyBox($technician ?? $this->person(Role::Technician));
        $this->decide($this->submit($box, $box->responsible), 'validate', reason: null);

        return $box->refresh();
    }

    private function occurrence(AuditBox $box, AuditAnomalyNature $nature = AuditAnomalyNature::Mechanical, AuditOccurrenceState $state = AuditOccurrenceState::ToRepair): AuditOccurrence
    {
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => $nature, 'state' => $state,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', $nature)->value('id'),
        ]);
        $this->link($occurrence, $box, true);

        return $occurrence;
    }

    private function link(AuditOccurrence $occurrence, AuditBox $box, bool $origin = false): void
    {
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => $origin, 'added_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $this->reviewer->id]);
    }

    private function readyToClose(): AuditBox
    {
        $this->audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();

        return $this->validatedBox();
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        return Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->orderBy('id')->pluck('event')->all();
    }

    public function test_a_conforming_box_goes_back_to_not_assessed_when_an_anomaly_opens_on_it(): void
    {
        $box = $this->validatedBox();
        $this->assertSame(AuditConformity::Conforming, $box->conformity);

        $this->occurrence($box);
        app(Conformity::class)->recompute($this->reviewer, $box);

        $this->assertSame(AuditConformity::NotAssessed, $box->refresh()->conformity);
        $this->assertSame(2, collect($this->events())->filter(fn (string $event) => $event === 'box_conformity_changed')->count());
    }

    public function test_the_design_office_proposes_a_reserve_and_only_a_supervisor_accepts_it(): void
    {
        $box = $this->validatedBox();
        $other = $this->validatedBox();
        $occurrence = $this->occurrence($box);
        $this->link($occurrence, $other);
        $reserves = app(Reserves::class);

        $this->assertSame([422, 'reserve_reason_required'], $this->refusal(fn () => $reserves->propose($this->reviewer, $occurrence, 'too short')));
        $this->assertSame([403, 'forbidden'], $this->refusal(fn () => $reserves->propose($this->technician, $occurrence, 'the seal cannot be found anywhere')));
        $reserves->propose($this->reviewer, $occurrence, 'the seal cannot be found anywhere');
        $proposal = $reserves->propose($this->otherReviewer, $occurrence, 'nothing to change on site, client agreed');
        $this->assertSame(1, $occurrence->reserveProposals()->count(), 'one open proposal per anomaly');

        $this->assertSame([403, 'supervisor_only'], $this->refusal(fn () => $reserves->accept($this->reviewer, $occurrence, AuditReserveKind::FollowUp, 'accepted with a follow-up visit')));
        $this->assertSame([422, 'reserve_reason_required'], $this->refusal(fn () => $reserves->accept($this->supervisor, $occurrence, AuditReserveKind::FollowUp, 'short')));

        $boxes = $reserves->accept($this->supervisor, $occurrence, AuditReserveKind::FollowUp, 'accepted with a follow-up visit');

        $this->assertEqualsCanonicalizing([$box->name, $other->name], $boxes);
        $this->assertSame(AuditOccurrenceState::ReserveAccepted, $occurrence->refresh()->state);
        $this->assertSame(AuditReserveProposalState::Accepted, $proposal->refresh()->state);
        $this->assertSame($this->supervisor->id, $proposal->decided_by);
        $this->assertSame(2, AuditReserve::query()->where('audit_occurrence_id', $occurrence->id)->whereNull('closed_at')->where('audit_reserve_proposal_id', $proposal->id)->count());
        $this->assertSame(AuditConformity::ConformingWithReserve, $box->refresh()->conformity);
        $this->assertSame(AuditConformity::ConformingWithReserve, $other->refresh()->conformity);
        $this->assertContains('reserve_proposed', $this->events());
        $this->assertContains('reserve_accepted', $this->events());
    }

    public function test_an_administrative_closure_is_closed_at_once_and_a_follow_up_one_by_a_supervisor(): void
    {
        $reserves = app(Reserves::class);
        $first = $this->occurrence($this->validatedBox());
        $second = $this->occurrence($this->validatedBox());

        $reserves->accept($this->supervisor, $first, AuditReserveKind::AdministrativeClosure, 'no technical correction claimed');
        $this->assertNotNull(AuditReserve::query()->where('audit_occurrence_id', $first->id)->value('closed_at'));

        $reserves->accept($this->supervisor, $second, AuditReserveKind::FollowUp, 'to follow with the client');
        $reserve = AuditReserve::query()->where('audit_occurrence_id', $second->id)->firstOrFail();
        $this->assertTrue($reserve->isOpen());

        $this->assertSame([403, 'supervisor_only'], $this->refusal(fn () => $reserves->close($this->reviewer, $reserve, 'follow-up done')));
        $this->assertSame([422, 'reserve_reason_required'], $this->refusal(fn () => $reserves->close($this->supervisor, $reserve, 'ok')));
        $reserves->close($this->supervisor, $reserve, 'follow-up done');
        $this->assertSame('follow-up done', $reserve->refresh()->closing_reason);
        $this->assertSame([409, 'reserve_already_closed'], $this->refusal(fn () => $reserves->close($this->supervisor, $reserve, 'follow-up done')));
    }

    public function test_a_reserve_covers_only_an_anomaly_still_open_without_a_repair_waiting(): void
    {
        $reserves = app(Reserves::class);
        $box = $this->validatedBox();
        $corrected = $this->occurrence($box, state: AuditOccurrenceState::Corrected);
        $validated = $this->occurrence($box, AuditAnomalyNature::Optical, AuditOccurrenceState::Validated);

        $this->assertSame([409, 'repair_awaiting_decision'], $this->refusal(fn () => $reserves->accept($this->supervisor, $corrected, AuditReserveKind::FollowUp, 'accepted with a follow-up visit')));
        $this->assertSame([409, 'occurrence_not_open'], $this->refusal(fn () => $reserves->propose($this->reviewer, $validated, 'the seal cannot be found anywhere')));
    }

    public function test_accepting_a_reserve_cancels_the_planned_visit_left_without_repair(): void
    {
        $box = $this->validatedBox();
        $occurrence = $this->occurrence($box);
        $box->forceFill(['repair_state' => AuditRepairState::Planned])->save();
        $intervention = AuditIntervention::factory()->create(['audit_id' => $box->audit_id, 'audit_box_id' => $box->id, 'status' => AuditInterventionStatus::Planned, 'responsible_id' => $this->technician->id]);

        app(Reserves::class)->accept($this->supervisor, $occurrence, AuditReserveKind::FollowUp, 'accepted with a follow-up visit');

        $this->assertSame(AuditInterventionStatus::Cancelled, $intervention->refresh()->status);
        $this->assertSame(AuditRepairState::None, $box->refresh()->repair_state);
        $this->assertContains('intervention_cancelled', $this->events());
    }

    public function test_the_closing_checks_list_what_blocks_and_what_only_warns(): void
    {
        $waiting = $this->readyBox();
        $this->submit($waiting);
        $conforming = $this->validatedBox();
        $open = $this->occurrence($conforming);
        app(Conformity::class)->recompute($this->supervisor, $conforming);
        $notInstalled = $this->box(attributes: ['status' => 'not_installed']);

        $report = app(ClosingChecks::class)->report($this->audit);
        $blockers = collect($report['blockers'])->pluck('items', 'code');

        $this->assertFalse($report['ok']);
        $this->assertSame(['phase', 'requests_open', 'inspection_not_validated', 'boxes_not_conforming', 'occurrences_open'], $blockers->keys()->all());
        $this->assertSame([$waiting->name], $blockers['requests_open']);
        $this->assertNotContains($notInstalled->name, $blockers['inspection_not_validated'], 'a box not installed is out of the count');
        $this->assertSame([$open->reference.' ('.$conforming->name.')'], $blockers['occurrences_open']);
        $this->assertSame(['synoptic_missing'], array_column($report['warnings'], 'code'));
        $this->assertSame(2, $report['summary']['boxes']);
    }

    public function test_the_closing_checks_warn_about_refused_proofs_and_open_follow_up_reserves(): void
    {
        $box = $this->readyBox();
        $box->photos()->where('folder', AuditFolder::Labels->value)->delete();
        $this->decide($this->submit($box, justifications: ['labels' => 'label torn off by the wind']), 'validate_with_reserves', proofs: ['labels' => false]);
        $occurrence = $this->occurrence($box->refresh());
        app(Reserves::class)->accept($this->supervisor, $occurrence, AuditReserveKind::FollowUp, 'to follow with the client');

        $warnings = collect(app(ClosingChecks::class)->report($this->audit)['warnings'])->pluck('items', 'code');

        $this->assertSame([$box->name.' / '.AuditFolder::Labels->label()], $warnings['proofs_refused']);
        $this->assertSame([$box->name.' — '.$occurrence->reference], $warnings['follow_up_reserves_open']);
    }

    public function test_closing_needs_a_supervisor_an_express_confirmation_and_every_check(): void
    {
        $close = app(CloseAudit::class);
        $this->validatedBox();

        $this->assertSame([403, 'supervisor_only'], $this->refusal(fn () => $close->handle($this->reviewer, $this->audit, true)));
        $this->assertSame([422, 'confirmation_required'], $this->refusal(fn () => $close->handle($this->supervisor, $this->audit, false)));
        $this->assertSame([409, 'closing_blocked'], $this->refusal(fn () => $close->handle($this->supervisor, $this->audit, true)));
        $this->assertNull($this->audit->refresh()->closed_at);

        $this->audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();
        $version = $close->handle($this->supervisor, $this->audit, true);

        $this->audit->refresh();
        $this->assertNotNull($this->audit->closed_at);
        $this->assertSame($this->supervisor->id, $this->audit->closed_by);
        $this->assertSame(AuditWorkflowPhase::Closure, $this->audit->phase);
        $this->assertSame([1, AuditDossierKind::Initial, AuditDossierStatus::Pending, $this->supervisor->id], [$version->version_number, $version->kind, $version->status, $version->authorized_by]);
        $this->assertContains('audit_closed', $this->events());
        $this->assertSame([409, 'already_closed'], $this->refusal(fn () => $close->handle($this->supervisor, $this->audit, true)));
    }

    public function test_a_closed_audit_refuses_reserves_but_still_closes_its_follow_up_ones(): void
    {
        $box = $this->readyToClose();
        $occurrence = $this->occurrence($box);
        $reserves = app(Reserves::class);
        $reserves->accept($this->supervisor, $occurrence, AuditReserveKind::FollowUp, 'to follow with the client');
        app(CloseAudit::class)->handle($this->supervisor, $this->audit, true);

        $this->assertSame([409, 'audit_closed'], $this->refusal(fn () => $reserves->propose($this->reviewer, $this->occurrence($box), 'the seal cannot be found anywhere')));
        $reserves->close($this->supervisor, AuditReserve::query()->firstOrFail(), 'follow-up done after delivery');
        $this->assertNull(AuditReserve::query()->whereNull('closed_at')->first());
    }

    public function test_only_a_supervisor_reopens_a_closed_audit_formally_and_other_validations_stay(): void
    {
        $reopen = app(ReopenAudit::class);
        $taken = $this->readyToClose();
        $kept = $this->validatedBox();
        $decisions = AuditDecision::query()->count();

        $this->assertSame([409, 'audit_not_closed'], $this->refusal(fn () => $reopen->handle($this->supervisor, $this->audit, 'label to redo on site', [$taken->id])));
        app(CloseAudit::class)->handle($this->supervisor, $this->audit, true);

        $this->assertSame([403, 'supervisor_only'], $this->refusal(fn () => $reopen->handle($this->reviewer, $this->audit, 'label to redo on site', [$taken->id])));
        $this->assertSame([422, 'reopening_reason_required'], $this->refusal(fn () => $reopen->handle($this->supervisor, $this->audit, 'redo', [$taken->id])));
        $this->assertSame([422, 'reopening_boxes_required'], $this->refusal(fn () => $reopen->handle($this->supervisor, $this->audit, 'label to redo on site', [])));
        $this->assertSame([404, 'unknown_box'], $this->refusal(fn () => $reopen->handle($this->supervisor, $this->audit, 'label to redo on site', [$this->box($this->plannedAudit([$this->technician]))->id])));
        $this->assertSame([409, 'dossier_in_progress'], $this->refusal(fn () => $reopen->handle($this->supervisor, $this->audit, 'label to redo on site', [$taken->id])));

        AuditDossierVersion::query()->firstOrFail()->forceFill(['status' => AuditDossierStatus::Published, 'published_at' => now()])->save();
        $reopening = $reopen->handle($this->supervisor, $this->audit, 'label to redo on site', [$taken->id]);

        $this->assertNull($this->audit->refresh()->closed_at);
        $this->assertSame([$taken->id], $reopening->box_ids);
        $this->assertSame([AuditInspectionState::InProgress, AuditConformity::NotAssessed], [$taken->refresh()->inspection_state, $taken->conformity]);
        $this->assertSame([AuditInspectionState::Validated, AuditConformity::Conforming], [$kept->refresh()->inspection_state, $kept->conformity]);
        $this->assertSame($decisions, AuditDecision::query()->count());
        $this->assertSame(AuditDossierStatus::Published, AuditDossierVersion::query()->firstOrFail()->status);
        $this->assertContains('audit_reopened', $this->events());

        $this->decide($this->submit($taken, $taken->responsible), 'validate', reason: null);
        $version = app(CloseAudit::class)->handle($this->supervisor, $this->audit, true);

        $this->assertSame([2, AuditDossierKind::Update, 'label to redo on site'], [$version->version_number, $version->kind, $version->reason]);
        $this->assertSame(1, AuditReopening::query()->count());
    }
}
