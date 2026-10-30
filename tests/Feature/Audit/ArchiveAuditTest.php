<?php

namespace Tests\Feature\Audit;

use App\Audit\OpenAudit;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Audit\AuditIndex;
use App\Livewire\Audit\AuditPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArchiveAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_archiving_needs_the_reference_keeps_the_data_frees_the_cabinet_and_removes_its_planning(): void
    {
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id, 'name' => 'Sam']);
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $audit = app(OpenAudit::class)->handle($manager, 'PM153');
        $site = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $technician->id]);
        $other = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM999']);
        Task::query()->create(['user_id' => $technician->id, 'date' => '2026-11-03', 'state_code' => 'HO', 'work_site_id' => $site->id]);

        Livewire::actingAs($technician)->test(AuditPage::class, ['cabinet' => 'PM153'])->call('askArchive')->assertForbidden();

        $page = Livewire::actingAs($manager)->test(AuditPage::class, ['cabinet' => 'PM153'])->call('askArchive')->assertSet('archiving', true);
        $page->set('archiveCode', 'PM15')->call('archive')->assertHasErrors('archiveCode');
        $this->assertNull($audit->refresh()->deleted_at);

        $page->set('archiveCode', 'pm153')->call('archive')->assertRedirect();

        $archived = Audit::withTrashed()->findOrFail($audit->id);
        $this->assertTrue($archived->trashed());
        $this->assertSame('PM153', $archived->original_cabinet_key);
        $this->assertSame('Sam', $archived->deleted_by_name);
        $this->assertNotSame('PM153', $archived->cabinet_key);
        $this->assertNull(WorkSite::query()->find($site->id));
        $this->assertSame(0, Task::query()->count());
        $this->assertNotNull(WorkSite::query()->find($other->id));

        $again = app(OpenAudit::class)->handle($manager, 'PM153');
        $this->assertNotSame($audit->id, $again->id);
        $this->assertSame('PM153', $again->cabinet_key);
    }

    public function test_the_audit_list_deletes_a_card_with_the_same_confirmation(): void
    {
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $audit = app(OpenAudit::class)->handle($manager, 'PM153');
        $site = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $technician->id]);
        $plannedOnly = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM200', 'audit_mode' => 'full']);
        $other = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM999', 'audit_mode' => 'full']);

        Livewire::actingAs($technician)->test(AuditIndex::class, ['mode' => 'full'])->assertDontSee('audit__card-delete', false)->call('askArchive', 'PM153')->assertForbidden();

        $list = Livewire::actingAs($manager)->test(AuditIndex::class, ['mode' => 'full'])->assertSee('audit__card-delete', false)
            ->call('askArchive', 'PM153')->assertSet('archiving', 'PM153')->assertSee(__('audit.archive.question', ['cabinet' => 'PM153']));
        $list->set('archiveCode', 'PM15')->call('archive')->assertHasErrors('archiveCode');
        $this->assertFalse($audit->refresh()->trashed());

        $list->set('archiveCode', 'pm153')->call('archive')->assertHasNoErrors()->assertSet('archiving', null)->assertDispatched('toast');
        $this->assertTrue(Audit::withTrashed()->findOrFail($audit->id)->trashed());
        $this->assertNull(WorkSite::query()->find($site->id));

        $list->call('askArchive', 'PM200')->set('archiveCode', 'PM200')->call('archive')->assertHasNoErrors();
        $this->assertNull(WorkSite::query()->find($plannedOnly->id));
        $this->assertNotNull(WorkSite::query()->find($other->id));
    }
}
