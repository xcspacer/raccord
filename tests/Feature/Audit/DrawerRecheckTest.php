<?php

namespace Tests\Feature\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\AuditSnapshot;
use App\Audit\Drawer\DrawerPlan;
use App\Audit\Drawer\DrawerReader;
use App\Audit\Drawer\DrawerRecheck;
use App\Audit\OpenAudit;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Audit\AuditPage;
use App\Livewire\Audit\DrawerRecheckPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DrawerRecheckTest extends TestCase
{
    use RefreshDatabase;

    private const string CABLE = 'NET X1 144 Fo - A 153-PM O PB0001 E';

    private User $manager;

    private User $technician;

    private Audit $audit;

    private AuditPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id, 'name' => 'Sam']);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);
        $this->plan = $this->storePlan('PB0002');
    }

    private function storePlan(string $secondPbo): AuditPlan
    {
        $book = new Spreadsheet;
        $pb = $book->getActiveSheet()->setTitle('PB');
        $cable = $book->createSheet()->setTitle('CABLE');

        foreach ([$pb, $cable] as $sheet) {
            $sheet->setCellValue('B1', 'Tiroir : 1');
            $sheet->setCellValue('B3', 'A')->setCellValue('B4', 'B');

            for ($p = 1; $p <= 24; $p++) {
                $sheet->setCellValue([2 + 2 * $p, 2], $p);
            }
        }

        for ($p = 1; $p <= 24; $p++) {
            $pb->setCellValue([2 + 2 * $p, 3], 'PB0001');
            $cable->setCellValue([2 + 2 * $p, 3], self::CABLE."\nT".($p <= 12 ? '01' : '02').' F'.DrawerPlan::pad(DrawerPlan::fibreOf($p)));
        }

        for ($p = 1; $p <= 6; $p++) {
            $pb->setCellValue([2 + 2 * $p, 4], $secondPbo);
            $cable->setCellValue([2 + 2 * $p, 4], self::CABLE."\nT03 F".DrawerPlan::pad($p));
        }

        $path = tempnam(sys_get_temp_dir(), 'drawer').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = app(StoreUploadedFile::class)->handle(new UploadedFile($path, 'drawer.xlsx', null, null, true));

        return AuditPlan::query()->create(['audit_id' => $this->audit->id, 'kind' => AuditPlanKind::Drawer, 'name' => 'drawer.xlsx', 'stored_file_id' => $file->id]);
    }

    private function page(?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->technician)->test(DrawerRecheckPage::class, ['cabinet' => 'PM153']);
    }

    public function test_the_rules_of_tubes_fibres_cables_and_segments(): void
    {
        $this->assertSame([1, 0], [DrawerPlan::colourOfTube(1), DrawerPlan::ringsOfTube(1)]);
        $this->assertSame([12, 1], [DrawerPlan::colourOfTube(24), DrawerPlan::ringsOfTube(24)]);
        $this->assertSame('Pink ring 1', DrawerPlan::tubeName(24));
        $this->assertSame([3, 1], [DrawerPlan::fibreOf(15), DrawerPlan::slotOf(15)]);
        $this->assertSame('B2-T1-MA-01 à 06', DrawerPlan::code(1, 'A', 1, 6));
        $this->assertSame(144, DrawerPlan::parseCable(self::CABLE)['cap']);
        $this->assertSame(self::CABLE, DrawerPlan::buildCable('NET X1', 144, '153-PM', 'PB0001'));
        $this->assertSame('144 Fo → PB0001', DrawerPlan::shortCable(self::CABLE));
        $this->assertSame('PB12', DrawerPlan::shortName('153-PB0012'));
    }

    public function test_the_plan_is_read_into_drawers_cells_and_segments(): void
    {
        $recheck = app(DrawerRecheck::class);
        $state = $recheck->state($this->plan, $recheck->record($this->audit));

        $this->assertSame([['n' => 1, 'npos' => 24, 'mods' => [['L' => 'A', 'row' => 3], ['L' => 'B', 'row' => 4]]]], $state['drawers']);
        $this->assertSame(['pb' => 'PB0002', 'cable' => self::CABLE, 'tube' => 3, 'fibre' => 4], $state['current']['1|B|4']);

        $segments = DrawerPlan::segments($state['drawers'], $state['current'], 1);
        $this->assertSame(['PB0001', 'PB0001', 'PB0002', '', ''], array_column($segments, 'pb'));
        $this->assertSame([1, 13, 1, 7, 13], array_column($segments, 'a'));
        $this->assertSame(['unchecked', 'unchecked'], array_values($recheck->statuses($state['drawers'], $state['original'], $state['current'], $recheck->record($this->audit))));
    }

    public function test_confirming_and_editing_a_pbo_change_its_status_and_keep_the_draft_on_the_server(): void
    {
        $page = $this->page()->call('confirm', 'PB0002');
        $recheck = app(DrawerRecheck::class);
        $state = $recheck->state($this->plan, $recheck->record($this->audit));
        $this->assertSame('conform', $recheck->statuses($state['drawers'], $state['original'], $state['current'], $recheck->record($this->audit))['PB0002']);

        $page->call('openEdit', 'PB0002')
            ->call('editField', 'b', 0, '8')
            ->call('pickTube', 0, 0, 5)
            ->call('applyEdit')
            ->assertSet('edit', null);

        $record = $recheck->record($this->audit)->refresh();
        $state = $recheck->state($this->plan, $record);
        $this->assertSame('modified', $recheck->statuses($state['drawers'], $state['original'], $state['current'], $record)['PB0002']);
        $this->assertEquals(['pb' => 'PB0002', 'cable' => self::CABLE, 'tube' => 5, 'fibre' => 8], $state['current']['1|B|8']);
        $this->assertNotContains('PB0002', $record->confirmed);
        $this->assertTrue($record->has_unsaved_changes);

        $page->call('revert', 'PB0002');
        $state = $recheck->state($this->plan, $recheck->record($this->audit)->refresh());
        $this->assertSame($state['original'], $state['current']);
    }

    public function test_an_edit_is_refused_without_tube_cable_or_name_and_asks_before_taking_positions(): void
    {
        $page = $this->page()->call('openFree', 1, 'B', 7, 8);
        $page->call('applyEdit')->assertSet('error', __('audit.drawer.errors.name'));
        $page->call('editManualName', 'PB0009')->call('applyEdit')->assertSet('error', __('audit.drawer.errors.cable'));
        $page->call('editField', 'cable', 0, self::CABLE)->call('applyEdit')->assertSet('error', __('audit.drawer.errors.tube'));
        $page->call('editField', 'cable', 0, 'made up cable');
        $this->assertSame('', $page->get('edit')['segs'][0]['cable']);

        $page->call('editField', 'cable', 0, self::CABLE)->call('pickTube', 0, 0, 2)->call('editField', 'a', 0, '4')->call('applyEdit')
            ->assertSet('clash', ['PB0002'])->assertSet('edit.name', 'PB0009');
        $page->call('applyEdit', true)->assertSet('edit', null);

        $recheck = app(DrawerRecheck::class);
        $state = $recheck->state($this->plan, $recheck->record($this->audit)->refresh());
        $this->assertSame('PB0009', $state['current']['1|B|4']['pb']);
        $this->assertSame('PB0002', $state['current']['1|B|3']['pb']);
    }

    public function test_a_new_cable_is_written_like_the_cables_of_the_plan(): void
    {
        $page = $this->page()->call('openEdit', 'PB0002')->call('editField', 'cable', 0, '__new')->call('setNewCable', 'cap', '48')->call('setNewCable', 'dest', 'pb0077')->call('createCable');

        $this->assertSame('NET X1 48 Fo - A 153-PM O PB0077 E', $page->get('edit')['segs'][0]['cable']);
        $page->call('setNewCable', 'cap', '999');
        $this->assertNull($page->get('edit')['newCable']);
    }

    public function test_the_recheck_and_the_drawer_after_works_are_offered_on_the_cabinet_page_and_its_control_point(): void
    {
        $this->audit->forceFill(['mode' => AuditMode::Optical, 'sro' => 'SRO-1'])->save();
        $drawer = fn (): array => app(AuditSnapshot::class)->for($this->audit->refresh(), $this->technician)['audit']['drawer'];

        Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])
            ->assertSee(__('audit.toolbar.drawer_recheck'))->assertDontSee(__('audit.toolbar.drawer_after_works'));
        $this->assertSame(route('audits.drawer', ['cabinet' => 'PM153'], false), $drawer()['recheck_url']);
        $this->assertNull($drawer()['after_works_url']);
        $this->actingAs($this->technician)->get(route('audits.files', ['cabinet' => 'PM153', 'kind' => 'drawer']))->assertNotFound();

        $this->page()->call('confirm', 'PB0001')->call('save');

        Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertSee(__('audit.toolbar.drawer_after_works'));
        $this->assertSame(route('audits.files', ['cabinet' => 'PM153', 'kind' => 'drawer'], false), $drawer()['after_works_url']);
        $this->actingAs($this->technician)->get(route('audits.files', ['cabinet' => 'PM153', 'kind' => 'drawer']))->assertOk()->assertDownload('PM153 - Drawer plan after works.xlsx');
        $outsider = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->technician->agency_id]);
        $this->actingAs($outsider)->get(route('audits.files', ['cabinet' => 'PM153', 'kind' => 'drawer']))->assertNotFound();

        $this->audit->forceFill(['mode' => AuditMode::Full])->save();
        Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertDontSee(__('audit.toolbar.drawer_recheck'));
        $this->assertNull($drawer()['recheck_url']);
    }

    public function test_saving_writes_pb_recnok_and_cable_recnok_after_their_sheets_and_a_summary(): void
    {
        $this->page()->call('confirm', 'PB0001')->call('openEdit', 'PB0002')->call('pickTube', 0, 0, 6)->call('applyEdit')->call('save')->assertDispatched('toast');

        $record = app(DrawerRecheck::class)->record($this->audit)->refresh();
        $this->assertFalse($record->has_unsaved_changes);
        $this->assertSame('PM153 - Drawer plan after works.xlsx', $record->recnok_name);

        $book = IOFactory::createReader('Xlsx')->load(Storage::disk('local')->path($record->recnokFile->path));
        $this->assertSame(['PB', 'PB RECNOK', 'CABLE', 'CABLE RECNOK', 'POSITIONS PBO'], array_map(fn ($s) => $s->getTitle(), $book->getAllSheets()));
        $this->assertStringContainsString('T06 F01', (string) $book->getSheetByName('CABLE RECNOK')->getCell('D4')->getValue());
        $this->assertStringContainsString('T03 F01', (string) $book->getSheetByName('CABLE')->getCell('D4')->getValue());
        $this->assertSame('FFFF0000', $book->getSheetByName('CABLE RECNOK')->getCell('D4')->getStyle()->getFont()->getColor()->getARGB());
        $this->assertSame('FFFFFFFF', $book->getSheetByName('PB RECNOK')->getCell('E4')->getStyle()->getFill()->getStartColor()->getARGB());

        $summary = $book->getSheetByName('POSITIONS PBO');
        $this->assertSame('PB0001', $summary->getCell('A2')->getValue());
        $this->assertSame(__('audit.drawer.status.conform'), $summary->getCell('K2')->getValue());
        $this->assertSame(__('audit.drawer.status.modified'), $summary->getCell('K4')->getValue());
    }

    public function test_the_workbook_without_the_expected_sheets_is_reported_not_crashed(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('OTHER');
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->expectExceptionMessage('format');
        app(DrawerReader::class)->read($path);
    }

    public function test_a_pbo_named_question_marks_stays_to_check_until_it_is_renamed(): void
    {
        $this->plan->delete();
        $this->plan = $this->storePlan('???');
        $recheck = app(DrawerRecheck::class);
        $statuses = function () use ($recheck): array {
            $record = $recheck->record($this->audit)->refresh();
            $state = $recheck->state($this->plan, $record);

            return $recheck->statuses($state['drawers'], $state['original'], $state['current'], $record);
        };

        $page = $this->page()->call('overview')->assertSee(__('audit.drawer.errors.unnamed'))->assertSee(__('audit.drawer.badge.unchecked'))
            ->call('confirm', '???')->call('confirmAll');
        $this->assertSame(['???' => 'unchecked', 'PB0001' => 'conform'], $statuses());
        $this->assertNotContains('???', $recheck->record($this->audit)->refresh()->confirmed);

        $page->call('startGuide')->assertSet('guideIndex', 1)->assertDontSeeHtml('wire:click="guideConfirm"')->call('guideConfirm')->assertSet('guideIndex', 1);
        $this->assertSame('unchecked', $statuses()['???']);

        $page->call('openEdit', '???')->assertSet('edit.name', '')->call('editManualName', '???')->call('applyEdit')
            ->assertSet('error', __('audit.drawer.errors.unnamed'));
        $this->assertNotContains('???', $page->instance()->render(app(DrawerRecheck::class))->getData()['pbos']);

        $page->call('editManualName', 'PB0002')->call('applyEdit')->assertSet('edit', null);
        $this->assertSame(['???' => 'modified', 'PB0001' => 'conform', 'PB0002' => 'modified'], $statuses());
    }

    public function test_an_added_position_takes_the_place_after_the_last_one_and_fibres_are_set_from_and_to(): void
    {
        $page = $this->page()->call('openEdit', 'PB0002')->call('addSegment');
        $added = $page->get('edit')['segs'][1];

        $this->assertSame([1, 'B', 7, 8, self::CABLE], [$added['t'], $added['L'], $added['a'], $added['b'], $added['cable']]);
        $this->assertSame([0 => 0], $added['tubes']);
        $this->assertSame([0 => 7], $added['fibres']);

        $page->call('setFibreEnd', 0, 0, 12)->assertSet('edit.segs.0.fibres.0', 7)->assertSeeHtml('wire:change="setFibreEnd(0, 0, $event.target.value)"');
        $page->call('setFibre', 0, 0, 3)->assertSet('edit.segs.0.fibres.0', 3)->call('setFibreEnd', 0, 0, 25)->assertSet('edit.segs.0.fibres.0', 3);
    }

    public function test_an_unreadable_plan_shows_the_error_instead_of_the_recheck(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'not a workbook');
        $file = app(StoreUploadedFile::class)->handle(new UploadedFile($path, 'drawer.xlsx', null, null, true));
        $this->plan->update(['stored_file_id' => $file->id]);

        $this->actingAs($this->technician)->get(route('audits.drawer', ['cabinet' => 'PM153']))
            ->assertOk()->assertSee(__('audit.recheck.plan_error'))->assertDontSee('wire:click="save"', false);
    }

    public function test_leaving_with_unsaved_changes_asks_first(): void
    {
        $leave = 'wire:confirm="'.e(__('audit.recheck.unsaved')).'"';
        $page = $this->page()->call('overview')->assertDontSeeHtml($leave)->call('confirm', 'PB0002')->assertSeeHtml($leave);
        $page->call('leave')->assertRedirect(route('audits.show', ['cabinet' => 'PM153']));
    }

    public function test_permissions_and_a_validated_audit_are_enforced_on_the_server(): void
    {
        $url = route('audits.drawer', ['cabinet' => 'PM153']);
        $this->actingAs($this->technician)->get($url)->assertOk()->assertSee('drawer.xlsx');
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($url)->assertForbidden();

        $this->audit->update(['validated_at' => now(), 'validated_by' => $this->manager->id, 'validated_by_name' => 'Sam']);
        $this->page()->call('confirm', 'PB0001')->assertForbidden();
        $this->page($this->manager)->call('confirm', 'PB0001')->assertOk();
        $this->assertSame(1, StoredFile::query()->where('extension', 'xlsx')->count());
    }
}
