<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\DesignOfficeWorkflow\BoxTeam;
use App\Enums\AuditFolder;
use App\Enums\AuditWorkflow;
use App\Enums\Role;
use App\Livewire\Admin\PlatformSettings;
use App\Livewire\Audit\AuditConfigure;
use App\Models\Audit;
use App\Models\User;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Base and configuration of the design office workflow (spec 13 §4.1, 6, 7): the workflow chosen per PM and locked by
 * the first field activity, the standard validation steps it replaces, and its settings.
 */
class WorkflowConfigurationTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function apply(User $user, Audit $audit, array $operation): string
    {
        try {
            app(AuditOperations::class)->apply($user, $audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $operation);
        } catch (AuditOperationException $exception) {
            return $exception->status.':'.$exception->reason;
        }

        return 'ok';
    }

    public function test_the_workflow_is_chosen_before_any_field_activity_then_locked(): void
    {
        $audit = $this->plannedAudit([$this->technician], designOffice: false);

        $this->assertSame('ok', $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'design_office']));
        $this->assertSame(AuditWorkflow::DesignOffice, $audit->refresh()->workflow);
        $this->assertSame('ok', $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'standard']));
        $this->assertSame('422:invalid_workflow', $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'other']));

        $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'design_office']);
        app(BoxTeam::class)->claim($this->technician, $this->box($audit));

        $this->assertSame('409:workflow_locked', $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'standard']));
        $this->assertSame('ok', $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'design_office', 'sro' => 'SRO-1']));
    }

    public function test_photos_lock_the_workflow_of_a_standard_audit(): void
    {
        $audit = $this->plannedAudit([$this->technician], designOffice: false);
        $this->photo($this->box($audit), AuditFolder::Closed);

        $this->assertSame('409:workflow_locked', $this->apply($this->supervisor, $audit, ['op' => 'setup', 'workflow' => 'design_office']));
    }

    public function test_folder_validation_and_office_remarks_are_replaced_by_the_design_office_decisions(): void
    {
        $box = $this->box();

        $this->assertSame('409:design_office_workflow', $this->apply($this->supervisor, $this->audit, ['op' => 'folder_validate', 'box' => $box->name, 'folder' => 'closed']));
        $this->assertSame('409:design_office_workflow', $this->apply($this->supervisor, $this->audit, ['op' => 'remark', 'box' => $box->name, 'text' => 'see labels']));
    }

    public function test_the_configure_form_offers_the_workflow_while_the_module_is_on(): void
    {
        $audit = Audit::factory()->create();

        Livewire::actingAs($this->supervisor)->test(AuditConfigure::class, ['cabinet' => $audit->cabinet_key])
            ->assertSee(__('audit.fields.workflow'))
            ->set('workflow', 'design_office')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(AuditWorkflow::DesignOffice, $audit->refresh()->workflow);

        $this->switchWorkflowModule(false);
        Livewire::actingAs($this->supervisor)->test(AuditConfigure::class, ['cabinet' => $audit->cabinet_key])->assertDontSee(__('audit.fields.workflow'));
    }

    public function test_the_platform_settings_page_edits_the_workflow_rules(): void
    {
        $admin = $this->person(Role::Administrator);

        Livewire::actingAs($admin)->test(PlatformSettings::class)
            ->assertSee(__('platform_settings.design_office_workflow'))
            ->set('reviewDeadlineMinutes', 4)
            ->set('reviewAlertMinutes', 4)
            ->call('saveWorkflow')
            ->assertHasErrors('reviewAlertMinutes')
            ->set('reviewAlertMinutes', 2)
            ->set('maxOpenRequests', 3)
            ->set('exemptFolders', ['before', 'labels'])
            ->call('saveWorkflow')
            ->assertHasNoErrors();

        $settings = app(DesignOfficeWorkflowSettings::class);
        $settings->refresh();
        $this->assertSame([240, 120, 3, ['before', 'labels']], [$settings->review_deadline_seconds, $settings->review_alert_seconds, $settings->max_open_requests_per_reviewer, $settings->exempt_folders]);
    }

    public function test_only_administrators_edit_the_workflow_rules(): void
    {
        Livewire::actingAs($this->supervisor)->test(PlatformSettings::class)->assertForbidden();
    }

    public function test_exempted_folders_are_not_required_to_submit(): void
    {
        $settings = app(DesignOfficeWorkflowSettings::class);
        $settings->exempt_folders = ['labels'];
        $settings->save();
        $box = $this->box();
        app(BoxTeam::class)->claim($this->technician, $box);
        $this->fillPhotos($box, [AuditFolder::Labels]);

        $this->assertSame('submitted', $this->submit($box)->box->inspection_state->value);
    }
}
