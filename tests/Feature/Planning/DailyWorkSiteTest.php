<?php

namespace Tests\Feature\Planning;

use App\Enums\AbsenceType;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\WorkSites\WorkSiteEditor;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\TaskDetail;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\SampleFiles;
use Tests\TestCase;

class DailyWorkSiteTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    private User $lead;

    private User $partner;

    private User $boxTechnician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);

        $client = Client::factory()->create(['name' => 'Default Client']);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily, 'default_client_id' => $client->id]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
        [$this->lead, $this->partner, $this->boxTechnician] = User::factory()->count(3)->withRole(Role::Technician)->create(['agency_id' => $this->agency->id])->all();
    }

    public function test_one_day_work_site_generates_one_task_per_distinct_technician(): void
    {
        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)
            ->set('daily.partners', [(string) $this->partner->id])
            ->set('daily.city', 'Dijon')->set('daily.cabinetRef', 'PM042')
            ->set('daily.boxCount', '2')
            ->set('daily.boxes.0.reference', 'BPE-1')->set('daily.boxes.0.technicianId', (string) $this->boxTechnician->id)
            ->set('daily.boxes.1.reference', 'BPE-2')->set('daily.boxes.1.technicianId', (string) $this->lead->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false);

        $site = WorkSite::query()->with('boxes', 'client')->sole();
        $this->assertSame('Default Client', $site->client?->name);
        $this->assertSame(3, $site->crew_count);
        $this->assertSame('confirmed', $site->status->value);
        $this->assertSame('2026-11-03', $site->ends_on->toDateString());
        $this->assertCount(2, $site->boxes);
        $this->assertSame(3, Task::query()->where('work_site_id', $site->id)->count());
        $this->assertSame('SWAP', Task::query()->where('user_id', $this->lead->id)->sole()->label);

        $detail = app(TaskDetail::class);
        $this->assertSame('Default Client — Dijon · Cabinet : PM042 · Box : BPE-2', $detail->build($site, $this->lead->id));
        $this->assertSame('Default Client — Dijon · Cabinet : PM042 · Box : BPE-1', $detail->build($site, $this->boxTechnician->id));
        $this->assertSame('Default Client — Dijon · Cabinet : PM042', $detail->build($site, $this->partner->id));
    }

    public function test_required_fields_are_checked(): void
    {
        $this->editor()->set('daily.date', '2026-11-03')->call('save')
            ->assertHasErrors(['daily.lead', 'daily.city']);

        $this->assertDatabaseCount('work_sites', 0);
    }

    public function test_boxes_without_reference_or_technician_are_dropped(): void
    {
        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)->set('daily.city', 'Dijon')
            ->set('daily.boxCount', '3')
            ->set('daily.boxes.0.reference', 'OK')->set('daily.boxes.0.technicianId', (string) $this->lead->id)
            ->set('daily.boxes.1.reference', 'NO-TECH')
            ->set('daily.boxes.2.technicianId', (string) $this->lead->id)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['OK'], WorkSite::query()->sole()->boxes->pluck('reference')->all());
    }

    public function test_absent_technicians_cannot_be_assigned(): void
    {
        Absence::factory()->create(['user_id' => $this->partner->id, 'type' => AbsenceType::Sick, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-04']);

        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)
            ->set('daily.partners', [(string) $this->partner->id])->set('daily.city', 'Dijon')
            ->call('save')->assertHasErrors('lead');

        $this->assertDatabaseCount('work_sites', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_reassigning_a_busy_technician_asks_first_and_replaces_the_old_task(): void
    {
        $otherSite = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']);
        $old = Task::factory()->create(['user_id' => $this->lead->id, 'date' => '2026-11-03', 'work_site_id' => $otherSite->id]);
        $manual = Task::factory()->create(['user_id' => $this->partner->id, 'date' => '2026-11-03', 'work_site_id' => null]);

        $component = $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)
            ->set('daily.partners', [(string) $this->partner->id])->set('daily.city', 'Dijon')
            ->call('save')->assertDispatched('confirm-action');

        $this->assertModelExists($old);

        $component->call('confirmSave');

        $this->assertModelMissing($old);
        $this->assertModelMissing($manual);
        $this->assertSame(2, Task::query()->whereDate('date', '2026-11-03')->count());
        $this->assertModelExists($otherSite);
    }

    public function test_editing_updates_tasks_and_removes_dropped_technicians(): void
    {
        $this->editor()->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)
            ->set('daily.partners', [(string) $this->partner->id])->set('daily.city', 'Dijon')->call('save');
        $site = WorkSite::query()->sole();

        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor', $site->id)
            ->assertSet('daily.lead', (string) $this->lead->id)
            ->assertSet('daily.partners', [(string) $this->partner->id])
            ->set('daily.partners', [])->set('daily.date', '2026-11-04')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, $site->fresh()?->crew_count);
        $this->assertSame(['2026-11-04'], Task::query()->pluck('date')->map->toDateString()->all());
        $this->assertSame(2, $site->fresh()?->version);
    }

    public function test_attachments_are_validated_stored_once_and_replaced_only_by_a_new_file(): void
    {
        $component = $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)->set('daily.city', 'Dijon')
            ->set('kmzFile', SampleFiles::kmz('plan.kmz', '<Placemark><name>first</name></Placemark>'))
            ->set('synopticFile', UploadedFile::fake()->createWithContent('syno.pdf', "%PDF-1.4\nfirst"))
            ->call('save')->assertHasNoErrors();

        $site = WorkSite::query()->sole();
        $this->assertCount(2, $site->attachments);

        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor', $site->id)
            ->set('kmzFile', SampleFiles::kmz('plan2.kmz', '<Placemark><name>second</name></Placemark>'))
            ->call('save')->assertHasNoErrors();

        $names = $site->attachments()->pluck('original_name')->sort()->values()->all();
        $this->assertSame(['plan2.kmz', 'syno.pdf'], $names);
        unset($component);
    }

    public function test_dangerous_attachments_are_refused(): void
    {
        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)->set('daily.city', 'Dijon')
            ->set('synopticFile', UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'))
            ->set('kmzFile', UploadedFile::fake()->createWithContent('plan.pdf', 'x'))
            ->call('save')->assertHasErrors(['synopticFile', 'kmzFile']);

        $this->assertDatabaseCount('work_sites', 0);
    }

    public function test_attachments_over_15_mb_are_refused(): void
    {
        $limit = 15 * 1024;

        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)->set('daily.city', 'Dijon')
            ->set('kmzFile', UploadedFile::fake()->create('plan.kmz', $limit + 1))
            ->set('synopticFile', UploadedFile::fake()->create('syno.txt', $limit + 1))
            ->call('save')->assertHasErrors(['kmzFile' => 'max', 'synopticFile' => 'max']);

        $this->assertDatabaseCount('work_sites', 0);

        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)->set('daily.city', 'Dijon')
            ->set('kmzFile', SampleFiles::kmz('plan.kmz', kilobytes: $limit - 4))
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['plan.kmz'], WorkSite::query()->sole()->attachments()->pluck('original_name')->all());
    }

    public function test_attachments_are_served_to_managers_and_the_crew_only(): void
    {
        $this->editor()
            ->set('daily.date', '2026-11-03')->set('daily.lead', (string) $this->lead->id)->set('daily.city', 'Dijon')
            ->set('synopticFile', UploadedFile::fake()->createWithContent('syno.pdf', "%PDF-1.4\nbody"))
            ->set('kmzFile', SampleFiles::kmz('plan.kmz'))
            ->call('save')->assertHasNoErrors();

        $kmz = WorkSite::query()->sole()->attachments()->where('type', 'kmz')->sole();
        $pdf = WorkSite::query()->sole()->attachments()->where('type', 'synoptic')->sole();

        $this->actingAs($this->supervisor)->get(route('work-site-attachments.show', $kmz))->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->supervisor)->get(route('work-site-attachments.show', $pdf))->assertOk()->assertHeader('Content-Disposition', 'inline; filename=syno.pdf');
        $this->actingAs($this->lead)->get(route('work-site-attachments.show', $kmz))->assertOk();
        $this->actingAs($this->partner)->get(route('work-site-attachments.show', $kmz))->assertNotFound();
    }

    public function test_technicians_of_other_agencies_are_not_offered(): void
    {
        User::factory()->withRole(Role::Technician)->create(['name' => 'Outsider', 'agency_id' => Agency::factory()->create()->id]);
        User::factory()->withRole(Role::Technician)->departed()->create(['name' => 'Gone', 'agency_id' => $this->agency->id]);

        $this->editor()->assertSee($this->lead->name)->assertDontSee('Outsider')->assertDontSee('Gone');
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor');
    }
}
