<?php

namespace Tests\Feature\Planning;

use App\Audit\OpenAudit;
use App\Enums\AbsenceType;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\WorkSites\WorkSiteEditor;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\Client;
use App\Models\RoleFeatureOverride;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\FeatureAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\SampleFiles;
use Tests\TestCase;
use ZipArchive;

class AuditPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    private User $first;

    private User $second;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PublicHolidaySeeder::class]);
        $client = Client::factory()->create(['name' => 'Audit Client']);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily, 'default_client_id' => $client->id]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
        [$this->first, $this->second, $this->third] = User::factory()->count(3)->withRole(Role::Technician)->create(['agency_id' => $this->agency->id])->all();
    }

    public function test_one_work_site_per_team_and_working_day(): void
    {
        // Thu 2026-05-07 → Tue 2026-05-12: Fri 8 May is a holiday, 9-10 is a weekend -> Thu 7 and Mon 11, Tue 12.
        $this->editor('audit:optical')
            ->set('daily.cabinetRef', 'PM319')->set('daily.date', '2026-05-07')->set('daily.endDate', '2026-05-12')
            ->set('daily.teams.0.lead', (string) $this->first->id)->set('daily.teams.0.partner', (string) $this->second->id)
            ->call('addTeam')->set('daily.teams.1.lead', (string) $this->third->id)
            ->call('save')->assertHasNoErrors()->assertSet('open', false);

        $this->assertSame(6, WorkSite::query()->count());
        $this->assertSame(['2026-05-07', '2026-05-11', '2026-05-12'], WorkSite::query()->orderBy('starts_on')->pluck('starts_on')->map->toDateString()->unique()->values()->all());

        $site = WorkSite::query()->where('crew_count', 2)->orderBy('starts_on')->firstOrFail();
        $this->assertSame(ProjectType::Audit, $site->project_type);
        $this->assertSame(AuditMode::Optical, $site->audit_mode);
        $this->assertSame('PM319', $site->cabinet_ref);
        $this->assertSame('Audit Client', $site->client?->name);
        $this->assertSame(__('tasks.default_labels.optical_audit'), Task::query()->where('user_id', $this->first->id)->firstOrFail()->label);
        $this->assertSame(9, Task::query()->count());
    }

    public function test_period_without_working_day_is_refused(): void
    {
        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM1')->set('daily.date', '2026-05-09')->set('daily.endDate', '2026-05-10')
            ->set('daily.teams.0.lead', (string) $this->first->id)
            ->call('save')->assertHasErrors('date');

        $this->assertDatabaseCount('work_sites', 0);
    }

    public function test_pm_and_first_lead_are_required(): void
    {
        $this->editor('audit')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->call('save')->assertHasErrors(['daily.cabinetRef', 'daily.teams.0.lead']);
    }

    public function test_absent_technician_blocks_the_whole_plan(): void
    {
        Absence::factory()->create(['user_id' => $this->second->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-05-12', 'ends_on' => '2026-05-12']);

        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM2')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-12')
            ->set('daily.teams.0.lead', (string) $this->first->id)->set('daily.teams.0.partner', (string) $this->second->id)
            ->call('save')->assertHasErrors('teams');

        $this->assertDatabaseCount('work_sites', 0);
    }

    public function test_known_pm_keeps_its_city_and_capitalisation(): void
    {
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit, 'cabinet_ref' => 'Pm319', 'city' => 'Dijon', 'client_id' => Client::factory()->create(['name' => 'Other'])->id, 'starts_on' => '2026-05-04', 'ends_on' => '2026-05-04']);

        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM319')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->set('daily.teams.0.lead', (string) $this->first->id)
            ->call('save')->assertHasNoErrors();

        $created = WorkSite::query()->whereDate('starts_on', '2026-05-11')->sole();
        $this->assertSame('Pm319', $created->cabinet_ref);
        $this->assertSame('Dijon', $created->city);
        $this->assertSame('Other', $created->client?->name);
    }

    public function test_editing_moves_one_day_and_can_extend_the_team(): void
    {
        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM3')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->set('daily.teams.0.lead', (string) $this->first->id)->call('save');
        $site = WorkSite::query()->sole();

        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor', $site->id)
            ->assertSet('daily.project', 'audit')->assertSet('daily.cabinetRef', 'PM3')
            ->set('daily.endDate', '2026-05-15')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(4, WorkSite::query()->count());
        $this->assertSame(4, Task::query()->where('user_id', $this->first->id)->count());
        $this->assertSame(2, $site->fresh()?->version);
    }

    public function test_missing_default_client_is_reported(): void
    {
        $this->agency->update(['default_client_id' => null]);

        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM9')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->set('daily.teams.0.lead', (string) $this->first->id)
            ->call('save')->assertHasErrors('cabinetRef');
    }

    public function test_the_audit_form_hands_its_documents_to_the_audit_of_the_cabinet(): void
    {
        Storage::fake('local');
        config(['audit.box_suffixes' => ['FT'], 'audit.synoptic.stop_prefixes' => []]);
        $pdf = Pdf::loadHTML('<p>BPE-0001-FT [CHAMBRE : 12]</p><p>BPE 144 FO</p><p>900-PB0002 [POTEAU : 7]</p>')->output();

        $this->editor('audit:optical')
            ->set('daily.cabinetRef', 'PM900')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->set('daily.teams.0.lead', (string) $this->first->id)
            ->assertSee(__('work_sites.audit_files.sro'))->assertSee(__('work_sites.audit_files.drawer_plan'))
            ->set('auditSro', 'SRO-1')
            ->set('synopticFile', UploadedFile::fake()->createWithContent('syno.pdf', $pdf))->assertSee(__('audit.configure.boxes_found', ['count' => 2, 'bpe' => 1, 'pbo' => 1, 'joint' => 0]))
            ->set('kmzFile', $this->kml())->assertSee(__('audit.configure.map_read', ['located' => 2, 'total' => 2]))->assertSee(__('audit.configure.cabinet_found'))
            ->set('drawerPlan', SampleFiles::xlsx('drawer.xlsx'))
            ->set('boxPlans', [SampleFiles::xlsx('BPE-0001-81201-FT.xlsx')])
            ->call('save')->assertHasNoErrors()->assertSet('open', false)->assertNotDispatched('toast', message: __('work_sites.audit_files.setup_later'));

        $audit = Audit::query()->where('cabinet_key', 'PM900')->firstOrFail();
        $this->assertSame('SRO-1', $audit->sro);
        $this->assertSame(AuditMode::Optical, $audit->mode);
        $this->assertSame('syno.pdf', $audit->synoptic_name);
        $this->assertSame('map.kmz', $audit->kmz_name);
        $this->assertSame('43.910000', $audit->cabinet_latitude);
        $this->assertSame(['900-PB0002', 'BPE-0001-FT'], $audit->boxes()->orderBy('name')->pluck('name')->all());
        $this->assertSame('43.9300000', $audit->boxes()->where('name', 'BPE-0001-FT')->value('latitude'));
        $this->assertSame(1, $audit->plans()->where('kind', AuditPlanKind::Drawer->value)->count());
        $this->assertSame(1, $audit->plans()->where('kind', AuditPlanKind::Box->value)->count());
        $this->assertSame($audit->id, WorkSite::query()->sole()->audit_id);
        $this->assertSame(0, WorkSite::query()->sole()->attachments()->count());
    }

    public function test_planning_a_plain_audit_without_documents_does_not_open_it(): void
    {
        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM901')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->set('daily.teams.0.lead', (string) $this->first->id)
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('audits', 0);
        $this->assertDatabaseCount('work_sites', 1);
    }

    public function test_audit_documents_are_refused_to_people_who_cannot_manage_audits(): void
    {
        RoleFeatureOverride::query()->create(['role_id' => \Spatie\Permission\Models\Role::findByName(Role::Supervisor->value)->id, 'feature' => Feature::Audits, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();

        $this->editor('audit')
            ->set('daily.cabinetRef', 'PM902')->set('daily.date', '2026-05-11')->set('daily.endDate', '2026-05-11')
            ->set('daily.teams.0.lead', (string) $this->first->id)
            ->assertDontSee(__('work_sites.audit_files.sro'))->assertDontSee(__('work_sites.audit_files.in_progress'))
            ->set('auditSro', 'SRO-X')
            ->call('save')->assertHasNoErrors()->assertDispatched('toast', message: __('work_sites.audit_files.setup_later'));

        $this->assertDatabaseCount('audits', 0);
        $this->assertDatabaseCount('work_sites', 1);
    }

    public function test_pm_in_progress_chips_replan_next_week_with_the_last_teams(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-13 10:00', 'Europe/Paris'));
        $audit = app(OpenAudit::class)->handle($this->supervisor, 'PM153');
        foreach (['BPE-1' => AuditBoxStatus::Ok, 'BPE-2' => null, 'BPE-3' => null] as $name => $status) {
            AuditBox::query()->create(['audit_id' => $audit->id, 'name' => $name, 'kind' => AuditBoxKind::Bpe, 'source' => AuditBoxSource::Synoptic, 'status' => $status]);
        }
        $validated = app(OpenAudit::class)->handle($this->supervisor, 'PM777');
        $validated->forceFill(['validated_at' => now(), 'validated_by_name' => 'Sam'])->save();
        $this->plannedAudit('Pm200', '2026-05-04', $this->third);
        $this->plannedAudit('Pm200', '2026-05-06', $this->first, $this->second);
        $this->plannedAudit('Pm200', '2026-05-06', $this->third);

        $editor = $this->editor('audit')
            ->assertSee('PM153')->assertSee('1/3')->assertSee('Pm200')->assertDontSee('PM777')
            ->call('pickPm', 'PM200')
            ->assertSet('daily.cabinetRef', 'Pm200')->assertSet('daily.date', '2026-05-18')->assertSet('daily.endDate', '2026-05-22')
            ->assertSet('daily.teams', [['lead' => (string) $this->first->id, 'partner' => (string) $this->second->id], ['lead' => (string) $this->third->id, 'partner' => '']]);

        $editor->call('save')->assertHasNoErrors();
        $this->assertSame(10, WorkSite::query()->whereDate('starts_on', '>=', '2026-05-18')->count());
        $this->editor('audit')->call('pickPm', 'PM999')->assertNotFound();
    }

    public function test_a_configured_pm_has_nothing_to_send_again_unless_asked(): void
    {
        $audit = app(OpenAudit::class)->handle($this->supervisor, 'PM153');
        $audit->update(['sro' => 'SRO-153']);

        $this->editor('audit')->call('pickPm', 'PM153')
            ->assertSee(__('work_sites.audit_files.nothing_to_send'))->assertSee('SRO-153')
            ->assertDontSee(__('work_sites.audit_files.drawer_plan'))->assertSee(__('work_sites.audit_files.map'))
            ->call('toggleAuditFiles')->assertSee(__('work_sites.audit_files.drawer_plan'))->assertSet('auditSro', 'SRO-153');
    }

    private function plannedAudit(string $pm, string $date, User $lead, ?User $partner = null): WorkSite
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit, 'cabinet_ref' => $pm, 'starts_on' => $date, 'ends_on' => $date, 'lead_technician_id' => $lead->id]);
        $site->members()->sync([$lead->id => ['role' => 'lead'], ...($partner ? [$partner->id => ['role' => 'partner']] : [])]);

        return $site;
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
            .'</Folder></Document></kml>');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('map.kmz', $content);
    }

    private function editor(string $project): Testable
    {
        return Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor')->set('daily.project', $project);
    }
}
