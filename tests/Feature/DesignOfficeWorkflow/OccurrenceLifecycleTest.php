<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\ImpactedBoxes;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Role;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Life of an occurrence (spec 13 §4.17–24): numbering per PM, declaration with a « before » photo of the box, links
 * without copying files, optical anomalies on hold, corrections with an action and an « after » photo (mechanical ones
 * only in phase 2 during a planned intervention), the state machine and the impacted boxes proposed by the field and
 * confirmed or excluded by the design office. Inspired by the prototype tests (occurrences.test.ts).
 */
class OccurrenceLifecycleTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private int $opticalType;

    private int $mechanicalType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
        $this->opticalType = (int) AuditAnomalyType::query()->where('code', 'faulty_tco')->value('id');
        $this->mechanicalType = (int) AuditAnomalyType::query()->where('code', 'rubber_seal_to_replace')->value('id');
    }

    /**
     * @return list<int>
     */
    private function photoIds(AuditBox $box): array
    {
        return $box->photos()->pluck('id')->all();
    }

    private function declare(AuditBox $box, int $type, ?User $actor = null, ?array $photos = null, string $description = 'anomaly found on site'): AuditOccurrence
    {
        return app(OccurrenceLifecycle::class)->create($actor ?? $this->technician, $box, $type, $description, $photos ?? [$this->photoIds($box)[0]]);
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

    public function test_occurrences_are_numbered_per_pm_and_need_a_before_photo_of_the_box(): void
    {
        $box = $this->readyBox();
        $other = $this->readyBox($this->partner);

        $first = $this->declare($box, $this->opticalType);
        $second = $this->declare($box, $this->mechanicalType, photos: [$this->photoIds($box)[1]]);

        $this->assertSame('OC-'.$this->audit->cabinet_key.'-0001', $first->reference);
        $this->assertStringEndsWith('-0002', $second->reference);
        $this->assertSame(AuditOccurrenceState::Detected, $first->state);
        $this->assertSame(AuditAnomalyNature::Optical, $first->nature);
        $this->assertTrue($first->boxLinks()->where('audit_box_id', $box->id)->where('is_origin', true)->whereNotNull('confirmed_at')->exists());
        $this->assertSame(1, $first->photoLinks()->where('role', AuditOccurrencePhotoRole::Before)->count());

        $this->assertSame('422:before_required', $this->reason(fn () => $this->declare($box, $this->opticalType, photos: [])));
        $this->assertSame('422:bad_photo', $this->reason(fn () => $this->declare($box, $this->opticalType, photos: [$this->photoIds($other)[0]])));
        $this->assertSame('422:description_required', $this->reason(fn () => $this->declare($box, $this->opticalType, description: ' x ')));
        $this->assertSame('403:not_team_member', $this->reason(fn () => $this->declare($box, $this->opticalType, $this->otherReviewer)));
        $this->assertSame('403:not_team_member', $this->reason(fn () => $this->declare($box, $this->opticalType, $this->person(Role::Technician))));
    }

    public function test_a_disabled_type_is_refused_and_a_resent_declaration_answers_the_first_one(): void
    {
        $box = $this->readyBox();
        AuditAnomalyType::query()->whereKey($this->mechanicalType)->update(['is_active' => false]);

        $this->assertSame('422:bad_anomaly_type', $this->reason(fn () => $this->declare($box, $this->mechanicalType)));

        $lifecycle = app(OccurrenceLifecycle::class);
        $first = $lifecycle->create($this->technician, $box, $this->opticalType, 'fibre cut', [$this->photoIds($box)[0]], operationId: 'occ_1');
        $again = $lifecycle->create($this->technician, $box, $this->opticalType, 'fibre cut', [$this->photoIds($box)[0]], operationId: 'occ_1');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, AuditOccurrence::query()->count());
    }

    public function test_no_occurrence_is_declared_while_the_box_waits_for_the_design_office(): void
    {
        $box = $this->readyBox();
        $this->submit($box);

        $this->assertSame('409:box_in_validation', $this->reason(fn () => $this->declare($box->refresh(), $this->mechanicalType)));
    }

    public function test_an_optical_anomaly_is_put_on_hold_with_a_justification_or_corrected_with_an_after_photo(): void
    {
        $box = $this->readyBox();
        $photos = $this->photoIds($box);
        $lifecycle = app(OccurrenceLifecycle::class);
        $onHold = $this->declare($box, $this->opticalType);
        $corrected = $this->declare($box, $this->opticalType, photos: [$photos[1]]);

        $this->assertSame('422:pending_justification_required', $this->reason(fn () => $lifecycle->putOnHold($this->technician, $onHold, 'too short')));
        $lifecycle->putOnHold($this->technician, $onHold, 'chamber flooded, pumping needed before any work');
        $this->assertTrue($onHold->refresh()->isPending());

        $this->assertSame('422:after_required', $this->reason(fn () => $lifecycle->correct($this->technician, $corrected, 'splice redone', [])));
        $this->assertSame('422:action_required', $this->reason(fn () => $lifecycle->correct($this->technician, $corrected, 'ok', [$photos[2]])));
        $lifecycle->correct($this->technician, $corrected, 'splice redone and tested', [$photos[2]], 'laser test', 'all good');

        $corrected->refresh();
        $this->assertSame(AuditOccurrenceState::Corrected, $corrected->state);
        $this->assertSame('splice redone and tested', $corrected->action_taken);
        $this->assertSame($this->technician->id, $corrected->corrected_by);
        $this->assertSame([$photos[2]], $corrected->photoLinks()->where('role', AuditOccurrencePhotoRole::After)->pluck('audit_photo_id')->all());

        $this->submit($box);
    }

    public function test_a_mechanical_anomaly_is_never_put_on_hold_nor_corrected_in_phase_one(): void
    {
        $box = $this->readyBox();
        $occurrence = $this->declare($box, $this->mechanicalType);
        $lifecycle = app(OccurrenceLifecycle::class);

        $this->assertSame('409:repairs_phase_required', $this->reason(fn () => $lifecycle->correct($this->technician, $occurrence, 'seal changed', [$this->photoIds($box)[1]])));
        $this->assertSame('409:not_optical', $this->reason(fn () => $lifecycle->putOnHold($this->technician, $occurrence, 'a justification long enough')));

        $this->submit($box);
    }

    public function test_in_phase_two_a_repair_is_corrected_only_during_a_planned_intervention_of_the_person(): void
    {
        $box = $this->readyBox();
        $photos = $this->photoIds($box);
        $occurrence = $this->declare($box, $this->mechanicalType);
        $this->decide($this->submit($box), 'validate', reason: null);
        $this->audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();
        $lifecycle = app(OccurrenceLifecycle::class);

        $this->assertSame(AuditOccurrenceState::ToRepair, $occurrence->refresh()->state);
        $this->assertSame('409:no_intervention', $this->reason(fn () => $lifecycle->correct($this->technician, $occurrence, 'seal changed', [$photos[1]])));

        $intervention = AuditIntervention::query()->create(['audit_id' => $this->audit->id, 'audit_box_id' => $box->id, 'planned_on' => today(), 'responsible_id' => $this->partner->id, 'status' => AuditInterventionStatus::Planned]);
        $intervention->members()->attach($this->technician->id);

        $lifecycle->correct($this->technician, $occurrence, 'seal changed', [$photos[1]]);
        $this->assertSame(AuditOccurrenceState::Corrected, $occurrence->refresh()->state);

        $late = $this->declare($box->refresh(), $this->mechanicalType, photos: [$photos[2]]);
        $this->assertSame(AuditOccurrenceState::ToRepair, $late->state);
    }

    public function test_the_state_machine_refuses_any_other_move_and_a_closed_occurrence_takes_no_photo(): void
    {
        $box = $this->readyBox();
        $photos = $this->photoIds($box);
        $occurrence = $this->declare($box, $this->opticalType);
        $lifecycle = app(OccurrenceLifecycle::class);
        $lifecycle->correct($this->technician, $occurrence, 'fibre spliced again', [$photos[1]]);
        $this->decide($this->submit($box), 'validate', reason: null);

        $this->assertSame(AuditOccurrenceState::Validated, $occurrence->refresh()->state);
        $this->assertSame('409:occurrence_closed', $this->reason(fn () => $lifecycle->linkPhoto($this->technician, $occurrence, $photos[2], AuditOccurrencePhotoRole::Other)));

        $detected = AuditOccurrence::factory()->create(['audit_box_id' => $box->id]);
        $this->expectException(LogicException::class);
        $detected->update(['state' => AuditOccurrenceState::Validated]);
    }

    public function test_photos_are_linked_once_per_role_without_copying_the_file(): void
    {
        $box = $this->readyBox();
        $photos = $this->photoIds($box);
        $occurrence = $this->declare($box, $this->opticalType);
        $lifecycle = app(OccurrenceLifecycle::class);

        $lifecycle->linkPhoto($this->technician, $occurrence, $photos[1], AuditOccurrencePhotoRole::Other);
        $lifecycle->linkPhoto($this->technician, $occurrence, $photos[1], AuditOccurrencePhotoRole::Other);
        $lifecycle->linkPhoto($this->technician, $occurrence, $photos[0], AuditOccurrencePhotoRole::After);

        $this->assertSame(3, $occurrence->photoLinks()->count());
        $this->assertSame($box->photos()->count(), count($photos));
        $this->assertSame('422:bad_photo', $this->reason(fn () => $lifecycle->linkPhoto($this->technician, $occurrence, $this->photoIds($this->readyBox($this->partner))[0], AuditOccurrencePhotoRole::Other)));
    }

    public function test_impacted_boxes_are_proposed_by_the_field_and_confirmed_or_excluded_by_the_design_office(): void
    {
        $origin = $this->readyBox();
        $proposed = $this->readyBox($this->partner);
        $added = $this->readyBox();
        $impacts = app(ImpactedBoxes::class);
        $occurrence = $this->declare($origin, $this->opticalType);
        app(OccurrenceLifecycle::class)->putOnHold($this->technician, $occurrence, 'common cable cut upstream, network work needed');

        $this->assertSame('422:impact_justification_required', $this->reason(fn () => $impacts->propose($this->technician, $occurrence, $proposed, 'tube')));
        $this->assertSame(['confirmed' => false], $impacts->propose($this->technician, $occurrence, $proposed, 'same tube, fibres 3 to 6 dark'));
        $this->submit($proposed->refresh(), $this->partner);

        $this->assertSame(['confirmed' => true], $impacts->propose($this->reviewer, $occurrence, $added, 'same 288 cable, checked by reflectometry'));
        $request = $this->submit($added->refresh());
        $this->assertSame('422:optical_pending', $this->reason(fn () => $this->decide($request, 'validate', reason: null)));

        $this->assertSame('403:forbidden', $this->reason(fn () => $impacts->decide($this->technician, $occurrence, $proposed, true)));
        $this->assertSame('422:exclusion_reason_required', $this->reason(fn () => $impacts->decide($this->reviewer, $occurrence, $proposed, false, '')));
        $this->assertSame('409:origin_box', $this->reason(fn () => $impacts->decide($this->reviewer, $occurrence, $origin, false, 'not this one')));

        $impacts->decide($this->reviewer, $occurrence, $proposed, true);
        $impacts->setResult($this->reviewer, $occurrence, $proposed, AuditConformity::Conforming, 'continuity restored');
        $impacts->decide($this->supervisor, $occurrence, $added, false, 'separate cable after all');

        $links = AuditOccurrenceBox::query()->where('audit_occurrence_id', $occurrence->id)->get()->keyBy('audit_box_id');
        $this->assertNotNull($links[$proposed->id]->confirmed_at);
        $this->assertSame(AuditConformity::Conforming, $links[$proposed->id]->conformity);
        $this->assertNotNull($links[$added->id]->excluded_at);
        $this->assertSame('separate cable after all', $links[$added->id]->exclusion_reason);
        $this->assertSame('409:impact_already_decided', $this->reason(fn () => $impacts->propose($this->technician, $occurrence, $added, 'still think it is impacted')));

        $mechanical = $this->declare($origin, $this->mechanicalType, photos: [$this->photoIds($origin)[1]]);
        $this->assertSame('409:not_optical', $this->reason(fn () => $impacts->propose($this->reviewer, $mechanical, $proposed, 'mechanical anomaly')));
    }

    public function test_every_change_is_written_in_the_hash_chained_journal(): void
    {
        $box = $this->readyBox();
        $occurrence = $this->declare($box, $this->opticalType);
        app(OccurrenceLifecycle::class)->correct($this->technician, $occurrence, 'splice redone', [$this->photoIds($box)[1]]);

        $events = Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('subject_type', $occurrence->getMorphClass())->where('subject_id', $occurrence->id)->orderBy('id')->pluck('event')->all();

        $this->assertSame(['occurrence_created', 'occurrence_state_changed'], $events);
        $this->assertTrue(app(WorkflowJournal::class)->verify()['intact']);
    }
}
