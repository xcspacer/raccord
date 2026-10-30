<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Audit\ArchiveAudit;
use App\Audit\AuditOperationException;
use App\Audit\AuditSnapshot;
use App\DesignOfficeWorkflow\CloseAudit;
use App\Enums\AuditFolder;
use App\Enums\AuditPresenceState;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Approved decision (3) of spec 13 §9: on an audit of the design office workflow a manager corrects the data, but
 * never what a decision stands on (photos of an accepted submission, a box already submitted, a box being decided),
 * and once the audit is closed nothing changes any more through the regular audit actions (« Modify » mode, moving,
 * deleting photos, recheck, archive) until a formal reopening. A standard audit keeps the legacy behaviour.
 */
class AdminCorrectionsTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpWorkflow();
        $this->administrator = $this->person(Role::Administrator);
        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function send(User $user, array $operation, ?Audit $audit = null): TestResponse
    {
        return $this->actingAs($user)->postJson(route('audits.operations', ['cabinet' => ($audit ?? $this->audit)->cabinet_key]), [
            'op' => $operation + ['operation_id' => 'o_'.bin2hex(random_bytes(5))],
        ]);
    }

    private function validatedBox(): AuditBox
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'validate', reason: null);

        return $box->refresh();
    }

    private function close(): void
    {
        $this->audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();
        app(CloseAudit::class)->handle($this->supervisor, $this->audit->refresh(), true);
    }

    public function test_photos_a_decision_stands_on_are_neither_deleted_nor_moved(): void
    {
        $box = $this->validatedBox();
        $evidence = $box->photos()->where('folder', AuditFolder::Labels->value)->firstOrFail();
        $later = $this->photo($box, AuditFolder::Labels);

        $this->send($this->administrator, ['op' => 'photo_del', 'photo' => $evidence->id])->assertStatus(409)->assertJsonPath('error', 'photo_is_evidence');
        $this->send($this->administrator, ['op' => 'photo_move', 'box' => $box->name, 'folder' => AuditFolder::Cassettes->value, 'photos' => [$evidence->id]])->assertStatus(409)->assertJsonPath('error', 'photo_is_evidence');
        $this->assertSame(AuditFolder::Labels->value, $evidence->refresh()->folder);

        $this->send($this->administrator, ['op' => 'photo_move', 'box' => $box->name, 'folder' => AuditFolder::Cassettes->value, 'photos' => [$later->id]])->assertOk();
        $this->send($this->administrator, ['op' => 'photo_del', 'photo' => $later->id])->assertOk();
        $this->assertNull(AuditPhoto::query()->find($later->id));
    }

    public function test_the_photos_of_a_refused_submission_can_still_be_corrected(): void
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'refuse', targets: ['retake the labels']);
        $photo = $box->photos()->firstOrFail();

        $this->send($this->administrator, ['op' => 'photo_del', 'photo' => $photo->id])->assertOk();
    }

    public function test_the_photos_of_a_box_being_decided_stay_as_sent(): void
    {
        $box = $this->readyBox();
        $this->submit($box);
        $photo = $box->photos()->firstOrFail();

        $this->send($this->administrator, ['op' => 'photo_del', 'photo' => $photo->id])->assertStatus(409)->assertJsonPath('error', 'box_under_review');
        $this->send($this->technician, ['op' => 'photo_del', 'photo' => $photo->id])->assertStatus(409)->assertJsonPath('error', 'box_under_review');
    }

    public function test_a_submitted_box_is_never_removed_but_an_untouched_one_is(): void
    {
        $submitted = $this->validatedBox();
        $untouched = $this->box();

        $this->send($this->administrator, ['op' => 'box_admin', 'box' => $submitted->name, 'removed' => true])->assertStatus(409)->assertJsonPath('error', 'box_submitted');
        $this->send($this->administrator, ['op' => 'box_admin', 'box' => $submitted->name, 'address' => '12 corrected street'])->assertOk();
        $this->send($this->administrator, ['op' => 'box_admin', 'box' => $untouched->name, 'removed' => true])->assertOk();

        $this->assertNull($submitted->refresh()->removed_at);
        $this->assertSame('12 corrected street', $submitted->address);
        $this->assertNotNull($untouched->refresh()->removed_at);
    }

    public function test_a_closed_audit_refuses_every_change_until_a_formal_reopening(): void
    {
        $box = $this->validatedBox();
        $later = $this->photo($box, AuditFolder::Labels);
        $this->close();

        foreach ([
            ['op' => 'box_note', 'box' => $box->name, 'key' => 'route', 'text' => 'corrected after delivery'],
            ['op' => 'box_admin', 'box' => $box->name, 'address' => '12 corrected street'],
            ['op' => 'photo_del', 'photo' => $later->id],
            ['op' => 'photo_move', 'box' => $box->name, 'folder' => AuditFolder::Cassettes->value, 'photos' => [$later->id]],
            ['op' => 'cp_add', 'name' => 'Extra point'],
            ['op' => 'setup', 'sro' => 'SRO-2'],
        ] as $operation) {
            $this->send($this->administrator, $operation)->assertStatus(409)->assertJsonPath('error', 'design_office_closed');
        }

        $this->send($this->technician, ['op' => 'presence', 'box' => $box->name, 'state' => AuditPresenceState::OnBox->value])->assertOk();
        $this->assertNotNull(AuditPhoto::query()->find($later->id));
        $this->assertFalse($this->administrator->can('recheck', $this->audit->refresh()));

        try {
            app(ArchiveAudit::class)->handle($this->administrator, $this->audit);
            $this->fail('A closed audit must not be archived.');
        } catch (AuditOperationException $exception) {
            $this->assertSame('design_office_closed', $exception->reason);
        }

        $this->assertNotNull(app(AuditSnapshot::class)->for($this->audit, $this->administrator)['audit']['closed']);
    }

    public function test_a_standard_audit_keeps_the_managers_free_hand(): void
    {
        $audit = $this->plannedAudit([$this->technician], designOffice: false);
        $box = $this->box($audit);
        $photo = $this->photo($box, AuditFolder::Labels);
        $audit->forceFill(['closed_at' => now()])->save();

        $this->send($this->administrator, ['op' => 'photo_del', 'photo' => $photo->id], $audit)->assertOk();
        $this->send($this->administrator, ['op' => 'box_admin', 'box' => $box->name, 'removed' => true], $audit)->assertOk();
        $this->assertTrue($this->administrator->can('recheck', $audit));
        $this->assertNull(app(AuditSnapshot::class)->for($audit, $this->administrator)['audit']['closed']);
    }
}
