<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditFolder;
use App\Enums\AuditInspectionState;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\Role;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditOccurrencePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * What the occurrences of a box mean for its inspection (spec 13 §4.4, 11, 13): optical anomalies must be corrected
 * or justified before submitting, a waiting one only allows continuation, a refusal or a control, and a validated
 * inspection moves the occurrences on and derives the conformity.
 */
class InspectionOccurrencesTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    private function occurrence(AuditBox $box, AuditAnomalyNature $nature, AuditOccurrenceState $state = AuditOccurrenceState::Detected, array $attributes = []): AuditOccurrence
    {
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => $nature, 'state' => $state,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', $nature)->value('id'), ...$attributes,
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $this->technician->id]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_photo_id' => $box->photos()->value('id'), 'role' => AuditOccurrencePhotoRole::Before, 'linked_at' => now()]);

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

    public function test_an_optical_anomaly_neither_corrected_nor_justified_blocks_the_submission(): void
    {
        $box = $this->readyBox();
        $occurrence = $this->occurrence($box, AuditAnomalyNature::Optical);

        $this->assertSame('422:optical_not_addressed', $this->reason(fn () => $this->submit($box)));

        $occurrence->update(['pending_justification' => 'chamber flooded, access impossible today']);
        $request = $this->submit($box);

        $this->assertSame([$occurrence->reference], collect($request->submission->snapshot['occurrences'])->pluck('reference')->all());
    }

    public function test_an_unconfirmed_impacted_box_does_not_count(): void
    {
        $origin = $this->readyBox();
        $occurrence = $this->occurrence($origin, AuditAnomalyNature::Optical);
        $technician = $this->person(Role::Technician);
        $impacted = $this->readyBox($technician);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $impacted->id, 'is_suggested' => true, 'added_at' => now()]);

        $this->assertSame('none', $this->reason(fn () => $this->submit($impacted, $technician)));
    }

    public function test_a_waiting_optical_anomaly_only_allows_continuation_a_refusal_or_a_control(): void
    {
        $box = $this->readyBox();
        $occurrence = $this->occurrence($box, AuditAnomalyNature::Optical, attributes: ['pending_justification' => 'chamber flooded, access impossible today']);
        $request = $this->submit($box);

        $this->assertSame('422:optical_pending', $this->reason(fn () => $this->decide($request, 'validate', reason: null)));
        $this->assertSame('422:optical_pending', $this->reason(fn () => $this->decide($request, 'validate_with_reserves')));
        $this->decide($request, 'authorize_continuation', reason: 'go on, fix it later');

        $box->refresh();
        $occurrence->refresh();
        $this->assertSame(AuditInspectionState::Validated, $box->inspection_state);
        $this->assertTrue($box->is_continuation_authorized);
        $this->assertNotNull($occurrence->pending_authorized_at);
        $this->assertSame(AuditOccurrenceState::Detected, $occurrence->state, 'continuation never means corrected');
        $this->assertSame(AuditConformity::NotAssessed, $box->conformity);
    }

    public function test_validating_the_inspection_moves_the_occurrences_on_and_derives_the_conformity(): void
    {
        $box = $this->readyBox();
        $optical = $this->occurrence($box, AuditAnomalyNature::Optical, AuditOccurrenceState::Corrected, ['action_taken' => 'spliced again']);
        $mechanical = $this->occurrence($box, AuditAnomalyNature::Mechanical);

        $this->decide($this->submit($box), 'validate', reason: null);

        $this->assertSame(AuditOccurrenceState::Validated, $optical->refresh()->state);
        $this->assertSame($this->supervisor->id, $optical->validated_by);
        $this->assertSame(AuditOccurrenceState::ToRepair, $mechanical->refresh()->state);
        $this->assertSame(AuditConformity::NotAssessed, $box->refresh()->conformity, 'a mechanical repair is still open');

        $this->expectException(LogicException::class);
        $optical->update(['state' => AuditOccurrenceState::Refused]);
    }

    public function test_a_box_without_open_occurrence_conforms_once_its_inspection_is_validated(): void
    {
        $box = $this->readyBox();

        $this->decide($this->submit($box), 'validate', reason: null);

        $this->assertSame(AuditConformity::Conforming, $box->refresh()->conformity);
    }

    public function test_a_blocking_observation_keeps_the_box_from_being_submitted(): void
    {
        $box = $this->readyBox();
        AuditBoxMessage::factory()->blocking()->create(['audit_box_id' => $box->id, 'author_id' => $this->reviewer->id]);

        $this->assertSame('409:blocking_messages', $this->reason(fn () => $this->submit($box)));
    }

    public function test_a_pole_is_proven_by_its_three_sections_or_by_before_and_after(): void
    {
        $box = $this->box(attributes: ['support' => 'pole']);
        app(BoxTeam::class)->claim($this->technician, $box);
        $this->fillPhotos($box, [AuditFolder::Pole]);
        $this->photo($box, AuditFolder::Pole, 'whole');

        $this->assertSame('422:missing_proofs', $this->reason(fn () => $this->submit($box)));

        $this->photo($box, AuditFolder::Pole, 'before');
        $this->photo($box, AuditFolder::Pole, 'after');
        $this->assertSame('none', $this->reason(fn () => $this->submit($box)));
    }
}
