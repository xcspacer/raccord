<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxTeam;
use App\Enums\AuditRequestState;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Admin\Users\UserCard;
use App\Livewire\DesignOfficeWorkflow\QueuePage;
use App\Livewire\DesignOfficeWorkflow\RequestPage;
use App\Models\AuditBoxMessage;
use App\Models\AuditDecision;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Server-side authorization of every action of phase 1 of the design office workflow (spec 13 §2): the box actions of
 * the technicians (JSON for the phone), the queue and decision screens of the design office and the supervisors, and
 * availability. Convention: no workflow (module off: 404; « Gérer les accès » switch off or wrong role: 403), an
 * audit or a request that is not the person's does not exist (404).
 */
class WorkflowAuthorizationTest extends TestCase
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

    public function test_a_planned_technician_starts_a_box_and_reads_its_status(): void
    {
        $box = $this->box();

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.claim', $box))
            ->assertOk()->assertJsonPath('responsible_id', $this->technician->id)->assertJsonPath('status.box.is_responsible', true);
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.claim', $box))
            ->assertOk()->assertJsonPath('joined', true);
        $this->actingAs($this->partner)->getJson(route('design-office.boxes.show', $box))
            ->assertOk()->assertJsonPath('box.inspection_state', 'in_progress')->assertJsonPath('request', null);
    }

    public function test_box_actions_are_refused_to_whoever_is_not_a_planned_technician(): void
    {
        $box = $this->box();
        $stranger = $this->person(Role::Technician);

        $this->actingAs($stranger)->postJson(route('design-office.boxes.claim', $box))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('design-office.boxes.show', $box))->assertNotFound();
        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.claim', $box))->assertForbidden();
        $this->actingAs($this->supervisor)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => 'op1'])->assertForbidden();
        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.hand-over', $box))->assertForbidden();
        $this->actingAs($this->reviewer)->getJson(route('design-office.boxes.show', $box))->assertOk();
    }

    public function test_box_actions_follow_the_module_and_the_access_switch(): void
    {
        $box = $this->box();

        $this->switchOff(Role::Technician);
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.claim', $box))->assertForbidden();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.claim', $box))->assertNotFound();
    }

    public function test_the_submission_endpoint_checks_the_responsible_and_validates_its_input(): void
    {
        $box = $this->readyBox();
        app(BoxTeam::class)->claim($this->partner, $box);

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), [])->assertUnprocessable()->assertJsonValidationErrors('operation_id');
        $this->actingAs($this->partner)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => 'op_partner'])
            ->assertForbidden()->assertJsonPath('reason', 'not_responsible');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => 'op_lead'])
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('status.box.inspection_state', 'submitted');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => 'op_lead'])
            ->assertOk()->assertJsonPath('duplicate', true);
    }

    public function test_a_standard_audit_answers_409_to_every_box_action(): void
    {
        $box = $this->box($this->plannedAudit([$this->technician], designOffice: false));

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.claim', $box))->assertStatus(409)->assertJsonPath('reason', 'standard_workflow');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => 'op1'])->assertStatus(409);
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.hand-over', $box))->assertStatus(409);
    }

    public function test_hand_over_is_for_the_responsible_only(): void
    {
        $box = $this->readyBox();
        app(BoxTeam::class)->claim($this->partner, $box);

        $this->actingAs($this->partner)->postJson(route('design-office.boxes.hand-over', $box))->assertForbidden()->assertJsonPath('reason', 'not_responsible');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.hand-over', $box), ['to_user_id' => $this->partner->id])
            ->assertOk()->assertJsonPath('responsible_id', $this->partner->id);
    }

    public function test_the_queue_is_for_the_design_office_and_the_supervisors(): void
    {
        $this->actingAs($this->technician)->get(route('design-office.queue'))->assertForbidden();
        $this->actingAs($this->reviewer)->get(route('design-office.queue'))->assertOk();
        $this->actingAs($this->supervisor)->get(route('design-office.queue'))->assertOk();

        $this->switchOff(Role::DesignOffice);
        $this->actingAs($this->reviewer)->get(route('design-office.queue'))->assertForbidden();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.queue'))->assertNotFound();
    }

    public function test_a_reviewer_only_opens_the_requests_given_to_them(): void
    {
        $request = $this->submit($this->readyBox());
        [$assigned, $other] = $request->assigned_to === $this->reviewer->id ? [$this->reviewer, $this->otherReviewer] : [$this->otherReviewer, $this->reviewer];

        $this->actingAs($other)->get(route('design-office.requests.show', $request))->assertNotFound();
        $this->actingAs($this->technician)->get(route('design-office.requests.show', $request))->assertForbidden();
        $this->actingAs($assigned)->get(route('design-office.requests.show', $request))->assertOk()->assertSee($request->box->name);
        $this->assertSame(AuditRequestState::InReview, $request->refresh()->state);
        $this->actingAs($this->supervisor)->get(route('design-office.requests.show', $request))->assertOk();
    }

    public function test_the_assigned_reviewer_decides_on_the_decision_screen(): void
    {
        $request = $this->submit($this->readyBox());
        $assigned = User::query()->findOrFail($request->assigned_to);

        Livewire::actingAs($assigned)->test(RequestPage::class, ['validationRequest' => $request])
            ->set('decision', 'refuse')
            ->set('reason', 'labels unreadable')
            ->call('decide')
            ->assertHasErrors('decision')
            ->set('targets', "take the labels again\ncheck the cassettes")
            ->call('decide')
            ->assertHasNoErrors()
            ->assertSee(__('design_office_workflow.decision_types.refuse'));

        $this->assertSame(['take the labels again', 'check the cassettes'], AuditDecision::query()->sole()->targets);
    }

    public function test_a_second_decision_on_the_screen_is_told_the_first_one(): void
    {
        $request = $this->submit($this->readyBox());
        $page = Livewire::actingAs($this->supervisor)->test(RequestPage::class, ['validationRequest' => $request]);
        $this->decide($request, 'validate', reason: null);

        $page->set('decision', 'request_control')->set('reason', 'continuity measure')->call('decide')->assertHasErrors('decision');
        $this->assertSame(1, AuditDecision::query()->count());
    }

    public function test_queue_actions_are_checked_on_the_server(): void
    {
        $request = $this->submit($this->readyBox());
        $other = $request->assigned_to === $this->reviewer->id ? $this->otherReviewer : $this->reviewer;
        $box = $this->readyBox($this->partner);

        Livewire::actingAs($this->reviewer)->test(QueuePage::class)->call('reassign', $request->id, $other->id)->assertForbidden();
        Livewire::actingAs($this->reviewer)->test(QueuePage::class)->call('designate', $box->id, $this->technician->id)->assertForbidden();
        Livewire::actingAs($this->reviewer)->test(QueuePage::class)->call('toggleAvailability', $this->otherReviewer->id)->assertForbidden();

        Livewire::actingAs($this->reviewer)->test(QueuePage::class)->call('toggleAvailability');
        $this->assertFalse($this->reviewer->refresh()->is_available_for_review);

        Livewire::actingAs($this->supervisor)->test(QueuePage::class)->call('reassign', $request->id, $other->id)->assertHasNoErrors();
        $this->assertSame($other->id, $request->refresh()->assigned_to);
        Livewire::actingAs($this->supervisor)->test(QueuePage::class)->call('toggleAvailability', $this->otherReviewer->id);
        $this->assertFalse($this->otherReviewer->refresh()->is_available_for_review);
    }

    public function test_the_design_office_resolves_controls_from_the_queue(): void
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'request_control', reason: 'measure asked');
        $message = AuditBoxMessage::query()->sole();

        Livewire::actingAs($this->reviewer)->test(QueuePage::class)->assertSee('measure asked')->call('resolveMessage', $message->id);

        $this->assertNotNull($message->refresh()->resolved_at);
    }

    public function test_the_administrator_sets_the_availability_from_user_management(): void
    {
        $admin = $this->person(Role::Administrator);

        Livewire::actingAs($admin)->test(UserCard::class, ['user' => $this->reviewer])->call('toggleReviewAvailability');
        $this->assertFalse($this->reviewer->refresh()->is_available_for_review);

        Livewire::actingAs($admin)->test(UserCard::class, ['user' => $this->technician])->call('toggleReviewAvailability')->assertNotFound();
    }
}
