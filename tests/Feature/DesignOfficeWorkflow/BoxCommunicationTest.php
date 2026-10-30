<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditFolder;
use App\Enums\Feature;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\AuditBoxMessage;
use App\Models\AuditPhotoAnnotation;
use App\Models\AuditValidationRequest;
use App\Models\RoleFeatureOverride;
use App\Support\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * The design office workflow on the box screen of the phone (spec 13 §3.9, rules 5, 12, 16, 42, 43): the internal chat
 * of a box (prepared offline and resent with the same operation id), blocking observations and controls resolved only
 * by the design office or a supervisor, drawings over a photo that never touch the photo, what the screen needs to
 * start, and the review clock that only starts on reception of a submission queued on the phone.
 */
class BoxCommunicationTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function message(string $body, array $data = []): array
    {
        return ['operation_id' => $this->operationId(), 'body' => $body, ...$data];
    }

    public function test_the_team_writes_in_the_chat_and_a_resent_message_is_not_duplicated(): void
    {
        $box = $this->readyBox();
        $payload = $this->message('Cable entry hidden behind the bracket');

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $payload)
            ->assertCreated()->assertJsonPath('duplicate', false)
            ->assertJsonPath('status.messages.0.body', 'Cable entry hidden behind the bracket')
            ->assertJsonPath('status.messages.0.side', 'team')
            ->assertJsonPath('status.messages.0.is_blocking', false)
            ->assertJsonPath('status.blocking_messages', 0);
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $payload)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, AuditBoxMessage::query()->count());
        $this->actingAs($this->reviewer)->getJson(route('design-office.boxes.show', $box))
            ->assertOk()->assertJsonPath('messages.0.by', $this->technician->name)->assertJsonPath('messages.0.is_mine', false);
    }

    public function test_an_operation_id_of_another_box_is_refused(): void
    {
        $box = $this->readyBox();
        $other = $this->box();
        $payload = $this->message('First');

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $payload)->assertCreated();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $other), $payload)
            ->assertStatus(409)->assertJsonPath('reason', 'operation_id_reused');
    }

    public function test_only_the_design_office_and_supervisors_write_blocking_messages(): void
    {
        $box = $this->readyBox();

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Looks fine', ['kind' => 'observation']))
            ->assertForbidden()->assertJsonPath('reason', 'forbidden');
        $this->actingAs($this->supervisor)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Measure again', ['kind' => 'control_request']))
            ->assertCreated()->assertJsonPath('status.messages.0.side', 'office')->assertJsonPath('status.blocking_messages', 1);
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $this->message(''))->assertUnprocessable();
    }

    public function test_an_early_observation_on_a_received_photo_blocks_the_submission_until_the_design_office_resolves_it(): void
    {
        $box = $this->readyBox();
        $photo = $this->photo($box, AuditFolder::Labels);
        $foreign = $this->photo($this->box(), AuditFolder::Labels);

        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Label unreadable', ['kind' => 'observation', 'photo_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonPath('reason', 'photo_not_in_box');
        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Label unreadable', ['kind' => 'observation', 'photo_id' => $photo->id]))
            ->assertCreated()->assertJsonPath('status.messages.0.photo_id', $photo->id);
        $message = AuditBoxMessage::query()->sole();

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => $this->operationId()])
            ->assertStatus(409)->assertJsonPath('reason', 'blocking_messages');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Photo taken again'))->assertCreated();
        $this->actingAs($this->technician)->postJson(route('design-office.messages.resolve', $message))->assertForbidden();
        $this->assertNull($message->refresh()->resolved_at);

        $this->actingAs($this->reviewer)->postJson(route('design-office.messages.resolve', $message))
            ->assertOk()->assertJsonPath('status.blocking_messages', 0)->assertJsonPath('status.messages.0.resolved.by', $this->reviewer->name);
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => $this->operationId()])->assertCreated();
        $this->assertSame(1, Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('event', 'message_resolved')->count());
    }

    public function test_a_chat_message_is_not_resolved(): void
    {
        $box = $this->readyBox();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Hello'))->assertCreated();

        $this->actingAs($this->reviewer)->postJson(route('design-office.messages.resolve', AuditBoxMessage::query()->sole()))
            ->assertStatus(409)->assertJsonPath('reason', 'not_blocking');
    }

    public function test_messages_follow_the_workflow_access_rules(): void
    {
        $box = $this->readyBox();
        $stranger = $this->person(Role::Technician);

        $this->actingAs($stranger)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Hi'))->assertNotFound();

        $standard = $this->box($this->plannedAudit([$this->technician], designOffice: false));
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $standard), $this->message('Hi'))
            ->assertStatus(409)->assertJsonPath('reason', 'standard_workflow');

        RoleFeatureOverride::query()->create(['role_id' => RoleModel::findByName(Role::Technician->value)->id, 'feature' => Feature::DesignOfficeWorkflow, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Hi'))->assertForbidden();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.messages.store', $box), $this->message('Hi'))->assertNotFound();
        $this->assertSame(0, AuditBoxMessage::query()->count());
    }

    public function test_the_design_office_draws_over_a_photo_without_touching_it(): void
    {
        $box = $this->readyBox();
        $photo = $this->photo($box, AuditFolder::Labels);
        $before = $photo->file->only(['sha256', 'path', 'size_bytes']);
        $shapes = [
            ['type' => 'circle', 'colour' => 'red', 'x1' => 0.1, 'y1' => 0.2, 'x2' => 0.4, 'y2' => 0.5],
            ['type' => 'arrow', 'colour' => 'yellow', 'x1' => 0.9, 'y1' => 0.9, 'x2' => 0.5, 'y2' => 0.5, 'extra' => 'dropped'],
            ['type' => 'pen', 'points' => [[0.1, 0.1], [0.2, 0.25], [1.5, -1]]],
            ['type' => 'text', 'colour' => 'blue', 'x' => 0.3, 'y' => 0.3, 'text' => 'Splice here'],
        ];

        $this->actingAs($this->reviewer)->putJson(route('design-office.photos.annotations.update', $photo), ['shapes' => $shapes])
            ->assertOk()->assertJsonPath('annotations.0.by', $this->reviewer->name)->assertJsonCount(4, 'annotations.0.shapes');

        $stored = AuditPhotoAnnotation::query()->sole()->shapes;
        $this->assertArrayNotHasKey('extra', $stored[1]);
        $this->assertSame('red', $stored[2]['colour']);
        $this->assertEquals([[0.1, 0.1], [0.2, 0.25], [1, 0]], $stored[2]['points']);
        $this->assertSame($before, $photo->file->refresh()->only(['sha256', 'path', 'size_bytes']));
        $this->actingAs($this->technician)->getJson(route('design-office.boxes.show', $box))->assertJsonPath('annotated_photos', [$photo->id]);

        $this->actingAs($this->reviewer)->putJson(route('design-office.photos.annotations.update', $photo), ['shapes' => [$shapes[0]]])->assertOk();
        $this->assertCount(1, AuditPhotoAnnotation::query()->sole()->shapes);
        $this->actingAs($this->reviewer)->putJson(route('design-office.photos.annotations.update', $photo), ['shapes' => []])->assertOk()->assertJsonPath('annotations', []);
        $this->assertSame(0, AuditPhotoAnnotation::query()->count());
        $this->assertSame(2, Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('event', 'photo_annotated')->count());
    }

    public function test_malformed_drawings_are_refused(): void
    {
        $photo = $this->photo($this->readyBox(), AuditFolder::Labels);

        foreach ([
            [['type' => 'star']],
            [['type' => 'circle', 'x1' => 'left']],
            [['type' => 'pen', 'points' => [[0.1, 0.1]]]],
            [['type' => 'text', 'x' => 0.1, 'y' => 0.1, 'text' => '']],
        ] as $shapes) {
            $this->actingAs($this->reviewer)->putJson(route('design-office.photos.annotations.update', $photo), ['shapes' => $shapes])->assertUnprocessable();
        }

        $this->assertSame(0, AuditPhotoAnnotation::query()->count());
    }

    public function test_drawings_are_seen_by_the_team_and_drawn_only_by_the_design_office_and_supervisors(): void
    {
        $box = $this->readyBox();
        $photo = $this->photo($box, AuditFolder::Labels);
        $stranger = $this->person(Role::Technician);
        $shapes = ['shapes' => [['type' => 'arrow', 'x1' => 0.1, 'y1' => 0.1, 'x2' => 0.2, 'y2' => 0.2]]];

        $this->actingAs($this->technician)->putJson(route('design-office.photos.annotations.update', $photo), $shapes)->assertForbidden();
        $this->actingAs($stranger)->putJson(route('design-office.photos.annotations.update', $photo), $shapes)->assertNotFound();
        $this->actingAs($this->supervisor)->putJson(route('design-office.photos.annotations.update', $photo), $shapes)->assertOk();

        $this->actingAs($this->technician)->get(route('design-office.photos.annotations', $photo))
            ->assertOk()->assertSee('photoMarkup', false)->assertSee('&quot;canDraw&quot;:false', false);
        $this->actingAs($this->reviewer)->get(route('design-office.photos.annotations', $photo))
            ->assertOk()->assertSee('&quot;canDraw&quot;:true', false);
        $this->actingAs($stranger)->get(route('design-office.photos.annotations', $photo))->assertNotFound();

        $standardPhoto = $this->photo($this->box($this->plannedAudit([$this->technician], designOffice: false)), AuditFolder::Labels);
        $this->actingAs($this->reviewer)->putJson(route('design-office.photos.annotations.update', $standardPhoto), $shapes)
            ->assertStatus(409)->assertJsonPath('reason', 'standard_workflow');

        $this->switchWorkflowModule(false);
        $this->actingAs($this->reviewer)->get(route('design-office.photos.annotations', $photo))->assertNotFound();
    }

    public function test_the_box_screen_gets_the_workflow_only_for_whoever_may_use_it(): void
    {
        $this->box(attributes: ['name' => 'BPE-7']);
        $headers = [EnsureCurrentSyncProtocol::HEADER => (string) EnsureCurrentSyncProtocol::VERSION];

        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => $this->audit->cabinet_key]), $headers)
            ->assertOk()->assertJsonPath('audit.workflow', 'design_office');
        $page = $this->actingAs($this->technician)->get(route('audits.box', ['cabinet' => $this->audit->cabinet_key, 'name' => 'BPE-7']))->assertOk();
        $page->assertSee('box-flow', false)->assertSee('&quot;role&quot;:&quot;technician&quot;', false)->assertSee('__BOX__', false);

        RoleFeatureOverride::query()->create(['role_id' => RoleModel::findByName(Role::Technician->value)->id, 'feature' => Feature::DesignOfficeWorkflow, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->technician)->get(route('audits.box', ['cabinet' => $this->audit->cabinet_key, 'name' => 'BPE-7']))
            ->assertOk()->assertSee('&quot;designOffice&quot;:null', false);
    }

    public function test_a_submission_queued_offline_starts_the_clock_only_when_the_server_receives_it(): void
    {
        $box = $this->readyBox();
        Carbon::setTestNow('2026-10-30 14:00:00');

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), [
            'operation_id' => 'o_offline1', 'client_created_at' => '2026-10-30T11:30:00+00:00', 'queued' => true,
        ])->assertCreated()->assertJsonPath('status.request.state', 'assigned');

        $request = AuditValidationRequest::query()->sole();
        $this->assertTrue($request->queued_at->equalTo(Carbon::parse('2026-10-30 14:00:00')));
        $this->assertTrue($request->deadline_at->equalTo(Carbon::parse('2026-10-30 14:05:00')));

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit', $box), ['operation_id' => 'o_offline1'])
            ->assertOk()->assertJsonPath('duplicate', true);
    }

    public function test_the_technician_learns_the_decision_by_polling_the_box(): void
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'refuse', reason: 'Photos blurred', targets: ['Retake the label photo']);

        $this->actingAs($this->technician)->getJson(route('design-office.boxes.show', $box))
            ->assertOk()
            ->assertJsonPath('box.inspection_state', 'refused')
            ->assertJsonPath('decision.code', 'refuse')
            ->assertJsonPath('decision.targets', ['Retake the label photo'])
            ->assertJsonPath('messages.0.side', 'office');
    }
}
