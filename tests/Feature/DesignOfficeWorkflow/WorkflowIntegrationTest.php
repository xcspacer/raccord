<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Audit\AuditScreenConfig;
use App\ClientPortal\PortalOverview;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\Pauses;
use App\DesignOfficeWorkflow\Reserves;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditFolder;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditReserveProposalState;
use App\Livewire\DesignOfficeWorkflow\ClosurePage;
use App\Livewire\DesignOfficeWorkflow\DeclareOccurrenceForm;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditReserveProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * The pieces that tie the module together: what the box screen of the phone reads (occurrences, pause, client repair,
 * translated refusals), the supervisor declaring an occurrence from the desktop with photos already received, the
 * refusal of a reserve proposal, the conformity lost when an occurrence is confirmed on a conforming box, and the
 * client portal's « to redo » derived from the occurrences to repair.
 */
class WorkflowIntegrationTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    private function validatedBox(): AuditBox
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'validate', reason: null);

        return $box->refresh();
    }

    private function toRepair(AuditBox $box): AuditOccurrence
    {
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => AuditAnomalyNature::Mechanical, 'state' => AuditOccurrenceState::ToRepair,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Mechanical)->value('id'),
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now()]);

        return $occurrence;
    }

    public function test_the_box_status_carries_the_occurrences_the_open_pause_and_the_client_repair_flag(): void
    {
        $box = $this->readyBox();
        $type = AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Optical)->value('id');
        app(OccurrenceLifecycle::class)->create($this->technician, $box, (int) $type, 'fibre bent in the tray', [$box->photos()->value('id')]);
        app(Pauses::class)->start($this->technician, $box, null);

        $this->actingAs($this->technician)->getJson(route('design-office.boxes.show', $box))->assertOk()
            ->assertJsonCount(1, 'occurrences.occurrences')
            ->assertJsonPath('occurrences.occurrences.0.description', 'fibre bent in the tray')
            ->assertJsonPath('current_pause.is_here', true)
            ->assertJsonPath('client_repair', false)
            ->assertJsonPath('position_proposal', null);
    }

    public function test_the_phone_gets_the_workflow_refusals_translated_and_the_urls_of_its_actions(): void
    {
        $config = app(AuditScreenConfig::class)->for($this->audit, $this->technician);

        $this->assertSame(__('design_office_workflow.errors.not_in_team'), $config['errors']['not_in_team']);
        $this->assertSame(__('audit.errors.invalid'), $config['errors']['invalid']);
        $this->assertStringContainsString('__ID__', $config['designOffice']['urls']['occurrenceCorrect']);
        $this->assertStringContainsString((string) $this->audit->id, $config['designOffice']['urls']['synopticCorrection']);
    }

    public function test_an_occurrence_confirmed_on_a_conforming_box_makes_it_not_assessed_again(): void
    {
        $box = $this->validatedBox();
        $this->assertSame(AuditConformity::Conforming, $box->conformity);

        $type = AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Optical)->value('id');
        app(OccurrenceLifecycle::class)->create($this->supervisor, $box, (int) $type, 'connector damaged', [$box->photos()->value('id')]);

        $this->assertSame(AuditConformity::NotAssessed, $box->refresh()->conformity);
    }

    public function test_a_supervisor_declares_an_occurrence_from_the_desktop_with_photos_already_received(): void
    {
        $box = $this->readyBox();
        $photo = $this->photo($box, AuditFolder::Open);
        $other = $this->photo($this->box(), AuditFolder::Open);
        $type = AuditAnomalyType::query()->where('is_active', true)->value('id');
        $form = fn () => Livewire::actingAs($this->supervisor)->test(DeclareOccurrenceForm::class)->call('show')
            ->set('auditId', $this->audit->id)->set('boxId', $box->id)->set('typeId', (string) $type)->set('description', 'label missing on the cable');

        $form()->set('photoIds', [$other->id])->call('save')->assertHasErrors('form');
        $this->assertSame(0, AuditOccurrence::query()->count());

        $form()->set('photoIds', [$photo->id])->call('save')->assertHasNoErrors()->assertRedirect();
        $occurrence = AuditOccurrence::query()->sole();
        $this->assertSame($box->id, $occurrence->audit_box_id);
        $this->assertSame([$photo->id], $occurrence->photoLinks()->pluck('audit_photo_id')->all());
    }

    public function test_the_page_of_an_occurrence_offers_to_declare_another_one_on_the_same_box(): void
    {
        $box = $this->readyBox();
        $occurrence = $this->toRepair($box);

        $this->actingAs($this->supervisor)->get(route('design-office.occurrences.show', $occurrence))->assertOk()
            ->assertSee(__('design_office_workflow.declare.button'));
        $this->actingAs($this->reviewer)->get(route('design-office.occurrences.show', $occurrence))->assertOk()
            ->assertDontSee(__('design_office_workflow.declare.button'));

        Livewire::actingAs($this->supervisor)->test(DeclareOccurrenceForm::class, ['presetAuditId' => $this->audit->id, 'presetBoxId' => $box->id])
            ->call('show')->assertSet('auditId', $this->audit->id)->assertSet('boxId', $box->id);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->supervisor)->test(DeclareOccurrenceForm::class, ['presetAuditId' => $this->audit->id, 'presetBoxId' => $box->id])->set('presetBoxId', 0);
    }

    public function test_only_supervisors_declare_from_the_desktop(): void
    {
        $this->readyBox();

        Livewire::actingAs($this->reviewer)->test(DeclareOccurrenceForm::class)->call('show')->assertForbidden();
        Livewire::actingAs($this->technician)->test(DeclareOccurrenceForm::class)->call('show')->assertForbidden();
    }

    public function test_a_supervisor_refuses_a_reserve_proposal_with_a_reason_shown_to_the_proposer(): void
    {
        $occurrence = $this->toRepair($this->validatedBox());
        app(Reserves::class)->propose($this->reviewer, $occurrence, 'the chamber is flooded every winter');
        $page = fn ($user) => Livewire::actingAs($user)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key]);

        $page($this->reviewer)->call('openDialog', 'refuse', $occurrence->id)->set('reason', 'repair it first')->call('refuse')->assertForbidden();
        $page($this->supervisor)->call('openDialog', 'refuse', $occurrence->id)->set('reason', 'no')->call('refuse')->assertHasErrors('dialog');
        $page($this->supervisor)->call('openDialog', 'refuse', $occurrence->id)->set('reason', 'repair it first, a reserve is not justified')->call('refuse')->assertHasNoErrors();

        $proposal = AuditReserveProposal::query()->sole();
        $this->assertSame(AuditReserveProposalState::Rejected, $proposal->state);
        $this->assertSame($this->supervisor->id, $proposal->decided_by);
        $this->assertSame(AuditOccurrenceState::ToRepair, $occurrence->refresh()->state, 'the anomaly stays open');
        $this->assertTrue(Activity::query()->where('log_name', 'design_office_workflow')->where('event', 'reserve_refused')->exists());
        $page($this->reviewer)->assertSee('repair it first, a reserve is not justified');
    }

    public function test_the_client_portal_counts_a_box_with_an_occurrence_to_repair_as_to_redo(): void
    {
        $box = $this->validatedBox();
        $overview = app(PortalOverview::class);
        $this->assertNotSame('partial', $overview->status($box));

        $this->toRepair($box);

        $this->assertSame('partial', app(PortalOverview::class)->status($box->refresh()));
    }
}
