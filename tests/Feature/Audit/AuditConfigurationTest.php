<?php

namespace Tests\Feature\Audit;

use App\Audit\OpenAudit;
use App\Enums\AuditBoxSource;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\AuditSupport;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Audit\AuditConfigure;
use App\Livewire\Audit\AuditIndex;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\SampleFiles;
use Tests\TestCase;
use ZipArchive;

class AuditConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['audit.box_suffixes' => ['FT'], 'audit.synoptic.stop_prefixes' => []]);
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM900', 'lead_technician_id' => $this->technician->id]);
    }

    private function kml(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kmz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
            .'<Folder><name>POSITION_PM</name><Placemark><name>Cabinet</name><Point><coordinates>2.1400,43.9100</coordinates></Point></Placemark></Folder>'
            .'<Folder><name>BOITES</name>'
            .'<Placemark><name>BPE-0001-FT</name><Point><coordinates>2.1480,43.9300</coordinates></Point></Placemark>'
            .'<Placemark><name>900-PB0002</name><Point><coordinates>2.1500,43.9310</coordinates></Point></Placemark>'
            .'<Placemark><name>900-PB0007</name><Point><coordinates>2.1600,43.9400</coordinates></Point></Placemark>'
            .'<Placemark><name>BPE-0050-FT</name><Point><coordinates>2.1700,43.9500</coordinates></Point></Placemark>'
            .'</Folder></Document></kml>');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('map.kmz', $content);
    }

    private function plan(string $name): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'plan '.$name);

        return SampleFiles::xlsx($name, $spreadsheet);
    }

    public function test_configuring_creates_the_boxes_locates_them_and_stores_the_files(): void
    {
        Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])
            ->set('sro', 'SRO-1')->set('mode', 'optical')
            ->set('list', "BPE-0001-FT [CHAMBER : 12]\nBPE 144 FO\n900-PB0001 [POLE : 45]\nPBO size 1\n1 main street\n81000 Town")
            ->set('mapFile', $this->kml())->set('synopticFile', UploadedFile::fake()->createWithContent('syno.pdf', '%PDF-1.4 x'))
            ->set('drawerPlan', $this->plan('drawer.xlsx'))->set('boxPlans', [$this->plan('PBO-SRO-BPI-8109935-0001.xlsx'), $this->plan('BPE-0001-81201-FT.xlsx'), $this->plan('Random notes.xlsx')])
            ->call('save')->assertHasNoErrors()->assertDispatched('audit-configured');

        $audit = Audit::query()->where('cabinet_key', 'PM900')->firstOrFail();
        $this->assertSame('SRO-1', $audit->sro);
        $this->assertSame('optical', $audit->mode->value);
        $this->assertSame('43.910000', $audit->cabinet_latitude);
        $this->assertSame('syno.pdf', $audit->synoptic_name);
        $this->assertSame('map.kmz', $audit->kmz_name);

        $bpe = $audit->boxes()->where('name', 'BPE-0001-FT')->first();
        $this->assertSame(AuditSupport::Chamber, $bpe->support);
        $this->assertSame('12', $bpe->support_number);
        $this->assertSame('BPE 144 FO', $bpe->type);
        $this->assertSame(AuditBoxSource::Synoptic, $bpe->source);
        $this->assertSame('43.9300000', $bpe->latitude);
        $this->assertSame('1 main street, 81000 Town', $audit->boxes()->where('name', '900-PB0001')->first()->address);

        $this->assertSame(1, $audit->plans()->where('kind', AuditPlanKind::Drawer->value)->count());
        $this->assertSame(3, $audit->plans()->where('kind', AuditPlanKind::Box->value)->count());
        Storage::disk('local')->assertExists(StoredFile::query()->findOrFail($audit->synoptic_file_id)->path);
    }

    public function test_a_box_on_both_the_plans_and_the_map_but_not_in_the_synoptic_is_added_and_the_others_are_reported(): void
    {
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM900');

        $component = Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])
            ->set('sro', 'SRO-1')->set('list', 'BPE-0001-FT')->set('mapFile', $this->kml())
            ->set('boxPlans', [$this->plan('PBO-SRO-BPI-8109935-0002.xlsx'), $this->plan('PBO-SRO-BPI-8109935-0009.xlsx'), $this->plan('Random notes.xlsx')])
            ->call('save')->assertHasNoErrors();

        $added = $audit->boxes()->where('name', '900-PB0002')->first();
        $this->assertSame(AuditBoxSource::CrossReference, $added->source);
        $this->assertSame('43.9310000', $added->latitude);
        $this->assertNull($audit->boxes()->where('name', '900-PB0009')->first());
        $this->assertNull($audit->boxes()->where('name', '900-PB0007')->first());
        $this->assertEqualsCanonicalizing(['900-PB0009', '900-PB0007', 'BPE-0050-FT'], $component->get('toVerify'));
        $this->assertSame(['PBO-SRO-BPI-8109935-0009.xlsx', 'Random notes.xlsx'], $component->get('unmatchedPlans'));
    }

    public function test_an_existing_box_is_updated_without_overwriting_its_support_and_a_removed_one_comes_back(): void
    {
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM900');
        AuditBox::query()->create(['audit_id' => $audit->id, 'name' => 'BPE-0001-FT', 'kind' => 'bpe', 'support' => AuditSupport::Pole, 'support_number' => 'P1', 'source' => AuditBoxSource::Technician]);
        AuditBox::query()->create(['audit_id' => $audit->id, 'name' => 'BPE-0002-FT', 'kind' => 'bpe', 'source' => AuditBoxSource::Technician, 'removed_at' => now()]);

        Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])->set('sro', 'SRO-1')
            ->set('list', "BPE-0001-FT [CHAMBER : 9]\nBPE 144 FO\nBPE-0002-FT [CHAMBER : 3]")->call('save')->assertHasNoErrors();

        $first = $audit->boxes()->where('name', 'BPE-0001-FT')->first();
        $this->assertSame(AuditSupport::Pole, $first->support);
        $this->assertSame('P1', $first->support_number);
        $this->assertSame('BPE 144 FO', $first->type);
        $second = $audit->boxes()->where('name', 'BPE-0002-FT')->first();
        $this->assertFalse($second->isRemoved());
        $this->assertSame(AuditSupport::Chamber, $second->support);
    }

    public function test_a_plan_with_the_same_name_replaces_the_previous_one_and_files_are_validated(): void
    {
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM900');
        $configure = fn (array $plans) => Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])->set('sro', 'SRO-1')->set('boxPlans', $plans)->call('save');

        $configure([$this->plan('BPE-0001-FT.xlsx')])->assertHasNoErrors();
        $configure([$this->plan('bpe-0001-ft.XLSX')])->assertHasNoErrors();
        $this->assertSame(1, $audit->plans()->count());

        $configure([UploadedFile::fake()->create('virus.php', 5)])->assertHasErrors('boxPlans.0');
        Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])->set('mapFile', UploadedFile::fake()->createWithContent('map.kmz', 'not a map'))->call('save')->assertHasErrors('mapFile');
        Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])->set('mapFile', UploadedFile::fake()->create('map.exe', 5))->call('save')->assertHasErrors('mapFile');
        $this->assertSame(1, $audit->plans()->count());
    }

    public function test_the_files_are_downloaded_only_by_the_people_working_on_the_audit(): void
    {
        Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])->set('sro', 'SRO-1')
            ->set('synopticFile', UploadedFile::fake()->createWithContent('syno.pdf', '%PDF-1.4 x'))->set('boxPlans', [$this->plan('BPE-0001-FT.xlsx')])->call('save');
        $audit = Audit::query()->where('cabinet_key', 'PM900')->firstOrFail();
        $plan = $audit->plans()->first();

        $this->actingAs($this->technician)->get(route('audits.files', ['cabinet' => 'PM900', 'kind' => 'synoptic']))->assertOk()->assertDownload('syno.pdf');
        $this->actingAs($this->technician)->get(route('audits.files', ['cabinet' => 'PM900', 'kind' => 'plan', 'plan' => $plan->id]))->assertOk()->assertDownload('BPE-0001-FT.xlsx');
        $this->actingAs($this->technician)->get(route('audits.files', ['cabinet' => 'PM900', 'kind' => 'map']))->assertNotFound();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->technician->agency_id]))->get(route('audits.files', ['cabinet' => 'PM900', 'kind' => 'synoptic']))->assertNotFound();
        $this->actingAs($this->manager)->get(route('audits.files', ['cabinet' => 'PM900', 'kind' => 'plan', 'plan' => 999999]))->assertNotFound();
    }

    public function test_the_add_cabinet_window_opens_for_managers_only(): void
    {
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'optical'])
            ->assertSee(__('audit.add_pm'))->assertSee(__('audit.field.chip_synced'))->assertDontSee(__('audit.new_pm.hint'))
            ->call('askNewPm')->assertSee(__('audit.new_pm.title', ['mode' => AuditMode::Optical->label()]))->assertSee(__('audit.new_pm.hint'))
            ->call('cancelNewPm')->assertDontSee(__('audit.new_pm.hint'));
        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'optical'])
            ->assertDontSee(__('audit.add_pm'))->assertSee(__('audit.field.chip_synced'))->call('askNewPm')->assertForbidden();
    }

    public function test_a_manager_adds_a_cabinet_from_its_number(): void
    {
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'optical'])->set('newPm', '153')->call('openPm')->assertRedirect(route('audits.show', ['cabinet' => 'PM153', 'mode' => 'optical']));
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'full'])->set('newPm', 'pm 27b')->call('openPm')->assertRedirect(route('audits.show', ['cabinet' => 'PM27B', 'mode' => 'full']));
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'full'])->set('newPm', '')->call('openPm')->assertHasErrors('newPm');
        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'full'])->set('newPm', '153')->call('openPm')->assertForbidden();

        app(OpenAudit::class)->handle($this->manager, 'PM153');
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'full'])->set('newPm', '153')->call('openPm')->assertDispatched('toast', message: __('audit.pm_exists'));
    }

    public function test_the_boxes_of_the_synoptic_pdf_are_read_and_the_pasted_list_wins_on_conflicts(): void
    {
        $pdf = Pdf::loadHTML('<p>BPE-0001-FT [CHAMBRE : 12]</p><p>BPE 144 FO</p><p>900-PB0002 [POTEAU : 7]</p>')->output();

        $component = Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])
            ->set('synopticFile', UploadedFile::fake()->createWithContent('syno.pdf', $pdf))->assertSee(__('audit.configure.boxes_found', ['count' => 2, 'bpe' => 1, 'pbo' => 1, 'joint' => 0]))
            ->set('sro', 'SRO-1')->set('list', 'BPE-0001-FT [POTEAU : 99]')->call('save')->assertHasNoErrors();

        $audit = Audit::query()->where('cabinet_key', 'PM900')->firstOrFail();
        $this->assertSame(['900-PB0002', 'BPE-0001-FT'], $audit->boxes()->orderBy('name')->pluck('name')->all());
        $this->assertSame('99', $audit->boxes()->where('name', 'BPE-0001-FT')->first()->support_number);
        $this->assertSame(AuditSupport::Pole, $audit->boxes()->where('name', 'BPE-0001-FT')->first()->support);
        $this->assertNotNull($component);

        Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM900'])
            ->set('synopticFile', UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 scanned'))->assertSee(__('audit.configure.nothing_found'));
    }
}
