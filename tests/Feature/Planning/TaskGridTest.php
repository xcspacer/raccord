<?php

namespace Tests\Feature\Planning;

use App\Audit\OpenAudit;
use App\Enums\AbsenceType;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Planning\TaskDetailModal;
use App\Livewire\Planning\TaskEditor;
use App\Livewire\Planning\TaskGrid;
use App\Livewire\Planning\TechnicianEditor;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\AuditBox;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAgencyPeriod;
use App\Models\UserPlanningHiddenWeek;
use App\Models\WorkSite;
use App\Planning\WeekRoster;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TaskGridTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
        $this->first = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Alice', 'planning_order' => 1]);
        $this->second = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Bruno', 'planning_order' => 2]);
    }

    public function test_managers_see_the_grid_and_detailed_agencies_are_sent_to_the_timeline(): void
    {
        $this->actingAs($this->supervisor)->get(route('planning.tasks'))->assertOk()->assertSee('Alice')->assertSee('Bruno');

        $detailed = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $detailed->id]))->test(TaskGrid::class)->assertRedirect(route('planning'));
    }

    public function test_technicians_see_only_their_own_week(): void
    {
        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'label' => 'MY-TASK']);
        Task::factory()->create(['user_id' => $this->second->id, 'date' => '2026-11-04', 'label' => 'OTHER-TASK']);

        $this->actingAs($this->first)->get(route('planning.tasks'))->assertOk()->assertSee('MY-TASK')->assertDontSee('OTHER-TASK')->assertDontSee('Bruno');
        Livewire::actingAs($this->first)->test(TaskGrid::class)->call('openTask', $this->second->id, '2026-11-04')->assertForbidden();
    }

    public function test_design_office_has_no_access(): void
    {
        $this->actingAs(User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]))->get(route('planning.tasks'))->assertForbidden();
    }

    public function test_week_navigation_starts_on_monday(): void
    {
        Livewire::actingAs($this->supervisor)->test(TaskGrid::class)
            ->call('nextWeek')->assertSet('week', '2026-11-09')
            ->call('previousWeek')->call('previousWeek')->assertSet('week', '2026-10-26')
            ->call('currentWeek')->assertSet('week', '');
    }

    public function test_roster_rules(): void
    {
        $monday = CarbonImmutable::parse('2026-11-02');
        User::factory()->withRole(Role::Technician)->create(['name' => 'Elsewhere', 'agency_id' => Agency::factory()->create()->id]);
        User::factory()->withRole(Role::Technician)->create(['name' => 'Traveller', 'agency_id' => Agency::factory()->create()->id, 'all_agencies' => true]);
        User::factory()->withRole(Role::Technician)->create(['name' => 'Hidden', 'agency_id' => $this->agency->id, 'is_hidden_from_planning' => true]);
        User::factory()->withRole(Role::Technician)->create(['name' => 'Left', 'agency_id' => $this->agency->id, 'departure_date' => '2026-11-01']);
        $moved = User::factory()->withRole(Role::Technician)->create(['name' => 'Mover', 'agency_id' => Agency::factory()->create()->id]);
        UserAgencyPeriod::query()->create(['user_id' => $moved->id, 'agency_id' => $this->agency->id, 'starts_on' => '2026-11-02']);

        $names = app(WeekRoster::class)->for($this->agency, $monday)['visible']->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Alice', 'Bruno', 'Traveller', 'Mover'], $names);
        $this->assertSame(['Alice', 'Bruno'], array_slice($names, 0, 2));

        $before = app(WeekRoster::class)->for($this->agency, CarbonImmutable::parse('2026-10-26'))['visible']->pluck('name')->all();
        $this->assertNotContains('Mover', $before);
    }

    public function test_removing_a_person_hides_them_for_that_week_only_and_can_be_restored(): void
    {
        $component = Livewire::actingAs($this->supervisor)->test(TaskGrid::class)
            ->call('askRemove', $this->second->id)->assertDispatched('confirm-action')
            ->call('removeFromWeek', $this->second->id);

        $this->assertDatabaseHas('user_planning_hidden_weeks', ['user_id' => $this->second->id, 'week_starts_on' => '2026-11-02']);
        $component->assertDontSee('Bruno ·')->assertSee('↩ Bruno');

        $component->call('nextWeek')->assertSee('Bruno');
        $component->call('previousWeek')->call('restoreToWeek', $this->second->id);
        $this->assertSame(0, UserPlanningHiddenWeek::query()->count());
    }

    public function test_people_can_be_reordered(): void
    {
        Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->call('move', $this->second->id, -1);

        $this->assertSame(1, $this->second->fresh()?->planning_order);
        $this->assertSame(2, $this->first->fresh()?->planning_order);
    }

    public function test_task_cells_show_generated_text_and_absences(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => 'Dijon', 'cabinet_ref' => 'PM042', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'label' => 'SWAP', 'detail' => null, 'work_site_id' => $site->id]);
        Absence::factory()->create(['user_id' => $this->second->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']);

        Livewire::actingAs($this->supervisor)->test(TaskGrid::class)
            ->assertSee('Dijon')->assertSee('Cabinet : PM042')->assertSee(__('tasks.absence_badge.leave'));
    }

    public function test_task_is_created_edited_and_cleared(): void
    {
        $editor = Livewire::actingAs($this->supervisor)->test(TaskEditor::class)
            ->call('openFor', $this->first->id, '2026-11-04')->assertSet('mode', 'task')
            ->set('label', 'SWAP PM1')->set('detail', 'CABINET')->set('note', 'RDV 8h')
            ->call('save')->assertHasNoErrors()->assertDispatched('task-saved');

        $task = Task::query()->sole();
        $this->assertSame('HO', $task->state_code);
        $this->assertSame('SWAP PM1', $task->label);

        $editor->call('openFor', $this->first->id, '2026-11-04')->assertSet('label', 'SWAP PM1')
            ->set('label', '')->set('detail', '')->set('note', '')->set('stateCode', '')
            ->call('save');
        $this->assertSame(0, Task::query()->count());

        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-05']);
        $editor->call('openFor', $this->first->id, '2026-11-05')->call('clear');
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_task_changed_by_someone_else_is_not_overwritten_and_the_window_shows_the_new_version(): void
    {
        $task = Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'label' => 'MINE']);
        $editor = Livewire::actingAs($this->supervisor)->test(TaskEditor::class)->call('openFor', $this->first->id, '2026-11-04')->set('label', 'MY EDIT');

        $task->update(['label' => 'THEIRS']);

        $editor->call('save')->assertHasErrors('conflict')->assertSee(__('tasks.conflict'))->assertSet('label', 'THEIRS')->assertNotDispatched('task-saved');
        $this->assertSame('THEIRS', $task->fresh()?->label);

        $editor->set('label', 'MY EDIT')->call('save')->assertHasNoErrors()->assertDispatched('task-saved');
        $this->assertSame('MY EDIT', $task->fresh()?->label);

        $empty = Livewire::actingAs($this->supervisor)->test(TaskEditor::class)->call('openFor', $this->first->id, '2026-11-05')->set('label', 'NEW');
        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-05', 'label' => 'CREATED MEANWHILE']);
        $empty->call('save')->assertHasErrors('conflict');

        $cleared = Livewire::actingAs($this->supervisor)->test(TaskEditor::class)->call('openFor', $this->first->id, '2026-11-04');
        $task->fresh()?->update(['note' => 'Changed']);
        $cleared->call('clear')->assertHasErrors('conflict');
        $this->assertModelExists($task);
    }

    public function test_editing_a_generated_task_keeps_its_work_site_link(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id]);
        $task = Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'work_site_id' => $site->id]);

        Livewire::actingAs($this->supervisor)->test(TaskEditor::class)->call('openFor', $this->first->id, '2026-11-04')->set('note', 'Changed')->call('save');

        $this->assertSame($site->id, $task->fresh()?->work_site_id);
    }

    public function test_absences_are_created_for_a_period_and_keep_tasks_after_confirmation(): void
    {
        $task = Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-05']);

        $editor = Livewire::actingAs($this->supervisor)->test(TaskEditor::class)
            ->call('openFor', $this->first->id, '2026-11-04')
            ->set('mode', 'leave')->set('from', '2026-11-04')->set('to', '2026-11-06')
            ->call('save')->assertDispatched('confirm-action');

        $this->assertDatabaseCount('absences', 0);

        $editor->call('confirmAbsence');
        $this->assertDatabaseHas('absences', ['user_id' => $this->first->id, 'type' => 'leave', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-06']);
        $this->assertModelExists($task);

        $editor->call('openFor', $this->first->id, '2026-11-05')->assertSet('mode', 'leave')->assertSet('from', '2026-11-04')->assertSet('to', '2026-11-06')->call('clear');
        $this->assertDatabaseCount('absences', 0);
    }

    public function test_single_day_absence_and_invalid_period(): void
    {
        $editor = Livewire::actingAs($this->supervisor)->test(TaskEditor::class)
            ->call('openFor', $this->first->id, '2026-11-04')
            ->set('mode', 'absent')->set('note', 'Doctor')->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('absences', ['user_id' => $this->first->id, 'type' => 'absent', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'note' => 'Doctor']);

        $editor->call('openFor', $this->second->id, '2026-11-04')->set('mode', 'sick')->set('from', '2026-11-06')->set('to', '2026-11-05')->call('save')->assertHasErrors('from');
    }

    public function test_a_task_cannot_be_planned_on_an_absent_day(): void
    {
        Absence::factory()->create(['user_id' => $this->first->id, 'type' => AbsenceType::Sick, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);

        Livewire::actingAs($this->supervisor)->test(TaskEditor::class)->call('openFor', $this->first->id, '2026-11-04')
            ->set('mode', 'task')->set('label', 'X')->call('save')->assertHasErrors('mode');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_people_who_left_cannot_be_edited_and_technicians_cannot_open_the_editor(): void
    {
        $left = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'departure_date' => '2026-11-03']);

        Livewire::actingAs($this->supervisor)->test(TaskEditor::class)->call('openFor', $left->id, '2026-11-04')->assertNotFound();
        Livewire::actingAs($this->first)->test(TaskEditor::class)->assertForbidden();
    }

    public function test_technician_detail_shows_team_boxes_and_documents_for_their_own_task_only(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'cabinet_ref' => 'PM042', 'city' => 'Dijon', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
        $site->members()->sync([$this->first->id => ['role' => 'lead'], $this->second->id => ['role' => 'partner']]);
        $box = $site->boxes()->create(['reference' => 'BPE-7', 'technician_id' => $this->first->id, 'position' => 0]);
        $box->partners()->sync([$this->second->id]);
        $stored = StoredFile::query()->create(['sha256' => str_repeat('a', 64), 'path' => 'files/aa/a.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 1]);
        $site->attachments()->create(['type' => 'synoptic', 'stored_file_id' => $stored->id, 'original_name' => 'syno.pdf']);
        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'work_site_id' => $site->id, 'note' => 'Bring keys']);
        Task::factory()->create(['user_id' => $this->second->id, 'date' => '2026-11-05', 'label' => 'SECRET']);

        Livewire::actingAs($this->first)->test(TaskGrid::class)->call('openDetail', '2026-11-04');

        Livewire::actingAs($this->first)->test(TaskDetailModal::class)
            ->call('openFor', '2026-11-04')
            ->assertSee('Bruno')->assertSee('BPE-7')->assertSee('PM042')->assertSee('Bring keys')->assertSee(__('enums.work_site_attachment_types.synoptic'))
            ->call('openFor', '2026-11-05')->assertNotFound();

        Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->call('openDetail', '2026-11-04')->assertForbidden();
    }

    public function test_technician_detail_offers_directions_to_known_positions_and_opens_the_audit(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit, 'cabinet_ref' => 'PM042', 'city' => 'Dijon', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
        $site->members()->sync([$this->first->id => ['role' => 'lead'], $this->second->id => ['role' => 'partner']]);
        $site->boxes()->create(['reference' => 'BPE-6', 'technician_id' => $this->first->id, 'position' => 0]);
        $site->boxes()->create(['reference' => 'BPE-7', 'technician_id' => $this->first->id, 'position' => 1])->partners()->sync([$this->second->id]);
        $site->boxes()->create(['reference' => 'BPE-8', 'technician_id' => $this->second->id, 'position' => 2]);
        $audit = app(OpenAudit::class)->handle($this->supervisor, 'PM042');
        $audit->forceFill(['cabinet_latitude' => 43.91, 'cabinet_longitude' => 2.14])->save();
        AuditBox::query()->create(['audit_id' => $audit->id, 'name' => 'BPE-7', 'kind' => AuditBoxKind::Bpe, 'source' => AuditBoxSource::Synoptic, 'latitude' => 43.93, 'longitude' => 2.148]);
        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'work_site_id' => $site->id]);

        $detail = Livewire::actingAs($this->first)->test(TaskDetailModal::class)->call('openFor', '2026-11-04')
            ->assertSee('PM042 🧭')->assertSee('BPE-7 🧭')->assertSee('BPE-8')->assertDontSee('BPE-8 🧭')->assertDontSee('BPE-6')
            ->assertSee(__('tasks.detail.team_box', ['n' => 2]))->assertSee(__('tasks.detail.open_audit'));

        $detail->call('navigate', 'box', 0)->assertSet('navigation', ['name' => 'BPE-7', 'latitude' => 43.93, 'longitude' => 2.148])
            ->assertSee('https://waze.com/ul?ll=43.93,2.148', false)->assertSee(__('audit.field.navigate_to', ['name' => 'BPE-7']))
            ->call('closeNavigation')->assertSet('navigation', null)
            ->call('navigate', 'cabinet', 0)->assertSet('navigation.name', 'PM042')
            ->call('openAudit')->assertRedirect(route('audits.show', ['cabinet' => 'PM042']));

        Livewire::actingAs($this->first)->test(TaskDetailModal::class)->call('openFor', '2026-11-04')->call('navigate', 'box', 1)->assertNotFound();
    }

    public function test_directions_fall_back_on_the_map_file_of_the_work_site(): void
    {
        Storage::fake('local');
        $path = tempnam(sys_get_temp_dir(), 'kml');
        file_put_contents($path, '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Folder><name>BOITES</name><Placemark><name>BPE-7</name><Point><coordinates>2.1480,43.9300</coordinates></Point></Placemark></Folder></Document></kml>');
        Storage::disk('local')->put('files/map.kml', (string) file_get_contents($path));
        unlink($path);
        $stored = StoredFile::query()->create(['sha256' => str_repeat('b', 64), 'disk' => 'local', 'path' => 'files/map.kml', 'mime_type' => 'application/xml', 'extension' => 'kml', 'size_bytes' => 10]);
        $other = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'cabinet_ref' => 'PM042']);
        $other->attachments()->create(['type' => 'kmz', 'stored_file_id' => $stored->id, 'original_name' => 'map.kml']);
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Swap, 'cabinet_ref' => 'PM042', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
        $site->members()->sync([$this->first->id => ['role' => 'lead']]);
        $site->boxes()->create(['reference' => 'BPE-7', 'technician_id' => $this->second->id, 'position' => 0]);
        Task::factory()->create(['user_id' => $this->first->id, 'date' => '2026-11-04', 'work_site_id' => $site->id]);

        Livewire::actingAs($this->first)->test(TaskDetailModal::class)->call('openFor', '2026-11-04')
            ->assertSee('BPE-7 🧭')->assertDontSee(__('tasks.detail.open_audit'))
            ->call('navigate', 'box', 0)->assertSet('navigation.latitude', 43.93);

        Livewire::actingAs($this->first)->test(TaskDetailModal::class)->call('openFor', '2026-11-04')->call('openAudit')->assertNotFound();
    }

    public function test_administrator_changes_a_technicians_agency_from_a_week(): void
    {
        $other = Agency::factory()->create();
        $admin = User::factory()->withRole(Role::Administrator)->create(['agency_id' => $this->agency->id]);

        Livewire::actingAs($admin)->test(TechnicianEditor::class)
            ->call('openFor', $this->first->id, '2026-11-04')
            ->assertSet('week', '2026-11-02')->assertSet('agencyId', (string) $this->agency->id)
            ->set('agencyId', (string) $other->id)->call('applyAgency')->assertHasNoErrors();

        $roster = app(WeekRoster::class);
        $this->assertSame($other->id, $roster->agencyIdFor($this->first, CarbonImmutable::parse('2026-11-02')));
        $this->assertSame($this->agency->id, $roster->agencyIdFor($this->first, CarbonImmutable::parse('2026-10-26')));
        $this->assertNotContains('Alice', $roster->for($this->agency, CarbonImmutable::parse('2026-11-09'))['visible']->pluck('name')->all());
    }

    public function test_supervisors_cannot_change_agencies_but_can_manage_absences_from_the_window(): void
    {
        Absence::factory()->create(['user_id' => $this->first->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-05']);
        Absence::factory()->create(['user_id' => $this->first->id, 'type' => AbsenceType::Absent, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);

        $component = Livewire::actingAs($this->supervisor)->test(TechnicianEditor::class)
            ->call('openFor', $this->first->id, '2026-11-02')
            ->assertSee(__('absences.days_short', ['count' => 3]))->assertDontSee(__('enums.absence_types.absent'));

        Livewire::actingAs($this->supervisor)->test(TechnicianEditor::class)->call('openFor', $this->first->id, '2026-11-02')->call('applyAgency')->assertForbidden();

        $component->call('editAbsence', '2026-11-03')->assertDispatched('task-open');
        $component->call('removeAbsence', '2026-11-03', '2026-11-05');
        $this->assertSame(1, Absence::query()->count());
    }
}
