<?php

namespace Tests\Feature\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Audit\Recheck\BoxRecheck;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\AuditSpliceType;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Livewire\Audit\BoxRecheckPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
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

class BoxRecheckTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Audit $audit;

    private AuditBox $box;

    private AuditPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['audit.box_suffixes' => ['FT']]);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id, 'name' => 'Sam']);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice']);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->audit->update(['mode' => AuditMode::Optical]);
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);
        $this->actingAs($this->technician);
        app(AuditOperations::class)->apply($this->technician, $this->audit->refresh(), 'o_box', ['op' => 'box_add', 'name' => 'BPE-0001-FT', 'support' => 'chamber']);
        $this->box = $this->audit->boxes()->firstOrFail();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('BOITE');
        $sheet->setCellValue('A1', 'PLAN');
        foreach (['CASSETTE', 'CABLE A', 'TUBE', 'FIBRE A', 'TYPE CONNEXION', 'FIBRE B', 'TUBE B', 'CABLE B'] as $i => $title) {
            $sheet->setCellValue([$i + 1, 3], $title);
        }
        foreach ([4 => ['01 A', 'T01', 'F01'], 5 => ['01 A', 'T01', 'F02'], 6 => ['02 B', 'T02', 'F01']] as $row => [$cassette, $tube, $fibre]) {
            $sheet->setCellValue("A$row", $cassette)->setCellValue("B$row", 'NET X1 144 Fo - A 153-PM O PB0001 E')->setCellValue("C$row", $tube)->setCellValue("D$row", $fibre);
        }
        $book->createSheet()->setTitle('OTHER');
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = app(StoreUploadedFile::class)->handle(new UploadedFile($path, 'BPE-0001-81201-FT.xlsx', null, null, true));
        $this->plan = AuditPlan::query()->create(['audit_id' => $this->audit->id, 'kind' => AuditPlanKind::Box, 'name' => 'BPE-0001-81201-FT.xlsx', 'stored_file_id' => $file->id]);
    }

    public function test_the_plan_of_a_box_is_found_by_its_name_and_its_lines_are_read(): void
    {
        $recheck = app(BoxRecheck::class);

        $this->assertSame($this->plan->id, $recheck->planFor($this->box, $this->audit)->id);
        $lines = $recheck->lines($this->plan, $recheck->record($this->box));

        $this->assertCount(3, $lines);
        $this->assertSame('waiting', $lines[0]['type']);
        $this->assertSame('F02', $lines[1]['fibre_a']);
        $this->assertSame(['01', '02'], array_map(fn (array $n) => $n['default'], array_values($recheck->cassetteNumbers($this->plan, $recheck->record($this->box)))));
        $this->assertSame('PB0001', BoxRecheck::compactCable('NET X1 144 Fo - A 153-PM O PB0001 E'));
    }

    public function test_changes_are_kept_on_the_server_and_straight_copies_fibre_and_tube(): void
    {
        Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->call('setType', 0, 'pass')
            ->call('setColour', 1, 'fibre_b', 5)
            ->call('straight', '02 B')
            ->call('setColour', 0, 'fibre_b', 13)
            ->assertHasNoErrors();

        $recheck = app(BoxRecheck::class);
        $lines = $recheck->lines($this->plan, $recheck->record($this->box));

        $this->assertSame('pass', $lines[0]['type']);
        $this->assertSame(['type' => 'pass'], $recheck->record($this->box)->draft['changes'][0]);
        $this->assertTrue($lines[0]['changed']);
        $this->assertSame('F05', $lines[1]['fibre_b']);
        $this->assertSame(['splice', 'F01', 'T02'], [$lines[2]['type'], $lines[2]['fibre_b'], $lines[2]['tube_b']]);
        $this->assertSame('', $lines[0]['fibre_b']);
        $this->assertTrue($recheck->record($this->box)->has_unsaved_changes);
    }

    public function test_saving_writes_a_recnok_sheet_after_boite_and_leaves_the_original_untouched(): void
    {
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->call('setType', 0, 'splice')->call('setColour', 0, 'fibre_b', 1)->call('setColour', 0, 'tube_b', 5)->call('setCassetteNumber', '01 A', '7');
        $page->call('save')->assertDispatched('toast');

        $record = app(BoxRecheck::class)->record($this->box);
        $this->assertSame('BPE-0001-FT_RECNOK.xlsx', $record->recnok_name);
        $this->assertFalse($record->has_unsaved_changes);

        $book = IOFactory::createReader('Xlsx')->load(Storage::disk('local')->path($record->recnokFile->path));
        $this->assertSame(['BOITE', 'RECNOK', 'OTHER'], array_map(fn ($s) => $s->getTitle(), $book->getAllSheets()));
        $recnok = $book->getSheetByName('RECNOK');
        $this->assertSame('EPISSURE', $recnok->getCell('E4')->getValue());
        $this->assertSame(['ATTENTE', 'ATTENTE'], [$recnok->getCell('E5')->getValue(), $recnok->getCell('E6')->getValue()]);
        $this->assertSame('F01', $recnok->getCell('F4')->getValue());
        $this->assertSame('FFFF0000', $recnok->getCell('F4')->getStyle()->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFF0000', $recnok->getCell('E4')->getStyle()->getFont()->getColor()->getARGB());
        $this->assertSame('07', $recnok->getCell('I4')->getValue());
        $this->assertSame('N° CASSETTE', $recnok->getCell('I3')->getValue());
        $this->assertNull($book->getSheetByName('BOITE')->getCell('E4')->getValue());
    }

    public function test_splice_types_are_shown_in_the_language_of_the_screen_and_the_plan_words_are_never_accepted(): void
    {
        foreach (['pt', 'en_GB'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id]);

            foreach (AuditSpliceType::cases() as $type) {
                $page->assertSeeHtml('>'.e($type->label()).'</button>')->assertSeeHtml("setType(0, '{$type->value}')");
            }

            $page->assertDontSee('ATTENTE')->assertDontSee('EPISSURE')->assertDontSeeHtml('>PASSAGE<');
        }

        Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->call('setType', 0, 'PASSAGE')->call('setCassetteType', '01 A', 'EPISSURE');
        $this->assertNull(app(BoxRecheck::class)->record($this->box)->draft);
    }

    public function test_the_plan_words_are_read_and_written_back_as_the_client_writes_them(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('BOITE');
        foreach (['CASSETTE', 'CABLE A', 'TUBE', 'FIBRE A', 'TYPE CONNEXION', 'FIBRE B', 'TUBE B', 'CABLE B'] as $i => $title) {
            $sheet->setCellValue([$i + 1, 1], $title);
        }
        foreach ([2 => 'epissure', 3 => 'PASSAGE', 4 => 'Attente', 5 => 'LOVAGE', 6 => ''] as $row => $word) {
            $sheet->setCellValue("A$row", '01 A')->setCellValue("C$row", 'T01')->setCellValue("D$row", 'F0'.$row)->setCellValue("E$row", $word);
        }
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->plan->update(['stored_file_id' => app(StoreUploadedFile::class)->handle(new UploadedFile($path, 'BPE-0001-81201-FT.xlsx', null, null, true))->id]);
        $recheck = app(BoxRecheck::class);

        $this->assertSame(['splice', 'pass', 'waiting', '', 'waiting'], array_column($recheck->lines($this->plan->refresh(), $recheck->record($this->box)), 'type'));

        Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->call('setType', 4, 'pass')->call('save');
        $recnok = IOFactory::createReader('Xlsx')->load(Storage::disk('local')->path($recheck->record($this->box)->recnokFile->path))->getSheetByName('RECNOK');

        $this->assertSame(['EPISSURE', 'PASSAGE', 'ATTENTE', 'LOVAGE', 'PASSAGE'], array_map(fn (int $row) => $recnok->getCell("E$row")->getValue(), range(2, 6)));
    }

    public function test_conform_gives_an_exact_copy_and_a_second_save_replaces_the_file(): void
    {
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id]);
        $page->call('conform');
        $record = app(BoxRecheck::class)->record($this->box);
        $first = $record->recnok_file_id;

        $this->assertTrue($record->is_conform);
        $page->assertSeeHtml('<b>BPE-0001-FT_RECNOK.xlsx</b>');
        $this->assertNull(IOFactory::createReader('Xlsx')->load(Storage::disk('local')->path($record->recnokFile->path))->getSheetByName('RECNOK')->getCell('E4')->getValue());

        $page->call('setType', 0, 'pass')->call('save');
        $record->refresh();

        $this->assertFalse($record->is_conform);
        $this->assertNotSame($first, $record->recnok_file_id);
        $this->assertSame(1, StoredFile::query()->where('extension', 'xlsx')->where('id', '!=', $this->plan->stored_file_id)->count());
    }

    public function test_a_non_conform_box_is_marked_and_the_snapshot_points_to_the_recheck(): void
    {
        Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])->call('toggleNonConform');
        $this->assertSame('nonconforming', $this->box->refresh()->recheck_state);

        $snapshot = $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']), [EnsureCurrentSyncProtocol::HEADER => (string) EnsureCurrentSyncProtocol::VERSION])->json('boxes.0');
        $this->assertSame(route('audits.recheck', ['cabinet' => 'PM153', 'box' => $this->box->id], false), $snapshot['recheck']['url']);
        $this->assertSame('nonconforming', $snapshot['recheck_state']);
    }

    public function test_only_people_working_on_the_audit_open_it_and_a_validated_audit_is_read_only_for_technicians(): void
    {
        $url = route('audits.recheck', ['cabinet' => 'PM153', 'box' => $this->box->id]);
        $this->actingAs($this->technician)->get($url)->assertOk()->assertSee('BPE-0001-81201-FT.xlsx');
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($url)->assertForbidden();

        $this->audit->update(['validated_at' => now(), 'validated_by' => $this->manager->id, 'validated_by_name' => 'Sam']);
        Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])->call('setType', 0, 'pass')->assertForbidden();
    }

    public function test_a_validated_audit_is_read_only_for_managers_outside_modify_mode(): void
    {
        $this->audit->update(['validated_at' => now(), 'validated_by' => $this->manager->id, 'validated_by_name' => 'Sam']);

        Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->assertDontSeeHtml('wire:click="conform"')->assertDontSeeHtml('wire:click="startEditing"')
            ->call('startEditing')->assertForbidden();
        $managerPage = fn () => Livewire::actingAs($this->manager)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id]);
        $managerPage()->assertSeeHtml('wire:click="startEditing"')->assertDontSeeHtml('wire:click="save"')->call('setType', 0, 'pass')->assertForbidden();
        $managerPage()->call('conform')->assertForbidden();

        $page = $managerPage()->call('startEditing')->assertSet('editing', true)->assertSeeHtml('wire:click="save"')->call('setType', 0, 'pass')->assertOk();
        $page->call('stopEditing')->call('setType', 1, 'pass')->assertForbidden();

        Livewire::actingAs($this->manager)->withQueryParams(['edit' => 1])->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->assertSet('editing', true)->call('setType', 1, 'pass')->assertOk();
        Livewire::actingAs($this->technician)->withQueryParams(['edit' => 1])->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->assertSet('editing', false)->call('save')->assertForbidden();
    }

    public function test_the_verdict_has_three_states_with_their_help_line(): void
    {
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->assertSee(__('audit.recheck.conform_hint'))->assertDontSee(__('audit.recheck.conform_state'))->assertDontSee(__('audit.recheck.nonconform_state'));

        $page->call('toggleNonConform')->assertSee(__('audit.recheck.nonconform_state'))->assertDontSee(__('audit.recheck.conform_hint'))
            ->assertSeeHtml('recheck__verdict-button is-bad is-on')->assertSeeHtml('recheck__verdict-button is-ok is-dim');
        $page->call('toggleNonConform')->assertSee(__('audit.recheck.conform_hint'))->assertDontSee(__('audit.recheck.nonconform_state'));
        $this->assertNull($this->box->refresh()->recheck_state);

        $page->call('conform')->assertDispatched('toast')->assertSee(__('audit.recheck.conform_state'))
            ->assertSeeHtml('recheck__verdict-button is-ok is-on')->assertSeeHtml('recheck__verdict-button is-bad is-dim');
    }

    public function test_the_box_conform_button_goes_away_after_a_line_change_and_comes_back_when_every_cassette_is_conform(): void
    {
        $conformButton = '✓ '.__('audit.recheck.conform').'</button>';
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->assertSeeHtml($conformButton)->assertSeeHtml(e(__('audit.recheck.conform_question')).'"');

        $page->call('setType', 0, 'pass')->assertDontSeeHtml($conformButton)
            ->assertSeeHtml('wire:confirm="'.e(__('audit.recheck.conform_cassette_question')).'"');

        $page->call('conformCassette', '01 A')->assertSeeHtml($conformButton)->assertSeeHtml(e(__('audit.recheck.conform_lose')))
            ->assertDontSeeHtml('wire:confirm="'.e(__('audit.recheck.conform_cassette_question')).'"');
        $page->call('conformCassette', '02 B')->assertSeeHtml('recheck__verdict-button is-ok is-on')->assertSee(__('audit.recheck.conform_state'));

        $page->call('conform')->assertNotDispatched('toast');
        $this->assertNull(app(BoxRecheck::class)->record($this->box)->recnok_file_id);
    }

    public function test_save_is_disabled_until_something_changes_and_leaving_with_changes_asks_first(): void
    {
        $leave = 'wire:confirm="'.e(__('audit.recheck.unsaved')).'"';
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->assertSeeHtml('wire:click="save" disabled')->assertDontSeeHtml($leave);

        $page->call('setType', 0, 'splice')->assertDontSeeHtml('wire:click="save" disabled')->assertSeeHtml($leave);
        $page->call('save')->assertDispatched('toast', message: __('audit.recheck.saved', ['name' => 'BPE-0001-FT_RECNOK.xlsx']))
            ->assertSeeHtml('wire:click="save" disabled')->assertDontSeeHtml($leave);
        $page->call('leave')->assertRedirect(route('audits.box', ['cabinet' => 'PM153', 'name' => 'BPE-0001-FT']));
    }

    public function test_the_todo_filter_unfolds_every_cassette_and_hides_the_ones_with_nothing_left(): void
    {
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->call('toggleCassette', '01 A')->assertSee('▸ 01 A');

        $page->call('setFilter', 'todo')->assertSee('▾ 01 A')->assertSee(__('audit.recheck.todo').' (3)');
        $page->call('setCassetteType', '02 B', 'splice')->assertDontSee('02 B');
        $page->call('setCassetteType', '01 A', 'pass')->assertSee(__('audit.recheck.nothing'));
        $page->call('setFilter', 'all')->assertSee('▸ 01 A')->assertSee('F?')->assertSee('T?');
    }

    public function test_the_extra_cable_b_panel_shows_the_current_cable_and_stays_open_after_applying(): void
    {
        $cable = 'NET X1 144 Fo - A 153-PM O PB0001 E';
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id])
            ->call('setCassetteCable', '01 A', 'cable_b', $cable)
            ->call('toggleExtraCable', '01 A')->assertSee(__('audit.recheck.extra_title'))->assertSeeHtml('<small>PB0001</small>');

        $page->call('setExtraCable', '01 A', '__manual')->set('manualCable', 'SECOND CABLE')->call('saveManualCable')
            ->call('toggleAllExtraLines', '01 A')->assertSet('extraCable.01 A.lines', [0, 1])
            ->call('toggleExtraLine', '01 A', 0)->call('applyExtraCable', '01 A')
            ->assertSet('extraCable.01 A', ['cable' => 'SECOND CABLE', 'lines' => []])->assertSee(__('audit.recheck.extra_title'));

        $recheck = app(BoxRecheck::class);
        $lines = $recheck->lines($this->plan, $recheck->record($this->box));
        $this->assertSame([$cable, 'SECOND CABLE'], [$lines[0]['cable_b'], $lines[1]['cable_b']]);
        $this->assertContains('SECOND CABLE', $recheck->record($this->box)->draft['manual_cables']);
    }

    public function test_an_unreadable_plan_shows_the_error_instead_of_the_recheck(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'not a workbook');
        $file = app(StoreUploadedFile::class)->handle(new UploadedFile($path, 'BPE-0001-81201-FT.xlsx', null, null, true));
        $this->plan->update(['stored_file_id' => $file->id]);

        $this->actingAs($this->technician)->get(route('audits.recheck', ['cabinet' => 'PM153', 'box' => $this->box->id]))
            ->assertOk()->assertSee(__('audit.recheck.plan_error'))->assertDontSee('wire:click="save"', false);
    }

    public function test_cables_can_only_be_picked_from_the_lists_offered(): void
    {
        $page = Livewire::actingAs($this->technician)->test(BoxRecheckPage::class, ['cabinet' => 'PM153', 'box' => $this->box->id]);
        $page->call('setCable', 0, 'cable_b', 'made up')->call('setCable', 0, 'cable_b', 'NET X1 144 Fo - A 153-PM O PB0001 E');
        $recheck = app(BoxRecheck::class);

        $this->assertSame('NET X1 144 Fo - A 153-PM O PB0001 E', $recheck->lines($this->plan, $recheck->record($this->box))[0]['cable_b']);

        $page->call('setCable', 1, 'cable_b', '__manual')->set('manualCable', 'MY CABLE 12Fo')->call('saveManualCable');
        $this->assertSame('MY CABLE 12Fo', $recheck->lines($this->plan, $recheck->record($this->box))[1]['cable_b']);
    }
}
