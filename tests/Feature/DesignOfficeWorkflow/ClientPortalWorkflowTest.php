<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\ClientClaims;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditAiDraftStatus;
use App\Enums\AuditClientClaimKind;
use App\Enums\AuditClientClaimState;
use App\Enums\AuditConformity;
use App\Enums\AuditDossierFileRole;
use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditInspectionState;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Enums\Module;
use App\Livewire\ClientPortal\PortalPage;
use App\Livewire\DesignOfficeWorkflow\ClientClaimsPage;
use App\Models\Audit;
use App\Models\AuditAiDraft;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditClientClaim;
use App\Models\AuditDossierFile;
use App\Models\AuditDossierVersion;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditPhoto;
use App\Models\AuditPhotoAnnotation;
use App\Models\AuditReserve;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\StoredFile;
use App\Settings\ModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * The client portal on a design office audit: photos only of accepted boxes (decision 8), the official dossier only
 * once published, reserves and their disputes (the design office answers the first, a supervisor the second), new
 * anomalies on a closed audit, the notice of a new dossier version, and nothing internal ever reaching the client.
 */
class ClientPortalWorkflowTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    /** Texts only the office and the crews may ever read. */
    private const array INTERNAL = ['Internal chat about the lid', 'Blocking control on the tray', 'Unapproved draft wording', 'Annotation label'];

    private Client $client;

    private ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpWorkflow();
        $this->enableClientPortal();
        $this->client = Client::factory()->create(['code' => 'ACME']);
        $this->account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'username' => 'jane', 'has_all_audits' => false]);
        $this->account->audits()->sync([$this->audit->id]);
    }

    private function enableClientPortal(): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_unique([...$settings->enabled, Module::ClientPortal->value]));
        $settings->save();
    }

    private function signInClient(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
    }

    private function storedPhoto(AuditBox $box, AuditFolder $folder = AuditFolder::Closed): AuditPhoto
    {
        $photo = $this->photo($box, $folder);
        Storage::disk('local')->put($photo->file->path, 'jpeg-'.$photo->id);

        return $photo;
    }

    private function zipFile(string $content): StoredFile
    {
        $path = 'files/'.hash('sha256', $content).'.zip';
        Storage::disk('local')->put($path, $content);

        return StoredFile::query()->create(['sha256' => hash('sha256', $content), 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/zip', 'extension' => 'zip', 'size_bytes' => strlen($content)]);
    }

    private function reserve(AuditBox $box, ?AuditOccurrence $occurrence = null): AuditReserve
    {
        return AuditReserve::factory()->create(['audit_box_id' => $box->id, 'audit_occurrence_id' => $occurrence?->id, 'reason' => 'Lid seal to be checked at the next visit', 'accepted_by' => $this->supervisor->id]);
    }

    public function test_on_a_design_office_audit_the_client_sees_photos_only_of_boxes_the_design_office_accepted(): void
    {
        $pending = $this->box(attributes: ['name' => 'BPE-PENDING']);
        $accepted = $this->box(attributes: ['name' => 'BPE-OK', 'inspection_state' => AuditInspectionState::Validated]);
        $hidden = $this->storedPhoto($pending);
        $shown = $this->storedPhoto($accepted);
        $this->signInClient();

        $this->get(route('portal.photos', ['acme', $hidden->id, 'full']))->assertNotFound();
        $this->get(route('portal.photos', ['acme', $shown->id, 'full']))->assertOk();
        $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$pending->id]])->assertNotFound();
        $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$accepted->id]])->assertOk();

        $drawer = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->set('tab', 'boxes')->call('openBox', $pending->id);
        $drawer->assertSee(__('client_portal.drawer.photos_after_validation'))->assertDontSee(route('portal.photos', ['acme', $hidden->id, 'thumb']));
    }

    /**
     * A published version with one report file in its ZIP.
     */
    private function publishedVersion(Audit $audit, int $number, string $content): AuditDossierVersion
    {
        $version = AuditDossierVersion::factory()->create(['audit_id' => $audit->id, 'version_number' => $number, 'kind' => $number > 1 ? AuditDossierKind::Update : AuditDossierKind::Initial, 'status' => AuditDossierStatus::Generating]);
        AuditDossierFile::query()->create(['audit_dossier_version_id' => $version->id, 'path' => 'Report.pdf', 'role' => AuditDossierFileRole::Report, 'stored_file_id' => $this->zipFile($content)->id]);
        $version->forceFill(['status' => AuditDossierStatus::Published, 'published_at' => now()])->save();

        return $version;
    }

    public function test_the_official_dossier_is_offered_only_once_published_and_any_version_can_be_downloaded(): void
    {
        $first = $this->publishedVersion($this->audit, 1, 'report-1');
        $second = AuditDossierVersion::factory()->create(['audit_id' => $this->audit->id, 'version_number' => 2, 'kind' => AuditDossierKind::Update, 'status' => AuditDossierStatus::Generating]);
        $other = $this->plannedAudit([$this->technician]);
        $this->box($other);
        $foreign = $this->publishedVersion($other, 1, 'foreign-report');
        $this->box();
        $this->signInClient();

        $zip = $this->get(route('portal.dossier', ['acme', $first->id, 'full']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Report.pdf', $zip);
        $this->get(route('portal.dossier', ['acme', $first->id, 'changes']))->assertNotFound();
        $this->get(route('portal.dossier', ['acme', $second->id, 'full']))->assertNotFound();
        $this->get(route('portal.dossier', ['acme', $foreign->id, 'full']))->assertNotFound();

        Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'dossier')
            ->assertSee(__('client_portal.design_office.version', ['version' => 1]))
            ->assertDontSee(__('client_portal.design_office.version', ['version' => 2]))
            ->assertSee(__('client_portal.design_office.event_dossier', ['version' => 1, 'cabinet' => $this->audit->cabinet_key]))
            ->assertDontSee(__('client_portal.design_office.event_dossier', ['version' => 1, 'cabinet' => $other->cabinet_key]));
    }

    public function test_the_dossier_tab_exists_only_on_design_office_audits(): void
    {
        $standard = $this->plannedAudit([$this->technician], designOffice: false);
        $this->box($standard);
        $this->account->audits()->sync([$standard->id]);
        $this->signInClient();

        Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'dossier')
            ->assertSet('tab', 'dashboard')
            ->assertDontSee(__('client_portal.tabs.dossier'));
    }

    public function test_a_reserve_can_be_disputed_twice_the_design_office_answers_the_first_and_a_supervisor_the_second(): void
    {
        $box = $this->box(attributes: ['inspection_state' => AuditInspectionState::Validated]);
        $reserve = $this->reserve($box);
        $this->signInClient();

        Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'dossier')
            ->assertSee('Lid seal to be checked at the next visit')
            ->call('startDispute', $reserve->id)->set('disputeText', 'short')->call('sendDispute')->assertHasErrors('disputeText')
            ->set('disputeText', 'The seal was replaced on the day of the audit.')->call('sendDispute')->assertHasNoErrors();

        $first = AuditClientClaim::query()->sole();
        $this->assertSame(AuditClientClaimState::Open, $first->state);
        $this->assertSame(1, $first->round);
        $this->assertSame(AuditClientClaimKind::ReserveDispute, $first->kind);

        try {
            app(ClientClaims::class)->dispute($this->account, $reserve, 'A second dispute while the first is open.');
            $this->fail('A second dispute was accepted while the first one waits.');
        } catch (WorkflowException $exception) {
            $this->assertSame('claim_pending', $exception->reason);
        }

        Livewire::actingAs($this->reviewer)->test(ClientClaimsPage::class)
            ->call('startDecision', $first->id)->set('verdict', 'refuse')->set('reason', '')->call('decide')->assertHasErrors('verdict')
            ->set('reason', 'The photo shows the old seal.')->call('decide')->assertHasNoErrors();
        $this->assertSame(AuditClientClaimState::Refused, $first->refresh()->state);
        $this->assertSame($this->reviewer->id, $first->decided_by);

        $second = app(ClientClaims::class)->dispute($this->account, $reserve, 'Here is the delivery note of the new seal.');
        $this->assertSame(AuditClientClaimState::Escalated, $second->state);
        $this->assertSame(2, $second->round);

        Livewire::actingAs($this->reviewer)->test(ClientClaimsPage::class)->call('startDecision', $second->id)->assertForbidden();
        Livewire::actingAs($this->supervisor)->test(ClientClaimsPage::class)
            ->call('startDecision', $second->id)->set('verdict', 'refuse')->set('reason', 'Seal still to be checked on site.')->call('decide')->assertHasNoErrors();

        try {
            app(ClientClaims::class)->dispute($this->account, $reserve, 'A third dispute of the same reserve.');
            $this->fail('A third dispute was accepted.');
        } catch (WorkflowException $exception) {
            $this->assertSame('dispute_limit', $exception->reason);
        }

        $this->assertSame(['client_reserve_disputed', 'client_claim_decided', 'client_reserve_disputed', 'client_claim_decided'], Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('subject_type', (new AuditClientClaim)->getMorphClass())->orderBy('id')->pluck('event')->all());
    }

    public function test_an_accepted_dispute_ends_the_disputes_of_that_reserve(): void
    {
        $reserve = $this->reserve($this->box(attributes: ['inspection_state' => AuditInspectionState::Validated]));
        $claim = app(ClientClaims::class)->dispute($this->account, $reserve, 'The seal was replaced on the day of the audit.');
        app(ClientClaims::class)->decide($this->reviewer, $claim, true, null);

        $this->expectExceptionObject(new WorkflowException('dispute_accepted', 409));
        app(ClientClaims::class)->dispute($this->account, $reserve, 'Another dispute after the acceptance.');
    }

    public function test_a_new_anomaly_is_declared_only_on_a_closed_audit_and_once_confirmed_becomes_an_occurrence_to_repair(): void
    {
        $box = $this->box(attributes: ['name' => 'BPE-9', 'inspection_state' => AuditInspectionState::Validated, 'conformity' => AuditConformity::Conforming]);
        $this->signInClient();

        Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'dossier')
            ->set('occurrenceBox', (string) $box->id)->set('occurrenceText', 'Cable entry not sealed on the left side.')->call('declareOccurrence')
            ->assertHasErrors('occurrenceText');
        $this->assertSame(0, AuditClientClaim::query()->count());

        $this->audit->forceFill(['closed_at' => now(), 'closed_by' => $this->supervisor->id])->save();

        Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'dossier')
            ->set('occurrenceBox', (string) $box->id)->set('occurrenceText', 'Cable entry not sealed on the left side.')->call('declareOccurrence')
            ->assertHasNoErrors();
        $claim = AuditClientClaim::query()->sole();
        $this->assertSame(AuditClientClaimKind::NewOccurrence, $claim->kind);

        $type = AuditAnomalyType::query()->where('code', 'cable_entry_sealant')->firstOrFail();
        Livewire::actingAs($this->reviewer)->test(ClientClaimsPage::class)
            ->call('startDecision', $claim->id)->set('verdict', 'accept')->call('decide')->assertHasErrors('verdict')
            ->set('anomalyType', (string) $type->id)->call('decide')->assertHasNoErrors();

        $occurrence = AuditOccurrence::query()->sole();
        $this->assertSame(AuditOccurrenceSource::Client, $occurrence->source);
        $this->assertSame(AuditOccurrenceState::ToRepair, $occurrence->state);
        $this->assertSame('OC-'.$this->audit->cabinet_key.'-0001', $occurrence->reference);
        $this->assertSame($occurrence->id, $claim->refresh()->audit_occurrence_id);
        $this->assertSame(AuditConformity::NonConforming, $box->refresh()->conformity);
        $this->assertTrue($this->audit->refresh()->isClosed(), 'Confirming a client anomaly never reopens the audit.');
    }

    public function test_only_the_design_office_and_supervisors_reach_the_client_claims(): void
    {
        Livewire::actingAs($this->technician)->test(ClientClaimsPage::class)->assertForbidden();
        $this->actingAs($this->reviewer)->get(route('design-office.claims'))->assertOk();
        $this->actingAs($this->supervisor)->get(route('design-office.claims'))->assertOk();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.claims'))->assertNotFound();
    }

    public function test_nothing_internal_reaches_the_client_in_the_portal_or_its_files(): void
    {
        $box = $this->box(attributes: ['name' => 'BPE-RES', 'inspection_state' => AuditInspectionState::Validated]);
        $pending = $this->box(attributes: ['name' => 'BPE-WAIT']);
        $photo = $this->storedPhoto($box);
        $unvalidated = $this->storedPhoto($pending);
        $occurrence = AuditOccurrence::factory()->create(['audit_box_id' => $box->id, 'state' => AuditOccurrenceState::ReserveAccepted]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_photo_id' => $photo->id, 'role' => AuditOccurrencePhotoRole::Before, 'linked_at' => now()]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_photo_id' => $unvalidated->id, 'role' => AuditOccurrencePhotoRole::Other, 'linked_at' => now()]);
        $this->reserve($box, $occurrence);
        AuditBoxMessage::factory()->create(['audit_box_id' => $box->id, 'author_id' => $this->reviewer->id, 'body' => 'Internal chat about the lid']);
        AuditBoxMessage::factory()->create(['audit_box_id' => $box->id, 'author_id' => $this->reviewer->id, 'body' => 'Blocking control on the tray', 'is_blocking' => true]);
        AuditPhotoAnnotation::query()->create(['audit_photo_id' => $photo->id, 'author_id' => $this->reviewer->id, 'shapes' => [['type' => 'text', 'text' => 'Annotation label']]]);
        AuditAiDraft::query()->create(['audit_occurrence_id' => $occurrence->id, 'photo_ids' => [$photo->id], 'locale' => 'fr', 'text' => 'Unapproved draft wording', 'status' => AuditAiDraftStatus::Draft]);
        AuditAiDraft::query()->create(['audit_occurrence_id' => $occurrence->id, 'photo_ids' => [$photo->id], 'locale' => 'fr', 'text' => 'Approved published wording', 'status' => AuditAiDraftStatus::Approved, 'approved_by' => $this->reviewer->id, 'approved_at' => now()]);
        $this->signInClient();

        foreach (['dashboard', 'boxes', 'notes', 'dossier'] as $tab) {
            $html = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', $tab)->call('openBox', $box->id)->html();
            $this->assertNothingInternal($html, "the {$tab} tab");
            $this->assertStringNotContainsString(route('portal.photos', ['acme', $unvalidated->id, 'thumb']), $html, "an unvalidated photo reached the {$tab} tab");
        }

        $dossier = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'dossier')->html();
        $this->assertStringContainsString('Approved published wording', $dossier);
        $this->assertStringContainsString(route('portal.photos', ['acme', $photo->id, 'thumb']), $dossier);

        $zip = $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$box->id]])->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $zip);
        $archive = new ZipArchive;
        $archive->open($path);

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $this->assertNothingInternal((string) $archive->getNameIndex($index).(string) $archive->getFromIndex($index), 'the box ZIP');
        }

        $archive->close();
        unlink($path);

        $this->get(route('portal.photos', ['acme', $unvalidated->id, 'full']))->assertNotFound();
        $this->get(route('design-office.claims'))->assertRedirect();
        $this->get(route('design-office.occurrences.ai', $occurrence))->assertRedirect();
    }

    private function assertNothingInternal(string $content, string $where): void
    {
        foreach (self::INTERNAL as $text) {
            $this->assertStringNotContainsString($text, $content, "« {$text} » reached the client in {$where}");
        }
    }
}
