<?php

namespace Tests\Feature\Planning;

use App\Enums\AbsenceType;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Planning\TaskGrid;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TaskDragAndSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
        $this->alice = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Alice', 'planning_order' => 1]);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Bruno', 'planning_order' => 2]);
    }

    private function grid(): Testable
    {
        return Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->set('week', '2026-11-02');
    }

    private function siteTask(User $user, string $date, string $project = 'swap'): Task
    {
        $site = WorkSite::factory()->create([
            'agency_id' => $this->agency->id, 'starts_on' => $date, 'ends_on' => $date, 'crew_count' => 2, 'client_id' => Client::factory()->create(['name' => 'Acme'])->id,
            'city' => 'Dijon', 'project_type' => $project, 'cabinet_ref' => 'PM1', 'lead_technician_id' => $user->id,
        ]);
        $box = $site->boxes()->create(['reference' => 'BPE-1', 'technician_id' => $user->id, 'position' => 0]);
        $box->partners()->sync([$this->bruno->id]);
        $site->boxes()->create(['reference' => 'BPE-2', 'technician_id' => $this->bruno->id, 'position' => 1]);

        return Task::factory()->create(['user_id' => $user->id, 'date' => $date, 'work_site_id' => $site->id, 'label' => 'SWAP']);
    }

    public function test_copying_a_site_task_creates_a_one_crew_work_site_for_the_target(): void
    {
        $source = $this->siteTask($this->alice, '2026-11-02');

        $this->grid()->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', false, 1)->assertDispatched('toast');

        $copy = Task::query()->where('user_id', $this->bruno->id)->whereDate('date', '2026-11-03')->with('workSite.boxes')->sole();
        $this->assertNotSame($source->work_site_id, $copy->work_site_id);
        $this->assertSame(1, $copy->workSite?->crew_count);
        $this->assertSame($this->bruno->id, $copy->workSite?->lead_technician_id);
        $this->assertSame(['BPE-1'], $copy->workSite?->boxes->pluck('reference')->all());
        $this->assertSame($this->bruno->id, $copy->workSite?->boxes->first()?->technician_id);
        $this->assertModelExists($source);
    }

    public function test_moving_deletes_the_source_and_its_emptied_work_site(): void
    {
        $source = $this->siteTask($this->alice, '2026-11-02');

        $this->grid()->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', true, 1);

        $this->assertModelMissing($source);
        $this->assertSoftDeleted(WorkSite::query()->withTrashed()->find($source->work_site_id));
        $this->assertSame(1, Task::query()->count());
    }

    public function test_plain_tasks_are_copied_as_they_are(): void
    {
        $source = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'label' => 'FREE', 'note' => 'N', 'work_site_id' => null]);

        $this->grid()->call('dropTask', $source->id, $this->bruno->id, '2026-11-04', false, 1);

        $copy = Task::query()->where('user_id', $this->bruno->id)->sole();
        $this->assertSame(['FREE', 'N', null], [$copy->label, $copy->note, $copy->work_site_id]);
    }

    public function test_dropping_on_the_same_cell_or_an_absent_day_does_nothing(): void
    {
        $source = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'work_site_id' => null]);
        Absence::factory()->create(['user_id' => $this->bruno->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']);

        $this->grid()->call('dropTask', $source->id, $this->alice->id, '2026-11-02', false, 1)->assertNotDispatched('toast')
            ->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', false, 1)->assertDispatched('toast');

        $this->assertSame(1, Task::query()->count());
    }

    public function test_replacing_an_existing_task_asks_first(): void
    {
        $source = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'label' => 'NEW', 'work_site_id' => null]);
        $existing = Task::factory()->create(['user_id' => $this->bruno->id, 'date' => '2026-11-03', 'label' => 'OLD', 'work_site_id' => null]);

        $component = $this->grid()->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', false, 1)->assertDispatched('confirm-action');
        $this->assertModelExists($existing);

        $component->call('confirmDrop', $source->id, $this->bruno->id, '2026-11-03', false, 1, 1);
        $this->assertModelMissing($existing);
        $this->assertSame('NEW', Task::query()->where('user_id', $this->bruno->id)->sole()->label);
    }

    public function test_a_task_changed_by_someone_else_is_not_dropped(): void
    {
        $source = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'label' => 'NEW', 'work_site_id' => null]);
        $source->update(['label' => 'CHANGED']);

        $this->assertSame(2, $source->fresh()?->version);
        $this->grid()->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', false, 1)->assertDispatched('toast', message: __('tasks.conflict_drop'));
        $this->assertSame(1, Task::query()->count());

        $existing = Task::factory()->create(['user_id' => $this->bruno->id, 'date' => '2026-11-03', 'label' => 'OLD', 'work_site_id' => null]);
        $component = $this->grid()->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', false, 2)->assertDispatched('confirm-action');
        $existing->update(['label' => 'EDITED MEANWHILE']);

        $component->call('confirmDrop', $source->id, $this->bruno->id, '2026-11-03', false, 2, 1)->assertDispatched('toast', message: __('tasks.conflict_drop'));
        $this->assertSame('EDITED MEANWHILE', $existing->fresh()?->label);
    }

    public function test_only_managers_can_drop(): void
    {
        $source = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'work_site_id' => null]);

        Livewire::actingAs($this->alice)->test(TaskGrid::class)->call('dropTask', $source->id, $this->bruno->id, '2026-11-03', false, 1)->assertForbidden();
        $this->assertSame(1, Task::query()->count());
    }

    public function test_rows_are_reordered_only_with_exactly_the_people_shown(): void
    {
        $this->grid()->call('reorder', [$this->bruno->id, $this->alice->id]);
        $this->assertSame(1, $this->bruno->fresh()?->planning_order);

        $this->grid()->call('reorder', [$this->alice->id]);
        $this->assertSame(1, $this->bruno->fresh()?->planning_order);
    }

    public function test_selection_modes_and_groups(): void
    {
        $a = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'work_site_id' => null]);
        $b = Task::factory()->create(['user_id' => $this->bruno->id, 'date' => '2026-11-03', 'work_site_id' => null]);
        $audit = $this->siteTask($this->alice, '2026-11-09', 'audit');

        $component = $this->grid()->call('toggleSelecting')->assertSet('selecting', true)
            ->call('toggleTask', $a->id)->assertSet('selected', [$a->id])
            ->call('toggleMany', [$a->id, $b->id])->assertSet('selected', [$a->id, $b->id])
            ->call('toggleMany', [$a->id, $b->id])->assertSet('selected', []);

        $groups = collect($component->viewData('groups'));
        $this->assertCount(2, $groups);

        $component->set('group', 'none');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $component->get('selected'));

        $auditKey = $groups->firstWhere('filter.project_type', ProjectType::Audit->value)['key'];
        $component->call('clearSelection')->set('group', $auditKey)->assertSet('selected', [$audit->id]);
    }

    public function test_bulk_delete_removes_empty_audit_sites_and_can_be_undone(): void
    {
        $plain = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'work_site_id' => null]);
        $audit = $this->siteTask($this->alice, '2026-11-03', 'audit');
        $swap = $this->siteTask($this->alice, '2026-11-04');

        $component = $this->grid()->call('toggleSelecting')->call('toggleMany', [$plain->id, $audit->id, $swap->id])
            ->call('askDeleteSelected')->assertDispatched('confirm-action')
            ->call('deleteSelected');

        $this->assertSame(0, Task::query()->count());
        $this->assertSoftDeleted(WorkSite::query()->withTrashed()->find($audit->work_site_id));
        $this->assertNotSoftDeleted(WorkSite::query()->withTrashed()->find($swap->work_site_id));
        $this->assertSame(['tasks' => 3, 'sites' => 1], array_intersect_key($component->get('undo'), ['tasks' => 0, 'sites' => 0]));

        Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'work_site_id' => null, 'label' => 'FILLED']);

        $component->call('undoDelete')->assertSet('undo', null);

        $this->assertSame(3, Task::query()->count());
        $this->assertSame('FILLED', Task::query()->where('user_id', $this->alice->id)->whereDate('date', '2026-11-02')->sole()->label);
        $this->assertNotSoftDeleted(WorkSite::query()->withTrashed()->find($audit->work_site_id));
    }

    public function test_undo_expires_and_belongs_to_the_person_who_deleted(): void
    {
        $task = Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'work_site_id' => null]);

        $component = $this->grid()->call('toggleSelecting')->call('toggleTask', $task->id)->call('deleteSelected');
        $this->travel(2)->minutes();
        $component->call('undoDelete');

        $this->assertSame(0, Task::query()->count());
    }

    public function test_only_visible_people_can_be_bulk_deleted(): void
    {
        $foreign = Task::factory()->create(['date' => '2026-11-02', 'work_site_id' => null]);

        $this->grid()->call('toggleSelecting')->call('toggleTask', $foreign->id)->call('deleteSelected');

        $this->assertModelExists($foreign);
    }
}
