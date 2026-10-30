<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\CloseAudit;
use App\DesignOfficeWorkflow\Reserves;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditReserveKind;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\DesignOfficeWorkflow\ClosurePage;
use App\Models\Audit;
use App\Models\AuditAnomalyType;
use App\Models\AuditDossierVersion;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditReserve;
use App\Models\AuditReserveProposal;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Server-side authorization of the « Closure & dossier » screen (spec 13 §2): the design office and the supervisors
 * see it and propose reserves, only a supervisor accepts or closes a reserve, closes or reopens a PM. Technicians and
 * anyone without the workflow get a 403 (module off: 404); an anomaly or reserve of another PM does not exist (404).
 */
class ClosureAuthorizationTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    private function openOccurrence(?Audit $audit = null, ?User $technician = null): AuditOccurrence
    {
        $box = $this->readyBox($technician, $audit);
        $this->decide($this->submit($box, $technician), 'validate', reason: null);
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => AuditAnomalyNature::Mechanical, 'state' => AuditOccurrenceState::ToRepair,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Mechanical)->value('id'),
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now()]);

        return $occurrence;
    }

    public function test_the_screen_is_for_the_design_office_and_the_supervisors(): void
    {
        $this->actingAs($this->technician)->get(route('design-office.closure'))->assertForbidden();
        $this->actingAs($this->reviewer)->get(route('design-office.closure'))->assertOk()->assertSee($this->audit->cabinet_key);
        $this->actingAs($this->supervisor)->get(route('design-office.closure', ['pm' => $this->audit->cabinet_key]))->assertOk()->assertSee(__('design_office_workflow.closure.checks_title'));

        RoleFeatureOverride::query()->create(['role_id' => RoleModel::findByName(Role::DesignOffice->value)->id, 'feature' => Feature::DesignOfficeWorkflow, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->reviewer)->get(route('design-office.closure'))->assertForbidden();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.closure'))->assertNotFound();
    }

    public function test_the_design_office_proposes_but_never_accepts_closes_or_reopens(): void
    {
        $occurrence = $this->openOccurrence();
        $page = Livewire::actingAs($this->reviewer)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key]);

        $page->call('openDialog', 'propose', $occurrence->id)->set('reason', 'the seal cannot be found anywhere')->call('propose')->assertHasNoErrors();
        $this->assertSame(1, AuditReserveProposal::query()->count());

        $fresh = fn () => Livewire::actingAs($this->reviewer)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key]);
        $fresh()->call('openDialog', 'accept', $occurrence->id)->set('reason', 'accepted with a follow-up visit')->call('accept')->assertForbidden();
        $fresh()->call('openDialog', 'close')->set('confirmed', true)->call('closeAudit')->assertForbidden();
        $fresh()->call('openDialog', 'reopen')->set('reason', 'label to redo on site')->call('reopen')->assertForbidden();
        $this->assertSame(AuditOccurrenceState::ToRepair, $occurrence->refresh()->state);
    }

    public function test_a_supervisor_accepts_and_closes_a_reserve_from_the_screen(): void
    {
        $occurrence = $this->openOccurrence();
        $page = Livewire::actingAs($this->supervisor)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key]);

        $page->call('openDialog', 'accept', $occurrence->id)->set('kind', AuditReserveKind::FollowUp->value)->set('reason', 'accepted with a follow-up visit')->call('accept')
            ->assertHasNoErrors()->assertSet('dialog', '');
        $reserve = AuditReserve::query()->firstOrFail();

        Livewire::actingAs($this->reviewer)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key])
            ->call('openDialog', 'close_reserve', $reserve->id)->set('reason', 'follow-up done')->call('closeReserve')->assertForbidden();
        $this->assertNull($reserve->refresh()->closed_at);

        Livewire::actingAs($this->supervisor)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key])
            ->call('openDialog', 'close_reserve', $reserve->id)->set('reason', 'follow-up done')->call('closeReserve')->assertHasNoErrors();
        $this->assertNotNull($reserve->refresh()->closed_at);
    }

    public function test_an_anomaly_or_a_reserve_of_another_pm_does_not_exist_for_the_screen(): void
    {
        $elsewhere = $this->openOccurrence($this->plannedAudit([$this->partner]), $this->partner);
        app(Reserves::class)->accept($this->supervisor, $elsewhere, AuditReserveKind::FollowUp, 'accepted with a follow-up visit');

        $fresh = fn () => Livewire::actingAs($this->supervisor)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key]);
        $fresh()->call('openDialog', 'accept', $elsewhere->id)->set('reason', 'accepted with a follow-up visit')->call('accept')->assertNotFound();
        $fresh()->call('openDialog', 'close_reserve', AuditReserve::query()->value('id'))->set('reason', 'follow-up done')->call('closeReserve')->assertNotFound();
    }

    public function test_closing_from_the_screen_needs_the_express_confirmation(): void
    {
        $this->audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'validate', reason: null);
        $page = Livewire::actingAs($this->supervisor)->test(ClosurePage::class, ['cabinet' => $this->audit->cabinet_key]);

        $page->call('openDialog', 'close')->call('closeAudit')->assertHasErrors('dialog');
        $this->assertNull($this->audit->refresh()->closed_at);

        $page->call('openDialog', 'close')->set('confirmed', true)->call('closeAudit')->assertHasNoErrors();
        $this->assertNotNull($this->audit->refresh()->closed_at);

        $page->call('openDialog', 'reopen')->set('reason', 'label to redo on site')->set('reopenBoxes', [$box->id])->call('reopen')->assertHasErrors('dialog');

        AuditDossierVersion::query()->firstOrFail()->forceFill(['status' => AuditDossierStatus::Published, 'published_at' => now()])->save();
        $page->call('openDialog', 'reopen')->set('reason', 'label to redo on site')->set('reopenBoxes', [$box->id])->call('reopen')->assertHasNoErrors();
        $this->assertNull($this->audit->refresh()->closed_at);
    }

    public function test_the_audit_page_links_to_the_screen_and_shows_the_closure(): void
    {
        $this->audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();
        $this->decide($this->submit($this->readyBox()), 'validate', reason: null);
        app(CloseAudit::class)->handle($this->supervisor, $this->audit, true);

        $this->actingAs($this->supervisor)->get(route('audits.show', ['cabinet' => $this->audit->cabinet_key]))
            ->assertOk()->assertSee(route('design-office.closure', ['pm' => $this->audit->cabinet_key]), false)
            ->assertSee(__('design_office_workflow.closure.closed_by', ['name' => $this->supervisor->name, 'date' => $this->audit->refresh()->closed_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]))
            ->assertDontSee(__('audit.archive.button'));
        $this->actingAs($this->technician)->get(route('audits.show', ['cabinet' => $this->audit->cabinet_key]))
            ->assertOk()->assertDontSee(route('design-office.closure', ['pm' => $this->audit->cabinet_key]), false);
    }
}
