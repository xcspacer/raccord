<?php

namespace Tests\Feature\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\OpenAudit;
use App\Audit\Recheck\BoxRecheck;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\Role;
use App\Models\AuditBox;
use App\Models\AuditPlan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ComparesWithLegacyWorkbooks;
use Tests\Support\WorkbookSnapshot;
use Tests\TestCase;

/**
 * The RECNOK of real client box plans against what the legacy platform wrote for them. The plans are customer data and
 * never enter the repository: they are read from LEGACY_SAMPLES_DIR/box-plans (« <plan>.xlsx » with the legacy
 * « <plan>.legacy-recnok » and « <plan>.legacy-recnok-conform » written by the legacy code with the same generic
 * corrections as below); the test is skipped without them.
 */
class LegacyBoxPlanSamplesTest extends TestCase
{
    use ComparesWithLegacyWorkbooks;
    use RefreshDatabase;

    /**
     * What differs only because the legacy library rewrote the whole file: it rounds font sizes down (8.25 → 8) and
     * writes the width of one column past the last used one when the plan gives a width to a range of columns.
     *
     * @param  array<string, array<string, mixed>>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function libraryNoise(array $snapshot): array
    {
        foreach ($snapshot as $title => $sheet) {
            $snapshot[$title]['widths'] = array_filter($sheet['widths'], fn (string $column) => strlen($column) === 1 && $column <= 'I', ARRAY_FILTER_USE_KEY);

            foreach ($sheet['cells'] as $coordinate => $cell) {
                if (isset($cell['font']['size'])) {
                    $snapshot[$title]['cells'][$coordinate]['font']['size'] = (float) (int) $cell['font']['size'];
                }
            }
        }

        return $snapshot;
    }

    public function test_real_box_plans_give_the_legacy_recnok(): void
    {
        $plans = getenv('LEGACY_SAMPLES_DIR') ? (glob(rtrim((string) getenv('LEGACY_SAMPLES_DIR'), '/').'/box-plans/*.xlsx') ?: []) : [];

        if ($plans === []) {
            $this->markTestSkipped('No real box plans (LEGACY_SAMPLES_DIR).');
        }

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->withRole(Role::Supervisor)->create();
        $audit = app(OpenAudit::class)->handle($manager, 'PM900');
        $audit->update(['mode' => AuditMode::Optical]);
        $recheck = app(BoxRecheck::class);

        foreach ($plans as $index => $path) {
            $legacy = substr($path, 0, -5);
            $file = app(StoreUploadedFile::class)->handle(new UploadedFile($path, "sample-{$index}.xlsx", null, null, true));
            $plan = AuditPlan::query()->create(['audit_id' => $audit->id, 'kind' => AuditPlanKind::Box, 'name' => "sample-{$index}.xlsx", 'stored_file_id' => $file->id]);

            foreach (['.legacy-recnok' => false, '.legacy-recnok-conform' => true] as $suffix => $conform) {
                $box = AuditBox::query()->forceCreate(['audit_id' => $audit->id, 'name' => "PB-{$index}".($conform ? 'C' : ''), 'kind' => AuditBoxKind::Pbo, 'source' => AuditBoxSource::Synoptic]);
                $record = $recheck->record($box);

                if (! $conform) {
                    $lines = $recheck->lines($plan, $record);
                    $recheck->change($plan, $record, [0], ['type' => 'splice', 'fibre_b' => 'F01', 'tube_b' => 'T01', 'cable_b' => 'CABLE TEST 12 Fo']);
                    $recheck->change($plan, $record->refresh(), [1], ['fibre_a' => 'F09']);
                    $recheck->change($plan, $record->refresh(), [2], ['cable_a' => 'CABLE MANUEL']);
                    $recheck->setCassetteNumber($record->refresh(), $lines[0]['cassette'], '07');
                }

                $recheck->save($box, $plan, $record->refresh(), $manager, $conform);

                $actual = $this->libraryNoise(WorkbookSnapshot::of(Storage::disk('local')->path($record->refresh()->recnokFile->path)));
                $this->assertSameAsLegacyFile($legacy.$suffix, $actual, [], $this->libraryNoise(...));
            }
        }
    }
}
