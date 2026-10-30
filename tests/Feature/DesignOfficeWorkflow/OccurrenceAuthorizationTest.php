<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\Enums\AuditConformity;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\DesignOfficeWorkflow\CataloguesPage;
use App\Livewire\DesignOfficeWorkflow\OccurrencePage;
use App\Livewire\DesignOfficeWorkflow\OccurrencesPage;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\RoleFeatureOverride;
use App\Support\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Server-side authorization of the occurrences (spec 13 §2): the phone's JSON end points (declare, link, hold,
 * correct, propose, confirm/exclude, result), the list and detail screens of the design office and the supervisors,
 * and the catalogues kept by the supervisors only. Convention: module off → 404; « Gérer les accès » switch off or
 * wrong role → 403; an audit that is not the person's → 404.
 */
class OccurrenceAuthorizationTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    private function opticalType(): int
    {
        return (int) AuditAnomalyType::query()->where('code', 'faulty_tco')->value('id');
    }

    private function occurrence(AuditBox $box): AuditOccurrence
    {
        return app(OccurrenceLifecycle::class)->create($this->technician, $box, $this->opticalType(), 'fibre cut in the box', [$box->photos()->value('id')]);
    }

    public function test_a_team_technician_declares_an_occurrence_and_reads_those_of_the_box(): void
    {
        $box = $this->readyBox();
        $photo = $box->photos()->value('id');

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.occurrences.store', $box), [
            'operation_id' => 'occ_a', 'anomaly_type_id' => $this->opticalType(), 'description' => 'fibre cut', 'before_photo_ids' => [$photo],
        ])->assertCreated()->assertJsonPath('occurrence.state', 'detected')->assertJsonPath('occurrence.photos.0.role', 'before');

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.occurrences.store', $box), [
            'operation_id' => 'occ_a', 'anomaly_type_id' => $this->opticalType(), 'description' => 'fibre cut', 'before_photo_ids' => [$photo],
        ])->assertOk();

        $this->actingAs($this->partner)->getJson(route('design-office.boxes.occurrences.index', $box))
            ->assertOk()->assertJsonCount(1, 'occurrences')->assertJsonPath('occurrences.0.is_inherited', false)
            ->assertJsonPath('anomaly_types.0.id', fn (int $id) => $id > 0);
    }

    public function test_the_declaration_is_refused_to_the_design_office_and_to_strangers(): void
    {
        $box = $this->readyBox();
        $payload = ['anomaly_type_id' => $this->opticalType(), 'description' => 'fibre cut', 'before_photo_ids' => [$box->photos()->value('id')]];

        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.occurrences.store', $box), $payload)->assertForbidden();
        $this->actingAs($this->person(Role::Technician))->postJson(route('design-office.boxes.occurrences.store', $box), $payload)->assertNotFound();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.occurrences.store', $box), [])->assertUnprocessable()->assertJsonValidationErrors('anomaly_type_id');
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.occurrences.store', $box), [...$payload, 'before_photo_ids' => []])
            ->assertUnprocessable()->assertJsonPath('reason', 'before_required');
    }

    public function test_field_actions_check_the_team_and_the_audit(): void
    {
        $box = $this->readyBox();
        $photos = $box->photos()->pluck('id')->all();
        $occurrence = $this->occurrence($box);
        $stranger = $this->person(Role::Technician);

        $this->actingAs($stranger)->postJson(route('design-office.occurrences.hold', $occurrence), ['justification' => 'a long enough justification'])->assertNotFound();
        $this->actingAs($this->reviewer)->postJson(route('design-office.occurrences.correct', $occurrence), ['action' => 'redone', 'after_photo_ids' => [$photos[1]]])->assertForbidden();

        $this->actingAs($this->partner)->postJson(route('design-office.occurrences.photos', $occurrence), ['photo_id' => $photos[1], 'role' => 'other'])
            ->assertForbidden()->assertJsonPath('reason', 'not_team_member');
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.photos', $occurrence), ['photo_id' => $photos[1], 'role' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.photos', $occurrence), ['photo_id' => $photos[1], 'role' => 'other'])
            ->assertOk()->assertJsonCount(2, 'occurrence.photos');
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.hold', $occurrence), ['justification' => 'chamber flooded, no access today'])
            ->assertOk()->assertJsonPath('occurrence.pending_justification', 'chamber flooded, no access today');
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.correct', $occurrence), ['action' => 'splice redone', 'after_photo_ids' => [$photos[2]], 'checks' => 'laser'])
            ->assertOk()->assertJsonPath('occurrence.state', 'corrected')->assertJsonPath('occurrence.pending_justification', null);
    }

    public function test_impacted_boxes_are_proposed_by_the_field_and_decided_by_the_design_office_only(): void
    {
        $origin = $this->readyBox();
        $other = $this->readyBox($this->partner);
        $occurrence = $this->occurrence($origin);

        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.boxes.propose', $occurrence), ['box_id' => $other->id, 'justification' => 'same tube, dark fibres'])
            ->assertOk()->assertJsonPath('confirmed', false)->assertJsonPath('occurrence.boxes.1.state', 'proposed');
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.boxes.decide', [$occurrence, $other]), ['confirm' => true])->assertForbidden();
        $this->actingAs($this->reviewer)->postJson(route('design-office.occurrences.boxes.decide', [$occurrence, $other]), ['confirm' => false])
            ->assertUnprocessable()->assertJsonPath('reason', 'exclusion_reason_required');
        $this->actingAs($this->reviewer)->postJson(route('design-office.occurrences.boxes.decide', [$occurrence, $other]), ['confirm' => true])
            ->assertOk()->assertJsonPath('occurrence.boxes.1.state', 'confirmed');
        $this->actingAs($this->partner)->postJson(route('design-office.occurrences.boxes.result', [$occurrence, $other]), ['conformity' => 'conforming', 'note' => 'continuity back'])
            ->assertOk()->assertJsonPath('occurrence.boxes.1.conformity', 'conforming');
        $this->actingAs($this->partner)->getJson(route('design-office.boxes.occurrences.index', $other))
            ->assertOk()->assertJsonPath('occurrences.0.is_inherited', true);
    }

    public function test_a_standard_audit_answers_409_and_the_module_switch_404(): void
    {
        $standard = $this->plannedAudit([$this->technician], designOffice: false);
        $box = $this->box($standard);
        $this->fillPhotos($box);
        $payload = ['anomaly_type_id' => $this->opticalType(), 'description' => 'fibre cut', 'before_photo_ids' => [$box->photos()->value('id')]];

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.occurrences.store', $box), $payload)->assertStatus(409)->assertJsonPath('reason', 'standard_workflow');

        RoleFeatureOverride::query()->create(['role_id' => RoleModel::findByName(Role::Technician->value)->id, 'feature' => Feature::DesignOfficeWorkflow, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.occurrences.store', $this->readyBox()), $payload)->assertForbidden();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.occurrences'))->assertNotFound();
        $this->actingAs($this->technician)->getJson(route('design-office.boxes.occurrences.index', $box))->assertNotFound();
    }

    public function test_the_occurrence_screens_are_for_the_design_office_and_the_supervisors(): void
    {
        $occurrence = $this->occurrence($this->readyBox());

        $this->actingAs($this->technician)->get(route('design-office.occurrences'))->assertForbidden();
        $this->actingAs($this->technician)->get(route('design-office.occurrences.show', $occurrence))->assertForbidden();
        $this->actingAs($this->reviewer)->get(route('design-office.occurrences'))->assertOk()->assertSee($occurrence->reference);
        $this->actingAs($this->supervisor)->get(route('design-office.occurrences.show', $occurrence))->assertOk()->assertSee($occurrence->description);

        Livewire::actingAs($this->reviewer)->test(OccurrencesPage::class)->set('state', 'validated')->assertDontSee($occurrence->reference);
    }

    public function test_the_design_office_confirms_and_excludes_impacted_boxes_on_the_detail_screen(): void
    {
        $origin = $this->readyBox();
        $other = $this->readyBox($this->partner);
        $third = $this->box();
        $occurrence = $this->occurrence($origin);
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.boxes.propose', $occurrence), ['box_id' => $other->id, 'justification' => 'same tube, dark fibres']);

        Livewire::actingAs($this->reviewer)->test(OccurrencePage::class, ['occurrence' => $occurrence])
            ->call('confirmBox', $other->id)->assertHasNoErrors()
            ->call('startExclusion', $other->id)->set('exclusionReason', '')->call('excludeBox')->assertHasErrors('exclusionReason')
            ->set('exclusionReason', 'separate cable')->call('excludeBox')->assertHasNoErrors()
            ->set('newBoxId', (string) $third->id)->set('newBoxJustification', 'checked on the map')->call('addBox')->assertHasNoErrors()
            ->set('results.'.$third->id, 'conforming')->call('saveResult', $third->id)->assertHasNoErrors();

        $this->assertNotNull($occurrence->boxLinks()->where('audit_box_id', $other->id)->value('excluded_at'));
        $this->assertSame(AuditConformity::Conforming, $occurrence->boxLinks()->where('audit_box_id', $third->id)->firstOrFail()->conformity);

        Livewire::actingAs($this->technician)->test(OccurrencePage::class, ['occurrence' => $occurrence])->assertForbidden();
    }

    public function test_the_catalogues_are_kept_by_the_supervisors_only(): void
    {
        $this->actingAs($this->reviewer)->get(route('design-office.catalogues'))->assertForbidden();
        $this->actingAs($this->technician)->get(route('design-office.catalogues'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('design-office.catalogues'))->assertOk()->assertSee(__('design_office_workflow.anomaly_types.faulty_tco'));

        Livewire::actingAs($this->reviewer)->test(CataloguesPage::class)->assertForbidden();
    }
}
