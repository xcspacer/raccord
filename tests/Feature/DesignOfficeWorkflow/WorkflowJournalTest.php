<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Jobs\AlertAdministrators;
use App\Logs\HashChainedJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * The journal of the design office workflow lives in the platform's activity log with a hash chain (decision 12 of
 * spec 13 §9): any change is detected by the daily verification, the order of JSON keys does not matter and the
 * retention clean-up never removes it.
 */
class WorkflowJournalTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorkflow();
    }

    public function test_the_workflow_writes_an_intact_chain_and_tampering_is_detected(): void
    {
        $this->decide($this->submit($this->readyBox()), 'validate', reason: null);
        $journal = app(WorkflowJournal::class);

        $result = $journal->verify();
        $this->assertTrue($result['intact']);
        $this->assertGreaterThanOrEqual(3, $result['checked']);

        $decision = Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('event', 'inspection_decided')->sole();
        DB::table('activity_log')->where('id', $decision->id)->update(['properties' => json_encode(['reason' => 'forged'])]);

        $broken = $journal->verify();
        $this->assertFalse($broken['intact']);
        $this->assertSame($decision->id, $broken['broken_at']);
    }

    public function test_a_removed_entry_breaks_the_chain(): void
    {
        $this->submit($this->readyBox());
        $first = Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->orderBy('id')->first();

        DB::table('activity_log')->where('id', $first->id)->delete();

        $this->assertFalse(app(WorkflowJournal::class)->verify()['intact']);
    }

    public function test_the_hash_does_not_depend_on_the_order_of_keys(): void
    {
        $journal = app(HashChainedJournal::class);

        $this->assertSame($journal->canonical(['b' => 1, 'a' => ['z' => 1, 'y' => [['d' => 1, 'c' => 2]]]]), $journal->canonical(['a' => ['y' => [['c' => 2, 'd' => 1]], 'z' => 1], 'b' => 1]));
    }

    public function test_the_retention_clean_up_keeps_the_chained_journal(): void
    {
        $this->submit($this->readyBox());
        activity()->log('ordinary entry');
        DB::table('activity_log')->update(['created_at' => now()->subYears(5)]);

        $this->artisan('activitylog:clean', ['--force' => true, '--days' => 30])->assertSuccessful();

        $this->assertSame(0, Activity::query()->where('log_name', '!=', WorkflowJournal::LOG_NAME)->count());
        $this->assertGreaterThan(0, Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->count());
        $this->artisan('activitylog:clean', [WorkflowJournal::LOG_NAME, '--force' => true, '--days' => 30])->assertSuccessful();
        $this->assertGreaterThan(0, Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->count());
    }

    public function test_the_daily_verification_alerts_the_administrators_when_the_chain_is_broken(): void
    {
        Bus::fake();
        $this->submit($this->readyBox());

        $this->artisan('design-office:verify-journal')->assertSuccessful();
        Bus::assertNotDispatchedAfterResponse(AlertAdministrators::class);

        DB::table('activity_log')->where('log_name', WorkflowJournal::LOG_NAME)->update(['event' => 'forged']);

        $this->artisan('design-office:verify-journal')->assertFailed();
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->event === 'journal_broken');
    }
}
