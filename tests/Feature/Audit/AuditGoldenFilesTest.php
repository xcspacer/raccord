<?php

namespace Tests\Feature\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\Drawer\DrawerPlan;
use App\Audit\Drawer\DrawerRecheck;
use App\Audit\OpenAudit;
use App\Audit\Recheck\BoxRecheck;
use App\Audit\Spreadsheet\AuditSynthesis;
use App\Audit\Spreadsheet\AuditTimesheet;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditPhoto;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ComparesWithLegacyWorkbooks;
use Tests\Support\WorkbookSnapshot;
use Tests\TestCase;

/**
 * Golden files of the audit deliverables: the synthesis, the audit time sheet, the RECNOK of a box and of the drawer
 * plan are compared cell by cell (values, merges, widths, fonts, fills, borders) with what the legacy platform wrote
 * for the same invented input (tests/fixtures/legacy). The French of the legacy is compared with the « fr » locale.
 */
class AuditGoldenFilesTest extends TestCase
{
    use ComparesWithLegacyWorkbooks;
    use RefreshDatabase;

    private const string CABLE_A = 'ACME X1 144 Fo - A 901-PM O PB0014 E';

    private const string CABLE_B = 'ACME X2 12 Fo - A PB0014 O PB0020 E';

    private const string CABLE_B2 = 'ACME X3 6 Fo - A PB0014 O PB0021 E';

    private User $alice;

    private User $bruno;

