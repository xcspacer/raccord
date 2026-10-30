<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\DecideRepairs;
use App\DesignOfficeWorkflow\FieldPhotoRules;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\RepairPlanning;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditFolder;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditWorkflowPhase;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditValidationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * An occurrence the client declared on a closed PM is repaired without reopening it (spec 13-44): the supervisor plans
 * the intervention, the team takes its photos, records the correction and submits the repairs, the design office
 * decides — while everything else stays refused on the closed PM.
 */
class ClientRepairOnClosedAuditTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    /**
     * A validated, conforming box of a PM that is then closed.
     */
    private function closedBox(): AuditBox
    {
        $box = $this->readyBox();
        $this->decide($this->submit($box), 'validate');
        $this->audit->forceFill(['closed_at' => now(), 'closed_by' => $this->supervisor->id, 'phase' => AuditWorkflowPhase::Closure])->save();

        return $box->refresh();
    }

    private function occurrence(AuditBox $box, AuditOccurrenceSource $source): AuditOccurrence
    {
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => AuditAnomalyNature::Mechanical, 'state' => AuditOccurrenceState::ToRepair, 'source' => $source,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Mechanical)->value('id'),
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $this->reviewer->id]);

        return $occurrence;
    }

    private function reason(callable $action): string
    {
        try {
            $action();
        } catch (WorkflowException $exception) {
            return $exception->status.':'.$exception->reason;
        }

        return 'none';
    }

    public function test_a_client_occurrence_is_planned_repaired_submitted_and_decided_while_the_pm_stays_closed(): void
    {
        $box = $this->closedBox();
        $occurrence = $this->occurrence($box, AuditOccurrenceSource::Client);

        app(RepairPlanning::class)->plan($this->supervisor, $box, now()->toDateString(), $this->technician->id);
        $this->assertCount(1, app(RepairPlanning::class)->interventionsOf($this->technician), 'the technician sees the intervention of the closed PM');

        $this->actingAs($this->technician)->getJson(route('design-office.boxes.show', $box))->assertOk()->assertJsonPath('client_repair', true);
        $this->assertSame(['is_geo_missing' => false, 'geo_missing_reason' => null], app(FieldPhotoRules::class)->admit($this->technician, $this->audit->refresh(), $box, false, ['45.1', '1.5'], null, null));

        $after = $this->photo($box, AuditFolder::Fixing);
        $this->actingAs($this->technician)->postJson(route('design-office.occurrences.correct', $occurrence), ['action' => 'Sealant applied again', 'after_photo_ids' => [$after->id]])
            ->assertOk()->assertJsonPath('occurrence.state', AuditOccurrenceState::Corrected->value);

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.submit-repairs', $box), ['operation_id' => $this->operationId()])->assertCreated();
        $request = AuditValidationRequest::query()->latest('id')->firstOrFail();

        app(DecideRepairs::class)->handle($this->supervisor, $request, [$occurrence->id => ['accepted' => true]]);

        $this->assertSame(AuditOccurrenceState::Validated, $occurrence->refresh()->state);
        $this->assertSame(AuditConformity::Conforming, $box->refresh()->conformity);
        $this->assertTrue($this->audit->refresh()->isClosed(), 'the repair never reopens the PM');
        $this->assertSame(1, AuditOccurrencePhoto::query()->where('audit_occurrence_id', $occurrence->id)->where('role', AuditOccurrencePhotoRole::After)->count());
    }

    public function test_everything_else_stays_refused_on_a_closed_pm(): void
    {
        $box = $this->closedBox();
        $other = $this->box(attributes: ['name' => 'BPE-OTHER']);
        $field = $this->occurrence($other, AuditOccurrenceSource::Field);
        $type = AuditAnomalyType::query()->where('is_active', true)->value('id');
        $photo = $this->photo($box, AuditFolder::Closed);

        $this->assertSame('409:audit_closed', $this->reason(fn () => app(RepairPlanning::class)->plan($this->supervisor, $other, now()->toDateString(), $this->technician->id)));
        $this->assertSame('409:audit_closed', $this->reason(fn () => app(OccurrenceLifecycle::class)->correct($this->supervisor, $field, 'done', [])));
        $this->assertSame('409:audit_closed', $this->reason(fn () => app(OccurrenceLifecycle::class)->create($this->technician, $box, (int) $type, 'new anomaly', [$photo->id])));
        $this->assertSame('409:audit_closed', $this->reason(fn () => app(FieldPhotoRules::class)->admit($this->technician, $this->audit->refresh(), $box, false, ['45.1', '1.5'], null, null)));
        $this->actingAs($this->technician)->getJson(route('design-office.boxes.show', $box))->assertOk()->assertJsonPath('client_repair', false);
    }
}
