<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\DesignOfficeWorkflow\LegacyBoxStatus;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\Enums\AuditBoxStatus;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Decision 2 of spec 13 §9: on a PM in the design office workflow the box status of the standard workflow follows the
 * workflow — « OK » once conforming, « anomaly » as soon as an occurrence counts on the box, « not installed » stays a
 * field statement out of the count, and sending a box back (« to be done again ») is a phase 2 repair instead.
 */
class LegacyBoxStatusTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    private function operation(User $actor, AuditBox $box, array $operation): string
    {
        try {
            app(AuditOperations::class)->apply($actor, $box->audit, 'op_'.bin2hex(random_bytes(6)), $operation);
        } catch (AuditOperationException $exception) {
            return $exception->status.':'.$exception->reason;
        }

        return 'none';
    }

    public function test_a_declared_occurrence_shows_the_box_as_anomaly_and_a_validated_box_as_ok(): void
    {
        $box = $this->readyBox();
        $type = (int) AuditAnomalyType::query()->where('code', 'faulty_tco')->value('id');
        $photos = $box->photos()->pluck('id')->all();
        $revision = $box->audit->revision;
        $occurrence = app(OccurrenceLifecycle::class)->create($this->technician, $box, $type, 'TCO out of order', [$photos[0]]);

        $box->refresh();
        $this->assertSame(AuditBoxStatus::Anomaly, $box->status);
        $this->assertSame($occurrence->reference, $box->reason);
        $this->assertGreaterThan($revision, $box->audit->refresh()->revision);

        app(OccurrenceLifecycle::class)->correct($this->technician, $occurrence, 'TCO replaced', [$photos[1]]);
        $this->decide($this->submit($box), 'validate', reason: null);

        $this->assertSame(AuditBoxStatus::Ok, $box->refresh()->status);
        $this->assertNull($box->reason);
    }

    public function test_ok_and_anomaly_cannot_be_typed_against_the_workflow(): void
    {
        $box = $this->readyBox();

        $this->assertSame('409:status_follows_decision', $this->operation($this->supervisor, $box, ['op' => 'box_status', 'box' => $box->name, 'status' => 'ok']));
        $this->assertSame('409:occurrence_required', $this->operation($this->technician, $box, ['op' => 'box_status', 'box' => $box->name, 'status' => 'anomaly', 'reason' => 'seal']));
        $this->assertSame('none', $this->operation($this->technician, $box, ['op' => 'box_status', 'box' => $box->name, 'status' => 'not_installed']));
        $this->assertSame('409:design_office_workflow', $this->operation($this->supervisor, $box, ['op' => 'box_return', 'box' => $box->name, 'comment' => 'redo it']));
    }

    public function test_a_box_not_installed_is_out_of_the_count(): void
    {
        $counted = $this->box();
        $notInstalled = $this->box(attributes: ['status' => AuditBoxStatus::NotInstalled]);
        $removed = $this->box(attributes: ['removed_at' => now()]);

        $ids = LegacyBoxStatus::counted(AuditBox::query()->where('audit_id', $this->audit->id))->pluck('id')->all();

        $this->assertSame([$counted->id], $ids);
        $this->assertNotContains($notInstalled->id, $ids);
        $this->assertNotContains($removed->id, $ids);
    }

    public function test_a_standard_audit_keeps_its_typed_statuses(): void
    {
        $audit = $this->plannedAudit([$this->technician], designOffice: false);
        $box = $this->box($audit);

        $this->assertSame('none', $this->operation($this->supervisor, $box, ['op' => 'box_status', 'box' => $box->name, 'status' => 'ok']));
        $this->assertSame(AuditBoxStatus::Ok, $box->refresh()->status);
    }
}
