<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\AnomalyCatalogue;
use App\DesignOfficeWorkflow\DecisionCatalogue;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditDecisionOutcome;
use App\Livewire\DesignOfficeWorkflow\CataloguesPage;
use App\Models\AuditAnomalyType;
use App\Models\AuditDecisionType;
use Database\Seeders\DesignOfficeCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Catalogues of the workflow kept by the supervisors (spec 13 §4.9, 17): anomaly types with a nature fixed once
 * created, decisions with an effect fixed once created, both switched off instead of deleted, a switched-off entry no
 * longer offered, the last active decision of an effect kept, and the neutral initial catalogue.
 */
class WorkflowCataloguesTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
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

    public function test_the_initial_catalogue_is_neutral_and_seeding_again_keeps_the_supervisors_changes(): void
    {
        AuditAnomalyType::query()->where('code', 'fixing')->update(['label' => 'Bracket', 'is_active' => false]);
        $this->seed(DesignOfficeCatalogueSeeder::class);

        $this->assertSame(count(DesignOfficeCatalogueSeeder::ANOMALY_TYPES), AuditAnomalyType::query()->count());
        $this->assertSame(3, AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Optical)->count());
        $this->assertSame('Bracket', AuditAnomalyType::query()->where('code', 'fixing')->value('label'));
        $this->assertTrue(AuditAnomalyType::query()->where('code', 'other_anomaly')->exists());
        $this->assertNull(AuditAnomalyType::query()->where('code', 'faulty_tco')->value('label'));
    }

    public function test_only_a_supervisor_manages_anomaly_types_whose_nature_never_changes(): void
    {
        $catalogue = app(AnomalyCatalogue::class);

        $this->assertSame('403:forbidden', $this->reason(fn () => $catalogue->create($this->reviewer, 'Dirty connector', AuditAnomalyNature::Optical)));
        $this->assertSame('422:label_required', $this->reason(fn () => $catalogue->create($this->supervisor, 'ab', AuditAnomalyNature::Optical)));

        $type = $catalogue->create($this->supervisor, 'Dirty connector', AuditAnomalyNature::Optical);
        $catalogue->rename($this->supervisor, $type, 'Soiled connector');
        $catalogue->setActive($this->supervisor, $type, false);

        $type->refresh();
        $this->assertSame('Soiled connector', $type->label);
        $this->assertSame(AuditAnomalyNature::Optical, $type->nature);
        $this->assertFalse($type->is_active);

        $box = $this->readyBox();
        $this->assertSame('422:bad_anomaly_type', $this->reason(fn () => app(OccurrenceLifecycle::class)->create($this->technician, $box, $type->id, 'connector soiled', [$box->photos()->value('id')])));

        $builtIn = AuditAnomalyType::query()->where('code', 'faulty_tco')->firstOrFail();
        $catalogue->rename($this->supervisor, $builtIn, '');
        $this->assertSame(__('design_office_workflow.anomaly_types.faulty_tco'), $builtIn->refresh()->displayLabel());
    }

    public function test_decisions_are_added_with_a_fixed_effect_and_the_last_one_of_an_effect_stays_active(): void
    {
        $catalogue = app(DecisionCatalogue::class);

        $refusal = $catalogue->create($this->supervisor, 'Refuse: photos blurred', AuditDecisionOutcome::Refuse, true, false);
        $this->assertTrue($refusal->requires_targets);
        $this->assertStringStartsWith('custom_', $refusal->code);

        $catalogue->update($this->supervisor, $refusal, 'Refuse: photos unreadable', false, false);
        $refusal->refresh();
        $this->assertSame('Refuse: photos unreadable', $refusal->label);
        $this->assertFalse($refusal->requires_reason);
        $this->assertTrue($refusal->requires_targets);

        $validate = AuditDecisionType::query()->where('code', 'validate')->firstOrFail();
        $this->assertSame('409:last_decision_of_outcome', $this->reason(fn () => $catalogue->setActive($this->supervisor, $validate, false)));
        $catalogue->setActive($this->supervisor, AuditDecisionType::query()->where('code', 'refuse')->firstOrFail(), false);
        $this->assertSame('409:last_decision_of_outcome', $this->reason(fn () => $catalogue->setActive($this->supervisor, $refusal, false)));
        $this->assertSame('403:forbidden', $this->reason(fn () => $catalogue->create($this->reviewer, 'Another one', AuditDecisionOutcome::Validate, false, false)));

        $request = $this->submit($this->readyBox());
        $this->assertSame('422:unknown_decision', $this->reason(fn () => $this->decide($request, 'refuse', targets: ['labels'])));
        $this->decide($request, $refusal->code, reason: null, targets: ['retake the label photos']);
        $this->assertSame($refusal->id, $request->refresh()->decision?->audit_decision_type_id);
    }

    public function test_the_supervisor_manages_both_catalogues_on_the_screen(): void
    {
        $tco = AuditAnomalyType::query()->where('code', 'faulty_tco')->firstOrFail();
        $validate = AuditDecisionType::query()->where('code', 'validate')->firstOrFail();

        Livewire::actingAs($this->supervisor)->test(CataloguesPage::class)
            ->set('anomalyLabel', 'Dirty connector')->set('anomalyNature', 'optical')->call('addAnomalyType')->assertHasNoErrors()
            ->call('toggleAnomalyType', $tco->id)
            ->call('editAnomalyType', $tco->id)->set('editingAnomalyLabel', 'TCO out of order')->call('renameAnomalyType')->assertHasNoErrors()
            ->set('decisionLabel', 'Validate after phone call')->set('decisionOutcome', 'validate')->set('decisionRequiresReason', true)->call('addDecisionType')->assertHasNoErrors()
            ->call('toggleDecisionType', $validate->id)->assertHasNoErrors()
            ->set('decisionLabel', '')->call('addDecisionType')->assertHasErrors(['decisionLabel', 'decisionOutcome']);

        $this->assertTrue(AuditAnomalyType::query()->where('label', 'Dirty connector')->where('nature', 'optical')->exists());
        $this->assertFalse($tco->refresh()->is_active);
        $this->assertSame('TCO out of order', $tco->label);
        $this->assertFalse($validate->refresh()->is_active);
        $this->assertTrue(AuditDecisionType::query()->where('label', 'Validate after phone call')->where('is_active', true)->exists());
    }
}
