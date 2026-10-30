<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Enums\AuditAiDraftStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\Role;
use App\Livewire\Admin\PlatformSettings;
use App\Livewire\DesignOfficeWorkflow\OccurrenceAiPage;
use App\Models\AuditAiDraft;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrencePhoto;
use App\Models\Client;
use App\Models\WorkSite;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * AI drafts of occurrences: off by default (decision 11), only on a person's request, only the occurrence's photos
 * sent, the key never leaving the server, the text in the client's language (French by default — decision 10), always
 * a draft until approved, and an internal, journaled history.
 */
class OccurrenceAiDraftsTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private AuditOccurrence $occurrence;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.anthropic.key' => 'secret-test-key', 'services.anthropic.url' => 'https://anthropic.test/v1/messages']);
        Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'facts' => ['Cable entry open on the left side'], 'observations' => ['Sealant missing around the cable'], 'hypotheses' => [], 'missing' => ['Watertightness cannot be judged from the photos'],
        ])]]])]);
        $this->setUpWorkflow();

        $box = $this->box();
        $this->occurrence = AuditOccurrence::factory()->create(['audit_box_id' => $box->id, 'description' => 'Cable entry without sealant']);

        foreach ([AuditOccurrencePhotoRole::Before, AuditOccurrencePhotoRole::After] as $role) {
            $photo = $this->photo($box, AuditFolder::Entries);
            Storage::disk('local')->put($photo->file->path, 'jpeg-'.$photo->id);
            AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $this->occurrence->id, 'audit_photo_id' => $photo->id, 'role' => $role, 'linked_at' => now()]);
        }

        $this->photo($box, AuditFolder::Closed);
    }

    private function enable(): void
    {
        $settings = app(DesignOfficeWorkflowSettings::class);
        $settings->ai_drafts_enabled = true;
        $settings->save();
    }

    public function test_the_ai_is_off_by_default_and_nothing_is_sent(): void
    {
        $this->assertFalse(app(DesignOfficeWorkflowSettings::class)->ai_drafts_enabled);

        Livewire::actingAs($this->reviewer)->test(OccurrenceAiPage::class, ['occurrence' => $this->occurrence])
            ->assertSee(__('design_office_workflow.ai.disabled'))
            ->call('generate')
            ->assertHasErrors('generate');

        Http::assertNothingSent();
        $this->assertSame(0, AuditAiDraft::query()->count());
    }

    public function test_an_administrator_switches_the_ai_drafts_on_in_the_settings(): void
    {
        $admin = $this->person(Role::Administrator);

        Livewire::actingAs($admin)->test(PlatformSettings::class)->set('aiDraftsEnabled', true)->call('saveWorkflow')->assertHasNoErrors();

        $this->assertTrue(app(DesignOfficeWorkflowSettings::class)->refresh()->ai_drafts_enabled);
    }

    public function test_a_draft_is_made_from_the_occurrence_photos_only_in_the_client_language(): void
    {
        $this->enable();

        Livewire::actingAs($this->reviewer)->test(OccurrenceAiPage::class, ['occurrence' => $this->occurrence])
            ->assertSee(__('design_office_workflow.ai.scope_hint', ['count' => 2, 'language' => __('platform.languages.fr')]))
            ->call('generate')
            ->assertHasNoErrors();

        $draft = AuditAiDraft::query()->sole();
        $this->assertSame(AuditAiDraftStatus::Draft, $draft->status);
        $this->assertSame('fr', $draft->locale);
        $this->assertSame($this->occurrence->photoLinks()->orderBy('id')->pluck('audit_photo_id')->all(), $draft->photo_ids);
        $this->assertStringContainsString(__('design_office_workflow.ai.sections.facts', [], 'fr'), $draft->text);
        $this->assertStringContainsString('Cable entry open on the left side', $draft->text);

        Http::assertSent(function (Request $request): bool {
            $content = $request['messages'][0]['content'];
            $images = array_filter($content, fn (array $part) => $part['type'] === 'image');

            return count($images) === 2 && $request->hasHeader('x-api-key', 'secret-test-key') && str_contains(end($content)['text'], 'Write in French');
        });

        $this->assertSame(1, Activity::query()->where('log_name', 'design_office_workflow')->where('event', 'ai_draft_generated')->count());
        $this->assertStringNotContainsString('secret-test-key', (string) Activity::query()->where('event', 'ai_draft_generated')->value('properties'));
    }

    public function test_the_text_follows_the_language_of_the_client_of_the_audit(): void
    {
        $this->enable();
        $client = Client::factory()->create(['locale' => 'es']);
        WorkSite::query()->where('audit_id', $this->audit->id)->update(['client_id' => $client->id]);

        Livewire::actingAs($this->reviewer)->test(OccurrenceAiPage::class, ['occurrence' => $this->occurrence])->call('generate')->assertHasNoErrors();

        $draft = AuditAiDraft::query()->sole();
        $this->assertSame('es', $draft->locale);
        $this->assertStringContainsString(__('design_office_workflow.ai.sections.facts', [], 'es'), $draft->text);
    }

    public function test_a_draft_is_corrected_then_approved_and_then_never_changes(): void
    {
        $this->enable();
        $page = Livewire::actingAs($this->reviewer)->test(OccurrenceAiPage::class, ['occurrence' => $this->occurrence])->call('generate');
        $draft = AuditAiDraft::query()->sole();

        $page->set('texts.'.$draft->id, 'Corrected wording of the non-conformity.')->call('save', $draft->id)->assertHasNoErrors();
        $this->assertSame('Corrected wording of the non-conformity.', $draft->refresh()->text);
        $this->assertSame($this->reviewer->id, $draft->edited_by);

        $page->call('approve', $draft->id)->assertHasNoErrors();
        $this->assertSame(AuditAiDraftStatus::Approved, $draft->refresh()->status);
        $this->assertSame($this->reviewer->id, $draft->approved_by);

        $page->set('texts.'.$draft->id, 'Changed after the approval.')->call('save', $draft->id)->assertHasErrors('generate');
        $page->call('reject', $draft->id)->assertHasErrors('generate');
        $this->assertSame('Corrected wording of the non-conformity.', $draft->refresh()->text);

        $page->call('generate');
        $second = AuditAiDraft::query()->latest('id')->firstOrFail();
        $page->call('reject', $second->id);
        $this->assertSame(AuditAiDraftStatus::Rejected, $second->refresh()->status);
        $this->assertSame(['ai_draft_generated', 'ai_draft_edited', 'ai_draft_approved', 'ai_draft_generated', 'ai_draft_rejected'], Activity::query()->where('log_name', 'design_office_workflow')->where('event', 'like', 'ai_draft_%')->orderBy('id')->pluck('event')->all());
    }

    public function test_only_the_design_office_and_supervisors_use_the_ai_drafts(): void
    {
        $this->enable();

        Livewire::actingAs($this->technician)->test(OccurrenceAiPage::class, ['occurrence' => $this->occurrence])->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('design-office.occurrences.ai', $this->occurrence))->assertOk();

        $standard = $this->plannedAudit([$this->technician], designOffice: false);
        $foreign = AuditOccurrence::factory()->create(['audit_box_id' => $this->box($standard)->id]);
        Livewire::actingAs($this->reviewer)->test(OccurrenceAiPage::class, ['occurrence' => $foreign])->assertNotFound();

        $draft = AuditAiDraft::query()->create(['audit_occurrence_id' => $foreign->id, 'photo_ids' => [], 'locale' => 'fr', 'text' => 'Some draft of another occurrence', 'status' => AuditAiDraftStatus::Draft]);
        Livewire::actingAs($this->reviewer)->test(OccurrenceAiPage::class, ['occurrence' => $this->occurrence])->call('approve', $draft->id)->assertNotFound();
        $this->assertSame(AuditAiDraftStatus::Draft, $draft->refresh()->status);
    }
}
