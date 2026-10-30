<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\OpenAudit;
use App\ClientPortal\ClientRemarks;
use App\Enums\ClientRemarkStatus;
use App\Enums\ClientRemarkTarget;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Livewire\Audit\AuditIndex;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Livewire\ClientPortal\PortalPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientRemark;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ClientNotesTest extends TestCase
{
    use RefreshDatabase;

    private ClientRemarks $remarks;

    private ClientAccount $account;

    private Audit $audit;

    private AuditBox $box;

    private User $office;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->seed(RoleSeeder::class);
        $this->remarks = app(ClientRemarks::class);
        $this->office = User::factory()->withRole(Role::DesignOffice)->create();
        $agency = Agency::factory()->create();
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'all_agencies' => true]);
        $this->audit = app(OpenAudit::class)->handle($this->office, 'PM1');
        $this->box = $this->audit->boxes()->create(['name' => 'BPE-1', 'kind' => 'bpe', 'source' => 'manager']);
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM1', 'lead_technician_id' => $this->technician->id]);
        $client = Client::factory()->create(['code' => 'ACME', 'name' => 'Acme']);
        $this->account = ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane', 'name' => 'Jane Doe']);
    }

    private function boxNote(): ClientRemark
    {
        return $this->remarks->create($this->account, $this->audit, ClientRemarkTarget::Box, $this->box->id, 'Blurry label photo');
    }

    public function test_a_note_needs_some_text_and_a_target_of_the_audit(): void
    {
        $other = app(OpenAudit::class)->handle($this->office, 'PM2')->boxes()->create(['name' => 'BPE-9', 'kind' => 'bpe', 'source' => 'manager']);

        try {
            $this->remarks->create($this->account, $this->audit, ClientRemarkTarget::Box, $other->id, 'Some text');
            $this->fail('A box of another audit was accepted.');
        } catch (NotFoundHttpException) {
            $this->assertSame(0, ClientRemark::query()->count());
        }

        $this->expectException(ValidationException::class);
        $this->remarks->create($this->account, $this->audit, ClientRemarkTarget::Box, $this->box->id, " \x07a ");
    }

    public function test_a_note_reopened_or_answered_by_the_client_waits_again_to_be_taken_into_account(): void
    {
        $note = $this->boxNote();
        $revision = $this->audit->refresh()->revision;

        $this->remarks->acknowledge($this->technician, $note);
        $this->remarks->acknowledge($this->office, $note->refresh());
        $this->assertSame($this->technician->id, $note->refresh()->acknowledged_by);
        $this->assertGreaterThan($revision, $this->audit->refresh()->revision);

        $this->remarks->staffReply($this->office, $note, 'We will redo it');
        $this->assertSame(ClientRemarkStatus::Answered, $note->refresh()->status);
        $this->assertNotNull($note->acknowledged_at);

        $this->remarks->clientReply($this->account, $note, 'Thanks');
        $this->assertSame(ClientRemarkStatus::Open, $note->refresh()->status);
        $this->assertNull($note->acknowledged_at);

        $this->remarks->acknowledge($this->technician, $note);
        $this->remarks->close($note->refresh());
        $this->assertFalse($note->refresh()->isPending());

        $this->remarks->reopen($note);
        $this->assertTrue($note->refresh()->isPending());
    }

    public function test_technicians_get_the_box_notes_of_their_audits_and_managers_every_note(): void
    {
        $this->boxNote();
        $general = $this->remarks->create($this->account, $this->audit, ClientRemarkTarget::Audit, null, 'Report by Friday');
        $outsider = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->technician->agency_id, 'all_agencies' => true]);

        $this->assertCount(1, $this->remarks->pendingFor($this->technician));
        $this->assertCount(2, $this->remarks->pendingFor($this->office));
        $this->assertCount(0, $this->remarks->pendingFor($outsider));

        $this->actingAs($this->technician)->postJson(route('client-notes.acknowledge', $general))->assertForbidden();
        $this->actingAs($outsider)->postJson(route('client-notes.acknowledge', $general))->assertNotFound();
        $this->actingAs($this->office)->postJson(route('client-notes.acknowledge', $general))->assertOk();

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['client_portal']));
        $modules->save();
        $this->assertCount(0, app(ClientRemarks::class)->pendingFor($this->office));
    }

    public function test_the_audit_list_shows_pending_notes_with_take_into_account(): void
    {
        $note = $this->boxNote();

        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'full'])
            ->assertSee('Blurry label photo')
            ->call('acknowledgeClientNote', $note->id)
            ->assertDontSee('Blurry label photo');

        $this->assertSame($this->technician->id, $note->refresh()->acknowledged_by);
    }

    public function test_the_field_screen_snapshot_carries_the_pending_notes(): void
    {
        $note = $this->boxNote();

        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM1']), [EnsureCurrentSyncProtocol::HEADER => (string) EnsureCurrentSyncProtocol::VERSION])
            ->assertOk()
            ->assertJsonPath('client_notes.0.id', $note->id)
            ->assertJsonPath('client_notes.0.box_id', $this->box->id);
    }

    public function test_the_client_writes_replies_closes_and_reopens_from_the_portal(): void
    {
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
        $this->app['auth']->forgetGuards();

        $component = Livewire::test(PortalPage::class, ['portalClient' => $this->account->client])
            ->call('openNoteDialog', $this->box->id)->set('noteText', 'x')->call('sendDialogNote')->assertHasErrors('noteText')
            ->set('noteText', 'Please redo the label photo')->call('sendDialogNote')->assertHasNoErrors()
            ->call('showTab', 'notes')->set('generalNote', 'Report by Friday')->call('sendGeneralNote')->assertHasNoErrors()
            ->assertSee('Please redo the label photo')->assertSee('Report by Friday');

        $note = ClientRemark::query()->where('target', 'box')->sole();
        $this->assertSame('Jane Doe', $note->author_name);
        $this->assertSame('Acme', $note->author_company);

        $component->call('closeRemark', $note->id);
        $this->assertTrue($note->refresh()->isClosed());
        $component->call('reopenRemark', $note->id)->call('startReply', $note->id)->set('replyText', 'Any news?')->call('sendReply')->assertHasNoErrors();
        $this->assertSame(1, $note->replies()->count());

        $foreign = app(OpenAudit::class)->handle($this->office, 'PM2');
        $this->account->update(['has_all_audits' => false]);
        $this->account->audits()->sync([$this->audit->id]);
        $hidden = ClientRemark::query()->create(['audit_id' => $foreign->id, 'target' => 'audit', 'target_label' => 'PM2', 'body' => 'x', 'author_name' => 'x', 'status' => 'open']);
        $component->call('closeRemark', $hidden->id)->assertNotFound();
    }

    public function test_the_client_is_told_when_a_note_is_taken_into_account_or_answered(): void
    {
        $note = $this->boxNote();
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
        $this->app['auth']->forgetGuards();

        $component = Livewire::test(PortalPage::class, ['portalClient' => $this->account->client]);

        $this->remarks->acknowledge($this->technician, $note);
        $this->remarks->staffReply($this->office, $note->refresh(), 'Done');

        $component->call('$refresh')
            ->assertDispatched('toast')
            ->assertSee(__('client_portal.notes.event_reply', ['company' => config('app.name'), 'label' => __('client_portal.notes.box_label', ['box' => 'BPE-1'])]), false)
            ->call('markNotificationsSeen');

        $this->assertNotNull($this->account->refresh()->notifications_seen_at);
    }

    public function test_the_office_answers_closes_and_reopens_notes_in_client_control(): void
    {
        $note = $this->boxNote();

        Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->assertSee('Blurry label photo')
            ->call('startReply', $note->id)->set('replyText', 'On it')->call('sendReply')->assertHasNoErrors()
            ->call('closeNote', $note->id)->assertDontSee('Blurry label photo')
            ->call('filterNotes', 'all')->assertSee('Blurry label photo')
            ->call('reopenNote', $note->id);

        $this->assertSame(ClientRemarkStatus::Open, $note->refresh()->status);
        $this->assertSame('On it', $note->replies()->sole()->body);

        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(ClientControlPage::class)->assertForbidden();
    }
}
