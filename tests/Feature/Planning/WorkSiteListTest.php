<?php

namespace Tests\Feature\Planning;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Enums\WorkSiteStatus;
use App\Livewire\WorkSites\WorkSiteEditor;
use App\Livewire\WorkSites\WorkSiteIndex;
use App\Models\Agency;
use App\Models\RoleFeatureOverride;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\CapacityCalculator;
use App\Support\FeatureAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkSiteListTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed, 'daily_crew_capacity' => 3]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
    }

    public function test_only_managers_open_the_list(): void
    {
        $this->actingAs($this->supervisor)->get(route('work-sites.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]))->get(route('work-sites.index'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]))->get(route('work-sites.index'))->assertForbidden();
    }

    public function test_list_shows_only_the_agency_work_sites_and_filters(): void
    {
        $mine = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => 'Dijon', 'status' => WorkSiteStatus::Confirmed]);
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => 'Tours', 'status' => WorkSiteStatus::Forecast]);
        WorkSite::factory()->create(['city' => 'Foreign City']);

        Livewire::actingAs($this->supervisor)->test(WorkSiteIndex::class)
            ->assertSee('Dijon')->assertSee('Tours')->assertDontSee('Foreign City')
            ->set('search', 'dijon')->assertSee('Dijon')->assertDontSee('Tours')
            ->set('search', '')->set('status', 'confirmed')->assertSee($mine->city)->assertDontSee('Tours');
    }

    public function test_work_site_is_created_with_a_new_or_existing_client(): void
    {
        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)
            ->call('openFor')
            ->set('form.client', 'Fibre Ouest')->set('form.city', 'Dijon')
            ->set('form.startsOn', '2026-11-02')->set('form.endsOn', '2026-11-06')->set('form.crews', '2')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('toast');

        $site = WorkSite::query()->sole();
        $this->assertSame('Fibre Ouest', $site->client?->name);
        $this->assertSame(2, $site->crew_count);
        $this->assertSame($this->supervisor->id, $site->created_by);
    }

    public function test_invalid_dates_and_missing_fields_are_refused(): void
    {
        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor')
            ->set('form.client', 'X')->set('form.city', 'Y')->set('form.startsOn', '2026-11-06')->set('form.endsOn', '2026-11-02')
            ->call('save')->assertHasErrors('form.endsOn')
            ->set('form.city', '')->call('save')->assertHasErrors('form.city');

        $this->assertDatabaseCount('work_sites', 0);
    }

    public function test_zero_crews_become_one(): void
    {
        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor')
            ->set('form.client', 'X')->set('form.city', 'Y')->set('form.startsOn', '2026-11-02')->set('form.endsOn', '2026-11-02')->set('form.crews', '0')
            ->call('save');

        $this->assertSame(1, WorkSite::query()->sole()->crew_count);
    }

    public function test_exceeding_the_capacity_asks_for_confirmation_first(): void
    {
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-04', 'crew_count' => 3, 'status' => WorkSiteStatus::Confirmed]);

        $component = Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor')
            ->set('form.client', 'X')->set('form.city', 'Y')->set('form.startsOn', '2026-11-03')->set('form.endsOn', '2026-11-05')->set('form.crews', '1')
            ->call('save')
            ->assertDispatched('confirm-action');

        $this->assertDatabaseCount('work_sites', 1);

        $component->call('confirmSave');
        $this->assertDatabaseCount('work_sites', 2);
    }

    public function test_capacity_counts_active_sites_every_calendar_day_of_detailed_agencies_only(): void
    {
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'starts_on' => '2026-11-07', 'ends_on' => '2026-11-08', 'crew_count' => 2, 'status' => WorkSiteStatus::Forecast]);
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-08', 'crew_count' => 2, 'status' => WorkSiteStatus::Confirmed]);
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-08', 'crew_count' => 9, 'status' => WorkSiteStatus::Cancelled]);

        $calculator = app(CapacityCalculator::class);

        $this->assertSame(['2026-11-08' => 4], $calculator->overbookedDays($this->agency));
        $this->assertNull($calculator->overrunFor($this->agency, CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-07'), 1, WorkSiteStatus::Forecast));
        $this->assertNull($calculator->overrunFor($this->agency, CarbonImmutable::parse('2026-11-08'), CarbonImmutable::parse('2026-11-08'), 5, WorkSiteStatus::Cancelled));

        $daily = Agency::factory()->create(['planning_mode' => PlanningMode::Daily, 'daily_crew_capacity' => 1]);
        WorkSite::factory()->create(['agency_id' => $daily->id, 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-08', 'crew_count' => 5]);
        $this->assertSame([], $calculator->overbookedDays($daily));
    }

    public function test_editing_keeps_a_single_record_and_refuses_stale_versions(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => 'Dijon']);

        $component = Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor', $site->id)
            ->set('form.city', 'Nancy')->call('save')->assertHasNoErrors();

        $this->assertSame('Nancy', $site->fresh()?->city);
        $this->assertSame(2, $site->fresh()?->version);

        $stale = Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor', $site->id);
        $site->update(['city' => 'Tours', 'version' => 3]);
        $stale->set('form.city', 'Lourdes')->call('save')->assertHasErrors('form.city');

        $this->assertSame('Tours', $site->fresh()?->city);
        $this->assertDatabaseCount('work_sites', 1);
        unset($component);
    }

    public function test_deleting_a_work_site_deletes_its_tasks(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id]);
        $task = Task::factory()->create(['work_site_id' => $site->id]);
        $other = Task::factory()->create();

        Livewire::actingAs($this->supervisor)->test(WorkSiteIndex::class)->call('delete', $site->id);

        $this->assertSoftDeleted($site);
        $this->assertModelMissing($task);
        $this->assertModelExists($other);
    }

    public function test_other_agency_work_sites_cannot_be_touched(): void
    {
        $foreign = WorkSite::factory()->create();

        Livewire::actingAs($this->supervisor)->test(WorkSiteIndex::class)->call('delete', $foreign->id)->assertNotFound();
        $this->assertNotSoftDeleted($foreign);
    }

    public function test_creation_needs_the_create_work_site_feature(): void
    {
        RoleFeatureOverride::query()->create(['role_id' => \Spatie\Permission\Models\Role::findByName(Role::Supervisor->value)->id, 'feature' => 'create_work_site', 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();

        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor')->assertForbidden();
    }

    public function test_the_new_work_site_switch_lets_the_design_office_create_work_sites(): void
    {
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]);

        $this->actingAs($designOffice)->get(route('work-sites.create'))->assertForbidden();
        Livewire::actingAs($designOffice)->test(WorkSiteEditor::class)->assertForbidden();

        RoleFeatureOverride::query()->create(['role_id' => \Spatie\Permission\Models\Role::findByName(Role::DesignOffice->value)->id, 'feature' => 'create_work_site', 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();
        $existing = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => 'Tours']);

        $this->actingAs($designOffice)->get(route('work-sites.create'))->assertOk()->assertDontSee('Tours');
        $this->actingAs($designOffice)->get(route('work-sites.index'))->assertForbidden();

        Livewire::actingAs($designOffice)->test(WorkSiteEditor::class)
            ->call('openFor')
            ->set('form.client', 'Fibre Ouest')->set('form.city', 'Dijon')
            ->set('form.startsOn', '2026-11-02')->set('form.endsOn', '2026-11-06')->set('form.crews', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('work_sites', ['city' => 'Dijon', 'created_by' => $designOffice->id]);
        Livewire::actingAs($designOffice)->test(WorkSiteEditor::class)->call('openFor', $existing->id)->assertForbidden();
    }

    public function test_only_managers_change_the_status_of_a_work_site(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'status' => WorkSiteStatus::Forecast]);
        $admin = User::factory()->withRole(Role::Administrator)->create(['agency_id' => $this->agency->id]);
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]);
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);
        RoleFeatureOverride::query()->create(['role_id' => \Spatie\Permission\Models\Role::findByName(Role::DesignOffice->value)->id, 'feature' => 'create_work_site', 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();

        foreach ([$designOffice, $technician] as $user) {
            $this->assertFalse($user->can('changeStatus', $site));
        }

        Livewire::actingAs($designOffice)->test(WorkSiteEditor::class)->call('openFor', $site->id)->assertForbidden();
        Livewire::actingAs($technician)->test(WorkSiteEditor::class)->assertForbidden();
        $this->assertSame(WorkSiteStatus::Forecast, $site->fresh()?->status);

        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)->call('openFor', $site->id)
            ->set('form.status', WorkSiteStatus::Confirmed->value)->call('save')->assertHasNoErrors();
        $this->assertSame(WorkSiteStatus::Confirmed, $site->fresh()?->status);

        Livewire::actingAs($admin)->test(WorkSiteEditor::class)->call('openFor', $site->id)
            ->set('form.status', WorkSiteStatus::Cancelled->value)->call('save')->assertHasNoErrors();
        $this->assertSame(WorkSiteStatus::Cancelled, $site->fresh()?->status);
    }

    public function test_a_work_site_created_by_the_design_office_starts_with_the_default_status(): void
    {
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]);
        RoleFeatureOverride::query()->create(['role_id' => \Spatie\Permission\Models\Role::findByName(Role::DesignOffice->value)->id, 'feature' => 'create_work_site', 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();

        Livewire::actingAs($designOffice)->test(WorkSiteEditor::class)
            ->call('openFor')
            ->assertDontSeeHtml('id="ws-status"')
            ->set('form.client', 'Fibre Ouest')->set('form.city', 'Dijon')
            ->set('form.startsOn', '2026-11-02')->set('form.endsOn', '2026-11-06')->set('form.crews', '1')
            ->set('form.status', WorkSiteStatus::Confirmed->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('work_sites', ['city' => 'Dijon', 'status' => WorkSiteStatus::Forecast->value]);

        Livewire::actingAs($this->supervisor)->test(WorkSiteEditor::class)
            ->call('openFor')
            ->assertSeeHtml('id="ws-status"')
            ->set('form.client', 'Fibre Ouest')->set('form.city', 'Tours')
            ->set('form.startsOn', '2026-11-02')->set('form.endsOn', '2026-11-06')->set('form.crews', '1')
            ->set('form.status', WorkSiteStatus::Confirmed->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('work_sites', ['city' => 'Tours', 'status' => WorkSiteStatus::Confirmed->value]);
    }

    public function test_technicians_never_create_work_sites(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);

        $this->assertFalse($technician->can('create', WorkSite::class));
        $this->actingAs($technician)->get(route('work-sites.create'))->assertForbidden();
    }
}