    private User $chloe;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app()->setLocale('fr');
        $this->travelTo(CarbonImmutable::parse('2026-10-20T13:00:00Z'));
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create();
        $this->alice = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice Martin', 'planning_order' => 2]);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Bruno Petit', 'planning_order' => 1]);
        $this->chloe = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Chloé Roux', 'planning_order' => 0]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id, 'name' => 'Sam Leroy']);
    }

    public function test_the_synthesis_is_the_legacy_one(): void
    {
        $audit = $this->audit('PM901', ['mode' => AuditMode::Optical, 'sro' => 'SRO-TEST-0001']);
        $bpe10 = $this->box($audit, 'BPE-0010-FT', AuditBoxKind::Bpe, 'chamber', ['status' => 'anomaly', 'reason' => 'Boîte fissurée', 'status_by' => $this->bruno->id, 'status_by_name' => 'Bruno Petit', 'status_at' => '2026-10-19T14:40:00Z']);
        $bpe10->cables()->create(['code' => 'CAB1X', 'capacity' => '12FO']);
        $this->photo($bpe10, 'closed', $this->bruno, '2026-10-19T14:30:00Z');
        $bpe2 = $this->box($audit, 'BPE-0002-FT', AuditBoxKind::Bpe, 'chamber', [
            'status' => 'ok', 'status_by' => $this->alice->id, 'status_by_name' => 'Alice Martin', 'status_at' => '2026-10-19T08:05:00Z', 'note_tco' => 'Bon état',
            'confirmed_latitude' => 43.9123456, 'confirmed_longitude' => 2.1456789, 'confirmed_accuracy' => 4, 'confirmed_by' => $this->alice->id, 'confirmed_by_name' => 'Alice Martin', 'confirmed_at' => '2026-10-19T07:50:00Z',
        ]);
        $bpe2->cables()->create(['code' => 'CAB1X', 'capacity' => '144FO', 'fibre_type' => 'g652']);
        $bpe2->cables()->create(['code' => 'CAB2X', 'capacity' => '48FO', 'fibre_type' => 'g657', 'note' => "vers  PB\nnord"]);
        foreach (['07:55', '07:56'] as $time) {
            $this->photo($bpe2, 'tco', $this->alice, "2026-10-19T{$time}:00Z");
        }
        $this->photo($bpe2, 'closed', $this->alice, '2026-10-19T07:57:00Z');
        foreach ([['office', null], ['field', null], ['office', '2026-10-19T09:00:00Z'], ['office', null]] as [$side, $acknowledged]) {
            $bpe2->remarks()->create(['author_side' => $side, 'user_name' => 'Sam Leroy', 'text' => 'À revoir', 'created_at' => now()])->forceFill(['acknowledged_at' => $acknowledged])->save();
        }
        $pb3 = $this->box($audit, '901-PB0003', AuditBoxKind::Pbo, 'pole');
        $this->photo($pb3, 'surroundings', $this->alice, '2026-10-18T09:00:00Z');
        $this->photo($pb3, 'surroundings', $this->bruno, '2026-10-18T10:00:00Z');
        $jd = $this->box($audit, 'JD-PBO-12', AuditBoxKind::Joint, 'facade', ['status' => 'not_installed', 'reason' => 'Accès impossible', 'status_by' => $this->alice->id, 'status_by_name' => 'Alice Martin', 'status_at' => '2026-10-20T06:15:00Z']);
        $jd->remarks()->create(['author_side' => 'office', 'user_name' => 'Sam Leroy', 'text' => 'Photo ?', 'created_at' => now()]);
        $pb1 = $this->box($audit, '901-PB0001', AuditBoxKind::Pbo, 'facade', ['status' => 'partial', 'status_by' => $this->bruno->id, 'status_by_name' => 'Bruno Petit', 'status_at' => '2026-10-18T16:00:00Z']);
        $this->photo($pb1, 'tco', $this->bruno, '2026-10-18T15:00:00Z');
        $this->box($audit, 'BPE-0003-FT', AuditBoxKind::Bpe, 'chamber', ['status' => 'ok', 'removed_at' => '2026-10-17T10:00:00Z']);

        $book = app(AuditSynthesis::class)->build($audit->refresh(), $this->manager);

        $this->assertSameAsLegacy('audit-synthesis.xlsx', $this->snapshot($book));
    }

    public function test_the_audit_time_sheet_is_the_legacy_one(): void
    {
        $pm901 = $this->audit('PM901');
        $bpe2 = $this->box($pm901, 'BPE-0002-FT', AuditBoxKind::Bpe, 'chamber', ['status' => 'ok', 'status_by' => $this->alice->id, 'status_by_name' => 'Alice Martin', 'status_at' => '2026-10-13T07:30:00Z']);
        foreach (['07:10', '07:11', '07:12'] as $time) {
            $this->photo($bpe2, 'closed', $this->alice, "2026-10-13T{$time}:00Z");
        }
        $this->photo($bpe2, 'closed', $this->manager, '2026-10-13T09:00:00Z', imported: true);
        $bpe10 = $this->box($pm901, 'BPE-0010-FT', AuditBoxKind::Bpe, 'chamber', ['status' => 'anomaly', 'status_by' => $this->bruno->id, 'status_by_name' => 'Bruno Petit', 'status_at' => '2026-10-14T09:00:00Z']);
        $this->photo($bpe10, 'closed', $this->bruno, '2026-10-13T08:00:00Z');
        $this->photo($bpe10, 'closed', $this->bruno, '2026-10-13T22:30:00Z');
        $this->box($pm901, '901-PB0003', AuditBoxKind::Pbo, 'pole', ['status' => 'not_installed', 'status_by' => $this->alice->id, 'status_by_name' => 'Alice Martin', 'status_at' => '2026-10-14T10:00:00Z']);
        $pb4 = $this->box($pm901, '901-PB0004', AuditBoxKind::Pbo, 'pole', ['status' => 'ok', 'status_by' => $this->manager->id, 'status_by_name' => 'Sam Leroy', 'status_at' => '2026-10-14T11:00:00Z']);
        $this->photo($pb4, 'closed', $this->alice, '2026-10-15T08:00:00Z');
        $pb10 = $this->box($pm901, '901-PB0010', AuditBoxKind::Pbo, 'pole', [
            'status' => 'partial', 'status_by' => $this->alice->id, 'status_by_name' => 'Alice Martin', 'status_at' => '2026-10-16T09:00:00Z',
            'report_items' => [['label' => 'Tirage câble', 'qty' => 2], ['label' => 'Pose boîtier', 'qty' => 1]], 'report_at' => '2026-10-15T10:00:00Z', 'report_by' => $this->alice->id, 'report_by_name' => 'Alice Martin',
        ]);
        $this->photo($pb10, 'closed', $this->alice, '2026-10-15T09:00:00Z');
        $this->photo($pb10, 'closed', $this->alice, '2026-10-15T09:05:00Z');
        $pb11 = $this->box($pm901, '901-PB0011', AuditBoxKind::Pbo, 'pole', ['status' => 'ok', 'status_by' => $this->bruno->id, 'status_by_name' => 'Bruno Petit', 'status_at' => '2026-10-15T09:10:00Z', 'returned_comment' => 'Refaire', 'returned_at' => '2026-10-15T12:00:00Z']);
        $this->photo($pb11, 'closed', $this->bruno, '2026-10-15T09:00:00Z');
        $pb12 = $this->box($pm901, '901-PB0012', AuditBoxKind::Pbo, 'pole', ['removed_at' => '2026-10-16T12:00:00Z']);
        $this->photo($pb12, 'closed', $this->bruno, '2026-10-15T09:00:00Z');
        $pb13 = $this->box($pm901, '901-PB0013', AuditBoxKind::Pbo, 'pole');
        $this->photo($pb13, 'closed', null, '2026-10-15T12:00:00Z');
        $cabinet = AuditControlPoint::query()->create(['audit_id' => $pm901->id, 'name' => 'Armoire']);
        $cabinet->forceFill(['report_items' => [['label' => 'Nettoyage', 'qty' => 1]], 'report_at' => '2026-10-13T09:00:00Z', 'report_by' => $this->bruno->id, 'report_by_name' => 'Bruno Petit'])->save();
        $this->photo($cabinet, 'closed', $this->bruno, '2026-10-13T08:30:00Z');
        $this->photo($cabinet, 'closed', $this->bruno, '2026-10-13T08:31:00Z');
        $chamber = AuditControlPoint::query()->create(['audit_id' => $pm901->id, 'name' => 'Chambre 0']);
        $this->photo($chamber, 'closed', $this->bruno, '2026-10-13T08:40:00Z');
        $pm902 = $this->audit('PM902');
        $this->photo($this->box($pm902, 'BPE-0001-FT', AuditBoxKind::Bpe, 'chamber'), 'closed', $this->alice, '2026-10-13T14:00:00Z');
        $this->photo($this->box($pm902, '902-PB0001', AuditBoxKind::Pbo, 'pole'), 'closed', $this->chloe, '2026-10-17T08:00:00Z');

        $auditSite = WorkSite::factory()->create(['project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM903']);
        $otherSite = WorkSite::factory()->create(['project_type' => ProjectType::Swap->value]);
        Task::factory()->create(['user_id' => $this->chloe->id, 'date' => '2026-10-16', 'work_site_id' => $auditSite->id, 'label' => 'AUDIT', 'detail' => 'PM903 secteur nord']);
        Task::factory()->create(['user_id' => $this->bruno->id, 'date' => '2026-10-16', 'work_site_id' => $auditSite->id, 'label' => 'AUDIT', 'detail' => '']);
        Absence::factory()->create(['user_id' => $this->bruno->id, 'type' => 'absent', 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-16']);
        Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-10-16', 'work_site_id' => $otherSite->id, 'label' => 'RACCORDEMENT', 'detail' => '']);

        $book = app(AuditTimesheet::class)->build($this->manager, CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-10-12'));
        $this->assertNotNull($book);

        $this->assertSameAsLegacy('audit-timesheet.xlsx', $this->snapshot($book), ['{company}' => mb_strtoupper(app(CompanySettings::class)->displayName())]);
    }

    public function test_the_recnok_of_a_box_is_the_legacy_one(): void
    {
        [$box, $plan, $recheck] = $this->boxRecheck();
        $record = $recheck->record($box);
        $changes = [
            0 => ['type' => 'splice', 'fibre_b' => 'F01', 'tube_b' => 'T01', 'cable_b' => self::CABLE_B],
            1 => ['type' => 'pass', 'fibre_b' => 'F05', 'tube_b' => 'T02', 'cable_b' => self::CABLE_B2],
            2 => ['cable_a' => 'CABLE MANUEL 6 FO', 'tube_a' => 'T03'],
            4 => ['fibre_b' => 'F12'],
            7 => ['fibre_a' => 'F09'],
        ];
        foreach ($changes as $index => $patch) {
            $recheck->change($plan, $record->refresh(), [$index], $patch);
        }
        $recheck->setCassetteNumber($record->refresh(), '01 - 12 EPIS', '7');

        $recheck->save($box, $plan, $record->refresh(), $this->alice, false);

        $this->assertSameAsLegacy('box-plan-RECNOK.xlsx', $this->stored($record->refresh()->recnokFile));
    }

    public function test_a_conform_box_gets_the_legacy_exact_copy(): void
    {
        [$box, $plan, $recheck] = $this->boxRecheck();

        $recheck->save($box, $plan, $recheck->record($box), $this->alice, true);

        $this->assertSameAsLegacy('box-plan-RECNOK-conform.xlsx', $this->stored($recheck->record($box)->recnokFile));
    }

    public function test_the_recnok_of_the_drawer_plan_is_the_legacy_one(): void
    {
        $audit = $this->audit('PM901');
        $file = app(StoreUploadedFile::class)->handle(new UploadedFile(base_path('tests/fixtures/legacy/drawer-plan.xlsx'), 'drawer-plan.xlsx', null, null, true));
        $plan = AuditPlan::query()->create(['audit_id' => $audit->id, 'kind' => AuditPlanKind::Drawer, 'name' => 'drawer-plan.xlsx', 'stored_file_id' => $file->id]);
        $recheck = app(DrawerRecheck::class);
        $record = $recheck->record($audit);
        $state = $recheck->state($plan, $record);
        $current = $state['current'];
        for ($position = 13; $position <= 24; $position++) {
            $current[DrawerPlan::key(1, 'A', $position)]['tube'] = 7;
        }
        for ($position = 1; $position <= 6; $position++) {
            $current[DrawerPlan::key(1, 'B', $position)]['pb'] = '';
        }
        for ($position = 7; $position <= 12; $position++) {
            $current[DrawerPlan::key(1, 'B', $position)] = ['pb' => 'PB0003', 'cable' => 'ACME X2 48 Fo - A 901-PM O PB0003 E', 'tube' => 1, 'fibre' => $position - 6];
        }
        for ($position = 13; $position <= 14; $position++) {
            $current[DrawerPlan::key(2, 'A', $position)] = ['pb' => 'PB0006', 'cable' => 'ACME X9 12 Fo - A 901-PM O PB0006 E', 'tube' => 3, 'fibre' => $position - 12];
        }
        $record->update(['changes' => array_filter($current, fn (array $cell, string $key) => ! DrawerPlan::sameCell($cell, $state['original'][$key]), ARRAY_FILTER_USE_BOTH), 'confirmed' => ['PB0001', 'PB0004', 'PB0003']]);

        $recheck->save($audit, $plan, $record->refresh(), $this->alice);

        $this->assertSameAsLegacy('drawer-plan-RECNOK.xlsx', $this->stored($record->refresh()->recnokFile));
    }

    /**
     * @return array{0: AuditBox, 1: AuditPlan, 2: BoxRecheck}
     */
    private function boxRecheck(): array
    {
        $audit = $this->audit('PM901', ['mode' => AuditMode::Optical]);
        $box = $this->box($audit, '901-PB0014', AuditBoxKind::Pbo, 'pole');
        $file = app(StoreUploadedFile::class)->handle(new UploadedFile(base_path('tests/fixtures/legacy/box-plan.xlsx'), '901-PB0014.xlsx', null, null, true));
        $plan = AuditPlan::query()->create(['audit_id' => $audit->id, 'kind' => AuditPlanKind::Box, 'name' => '901-PB0014.xlsx', 'stored_file_id' => $file->id]);

        return [$box, $plan, app(BoxRecheck::class)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function audit(string $cabinet, array $attributes = []): Audit
    {
        $audit = app(OpenAudit::class)->handle($this->manager, $cabinet);
        $audit->update($attributes);
        $audit->controlPoints()->delete();

        return $audit->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function box(Audit $audit, string $name, AuditBoxKind $kind, string $support, array $attributes = []): AuditBox
    {
        return AuditBox::query()->forceCreate(['audit_id' => $audit->id, 'name' => $name, 'kind' => $kind, 'support' => $support, 'source' => AuditBoxSource::Synoptic] + $attributes);
    }

    private function photo(AuditBox|AuditControlPoint $unit, string $folder, ?User $by, string $at, bool $imported = false): void
    {
        $file = StoredFile::query()->create(['sha256' => bin2hex(random_bytes(32)), 'disk' => 'local', 'path' => 'x/'.bin2hex(random_bytes(4)).'.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1, 'reference_count' => 1]);
        AuditPhoto::query()->create([
            'audit_id' => $unit->audit_id, $unit instanceof AuditBox ? 'audit_box_id' : 'audit_control_point_id' => $unit->id, 'folder' => $folder, 'subfolder' => '', 'stored_file_id' => $file->id,
            'captured_at' => $at, 'user_id' => $by?->id, 'user_name' => $by->name ?? 'Ancien Tech', 'is_imported' => $imported, 'received_at' => $at,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Spreadsheet $book): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'golden');
        (new Xlsx($book))->save($path);

        try {
            return WorkbookSnapshot::of($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(?StoredFile $file): array
    {
        $this->assertNotNull($file);

        return WorkbookSnapshot::of(Storage::disk('local')->path($file->path));
    }
}
