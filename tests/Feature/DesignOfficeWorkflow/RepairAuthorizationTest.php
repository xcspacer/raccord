<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\RepairPlanning;
use App\DesignOfficeWorkflow\SubmitRepairs;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditFolder;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\DesignOfficeWorkflow\RepairDecisionForm;
use App\Livewire\DesignOfficeWorkflow\RepairsPage;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditValidationRequest;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Server-side authorization of phase 2 of the design office workflow (spec 13 §2): the « Phase 2 — Reprises » screen
 * and its actions are the supervisors', the repairs JSON of the phone the technicians', the decision per repair the
 * assigned reviewer's (or a supervisor's). No workflow: module off 404, « Gérer les accès » switch off or wrong role 403;
 * an audit or a request that is not the person's does not exist (404).
 */
class RepairAuthorizationTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    private function switchOff(Role $role): void
    {
        RoleFeatureOverride::query()->create(['role_id' => RoleModel::findByName($role->value)->id, 'feature' => Feature::DesignOfficeWorkflow, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
    }

    /**
     * A box whose inspection (one mechanical anomaly) was validated, in an audit now in phase 2.
     *
     * @return array{0: AuditBox, 1: AuditOccurrence}
     */
    private function boxToRepair(): array
    {
        $box = $this->readyBox();
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => AuditAnomalyNature::Mechanical, 'state' => AuditOccurrenceState::Detected,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Mechanical)->value('id'),
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $this->technician->id]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_photo_id' => $box->photos()->value('id'), 'role' => AuditOccurrencePhotoRole::Before, 'linked_at' => now()]);
        $this->decide($this->submit($box), 'validate', reason: null);
        app(RepairPlanning::class)->openPhase($this->supervisor, $this->audit);

        return [$box->refresh(), $occurrence->refresh()];
    }

    /**
     * The repairs of the box planned for the partner, done and submitted.
     */
    private function submittedRepairs(): AuditValidationRequest
    {
        [$box, $occurrence] = $this->boxToRepair();
        app(RepairPlanning::class)->plan($this->supervisor, $box, now()->toDateString(), $this->partner->id);
        app(OccurrenceLifecycle::class)->correct($this->partner, $occurrence, 'sealant applied', [$this->photo($box, AuditFolder::Fixing)->id]);

        return app(SubmitRepairs::class)->handle($this->partner, $box, $this->operationId())['request'];
    }

    public function test_the_repairs_screen_is_for_the_supervisors(): void
    {
        $this->actingAs($this->technician)->get(route('design-office.repairs'))->assertForbidden();
        $this->actingAs($this->reviewer)->get(route('design-office.repairs'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('design-office.repairs'))->assertOk()->assertSee($this->audit->cabinet_key);

        $this->switchOff(Role::Supervisor);
        $this->actingAs($this->supervisor)->get(route('design-office.repairs'))->assertForbidden();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.repairs'))->assertNotFound();
    }

    public function test_the_supervisor_opens_the_phase_plans_and_cancels_from_the_screen(): void
    {
        $box = $this->readyBox();
        $occurrence = AuditOccurrence::factory()->create(['audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'state' => AuditOccurrenceState::Detected, 'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Mechanical)->value('id')]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now()]);
        $request = $this->submit($box);

        $page = Livewire::actingAs($this->supervisor)->test(RepairsPage::class)
            ->assertSet('auditId', $this->audit->id)
            ->call('openPhase')
            ->assertHasErrors('action');
        $this->assertSame(AuditWorkflowPhase::Inspection, $this->audit->refresh()->phase);

        $this->decide($request, 'validate', reason: null);
        $page->call('openPhase')->assertHasNoErrors()->assertSee($box->name);
        $this->assertSame(AuditWorkflowPhase::Repairs, $this->audit->refresh()->phase);

        $page->call('startPlanning', $box->id)
            ->assertSee($this->technician->name)
            ->set('plannedOn', 'not a date')
            ->call('plan')
            ->assertHasErrors('plannedOn')
            ->set('plannedOn', now()->toDateString())
            ->set('responsibleId', (string) $this->partner->id)
            ->set('memberIds', [(string) $this->technician->id])
            ->call('plan')
            ->assertHasNoErrors()
            ->assertSet('planningBoxId', null);

        $intervention = AuditIntervention::query()->sole();
        $this->assertSame($this->partner->id, $intervention->responsible_id);

        $page->call('askCancel', $intervention->id)
            ->set('cancellationReason', 'x')
            ->call('cancel')
            ->assertHasErrors('cancellationReason')
            ->set('cancellationReason', 'weather alert')
            ->call('cancel')
            ->assertHasNoErrors();
        $this->assertSame(AuditInterventionStatus::Cancelled, $intervention->refresh()->status);
    }

    public function test_the_actions_of_the_screen_are_refused_to_whoever_is_not_a_supervisor(): void
    {
        [$box] = $this->boxToRepair();
        $intervention = app(RepairPlanning::class)->plan($this->supervisor, $box, now()->toDateString(), $this->partner->id);
        $pages = array_map(fn () => Livewire::actingAs($this->supervisor)->test(RepairsPage::class), range(1, 3));

        $this->switchOff(Role::Supervisor);
        $pages[0]->call('startPlanning', $box->id)->assertForbidden();
        $pages[1]->call('askCancel', $intervention->id)->assertForbidden();
        $pages[2]->call('openPhase')->assertForbidden();
        $this->assertSame(AuditInterventionStatus::Planned, $intervention->refresh()->status);
    }

    public function test_the_phone_submits_the_repairs_of_the_responsible_of_the_intervention(): void
    {
        [$box, $occurrence] = $this->boxToRepair();
        app(RepairPlanning::class)->plan($this->supervisor, $box, now()->toDateString(), $this->partner->id, [$this->technician->id]);
        app(OccurrenceLifecycle::class)->correct($this->partner, $occurrence, 'sealant applied', [$this->photo($box, AuditFolder::Fixing)->id]);

        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op1'])->assertForbidden();
        $this->actingAs($this->person(Role::Technician))->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op1'])->assertNotFound();
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.submit-repairs', $box), [])->assertUnprocessable()->assertJsonValidationErrors('operation_id');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op_member'])
            ->assertForbidden()->assertJsonPath('reason', 'not_responsible');
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op_lead', 'attach_recheck' => true])
            ->assertUnprocessable()->assertJsonPath('reason', 'no_recheck');
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op_lead'])
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('status.box.repair_state', 'submitted')
            ->assertJsonPath('status.request.kind', 'repair')->assertJsonPath('status.repairs.intervention.status', 'submitted');
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op_lead'])
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->switchWorkflowModule(false);
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => 'op_again'])->assertNotFound();
    }

    public function test_technicians_read_their_interventions_and_the_repairs_of_a_box(): void
    {
        [$box] = $this->boxToRepair();
        app(RepairPlanning::class)->plan($this->supervisor, $box, now()->toDateString(), $this->partner->id);

        $this->actingAs($this->partner)->getJson(route('design-office.interventions.index'))
            ->assertOk()->assertJsonCount(1, 'interventions')->assertJsonPath('interventions.0.box.id', $box->id)
            ->assertJsonPath('interventions.0.is_responsible', true)->assertJsonPath('interventions.0.repairs.0.state', 'to_repair');
        $this->actingAs($this->technician)->getJson(route('design-office.interventions.index'))->assertOk()->assertJsonCount(0, 'interventions');
        $this->actingAs($this->reviewer)->getJson(route('design-office.interventions.index'))->assertForbidden();
        $this->actingAs($this->partner)->getJson(route('design-office.boxes.show', $box))
            ->assertOk()->assertJsonPath('repairs.phase', 2)->assertJsonPath('repairs.intervention.is_member', true)->assertJsonPath('repairs.can_submit', false);

        $this->switchOff(Role::Technician);
        $this->actingAs($this->partner)->getJson(route('design-office.interventions.index'))->assertForbidden();
    }

    public function test_the_assigned_reviewer_decides_each_repair_on_the_request_screen(): void
    {
        $request = $this->submittedRepairs();
        $assigned = User::query()->findOrFail($request->assigned_to);
        $other = $assigned->is($this->reviewer) ? $this->otherReviewer : $this->reviewer;
        $occurrenceId = $request->submission->snapshot['items'][0]['id'];

        $this->actingAs($other)->get(route('design-office.requests.show', $request))->assertNotFound();
        $this->actingAs($assigned)->get(route('design-office.requests.show', $request))->assertOk()->assertSee(__('design_office_workflow.repairs.items_title'));
        Livewire::actingAs($other)->test(RepairDecisionForm::class, ['requestId' => $request->id])->assertNotFound();

        Livewire::actingAs($assigned)->test(RepairDecisionForm::class, ['requestId' => $request->id])
            ->call('decide')
            ->assertHasErrors('decision')
            ->set('verdicts.'.$occurrenceId, 'refused')
            ->call('decide')
            ->assertHasErrors('decision')
            ->set('reasons.'.$occurrenceId, 'after photo blurred')
            ->call('decide')
            ->assertHasNoErrors()
            ->assertSee(__('design_office_workflow.decision_types.repairs_refused'));

        $this->assertSame(AuditOccurrenceState::Refused, AuditOccurrence::query()->findOrFail($occurrenceId)->state);
    }

    public function test_the_repair_form_is_only_for_repairs_requests(): void
    {
        $request = $this->submit($this->readyBox());

        Livewire::actingAs($this->supervisor)->test(RepairDecisionForm::class, ['requestId' => $request->id])->assertNotFound();
    }
}
