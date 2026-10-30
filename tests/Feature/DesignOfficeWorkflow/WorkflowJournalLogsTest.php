<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\Role;
use App\Livewire\Admin\LogIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * The « Logs » page shows the design office workflow journal read only, with a check of its hash chain.
 */
class WorkflowJournalLogsTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
        $this->admin = $this->person(Role::Administrator);
    }

    public function test_the_workflow_journal_is_listed_read_only_with_its_chain_status(): void
    {
        $box = $this->readyBox();
        $this->submit($box);

        Livewire::actingAs($this->admin)->test(LogIndex::class)
            ->assertSee(__('logs.types.design_office_workflow'))
            ->set('type', WorkflowJournal::LOG_NAME)
            ->assertSee(__('logs.workflow_events.box_claimed'))
            ->assertSee(__('logs.workflow_events.submission_received'))
            ->assertSee($this->technician->name)
            ->assertSee(__('logs.workflow.not_checked'))
            ->assertDontSee(__('logs.restore'))
            ->call('verifyChain')
            ->assertSet('chain.intact', true);

        $id = DB::table('activity_log')->where('log_name', WorkflowJournal::LOG_NAME)->where('event', 'box_claimed')->value('id');
        DB::table('activity_log')->where('id', $id)->update(['description' => 'tampered']);

        Livewire::actingAs($this->admin)->test(LogIndex::class)->set('type', WorkflowJournal::LOG_NAME)->call('verifyChain')
            ->assertSet('chain.intact', false)
            ->assertSet('chain.broken_at', $id)
            ->assertSee(__('logs.workflow.broken', ['id' => $id]));
    }

    public function test_the_journal_is_offered_only_while_the_workflow_module_is_on_and_to_administrators(): void
    {
        $this->switchWorkflowModule(false);

        Livewire::actingAs($this->admin)->test(LogIndex::class)
            ->assertDontSee(__('logs.types.design_office_workflow'))
            ->set('type', WorkflowJournal::LOG_NAME)
            ->assertDontSee(__('logs.workflow.verify'));

        Livewire::actingAs($this->supervisor)->test(LogIndex::class)->assertForbidden();
    }
}
