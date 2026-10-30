<?php

namespace Tests\Feature\Admin;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Admin\AgencyIndex;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgencyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
    }

    public function test_only_administrators_manage_agencies(): void
    {
        $this->actingAs($this->admin)->get(route('agencies.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('agencies.index'))->assertForbidden();
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(AgencyIndex::class)->assertForbidden();
    }

    public function test_agency_is_created_with_a_default_client_and_a_unique_code(): void
    {
        $component = Livewire::actingAs($this->admin)->test(AgencyIndex::class)
            ->set('name', 'North Agency')->set('mode', 'detailed')->set('capacity', '6')->set('defaultClient', 'Fibre Ouest')
            ->call('save')->assertHasNoErrors();

        $agency = Agency::query()->with('defaultClient')->sole();
        $this->assertSame('north-agency', $agency->code);
        $this->assertSame(PlanningMode::Detailed, $agency->planning_mode);
        $this->assertSame(6, $agency->daily_crew_capacity);
        $this->assertSame('Fibre Ouest', $agency->defaultClient?->name);

        $component->set('name', 'North  Agency!')->call('save')->assertHasNoErrors();
        $this->assertSame(['north-agency', 'north-agency-2'], Agency::query()->orderBy('id')->pluck('code')->all());
    }

    public function test_invalid_input_is_refused(): void
    {
        Agency::factory()->create(['name' => 'Taken']);

        Livewire::actingAs($this->admin)->test(AgencyIndex::class)
            ->set('name', '')->call('save')->assertHasErrors('name')
            ->set('name', 'Taken')->call('save')->assertHasErrors('name')
            ->set('name', 'New')->set('capacity', '0')->call('save')->assertHasErrors('capacity')
            ->set('capacity', '4')->set('mode', 'weekly')->call('save')->assertHasErrors('mode');
    }

    public function test_mode_is_locked_once_the_agency_has_work_sites(): void
    {
        $agency = Agency::factory()->create();
        WorkSite::factory()->create(['agency_id' => $agency->id]);

        Livewire::actingAs($this->admin)->test(AgencyIndex::class)->call('edit', $agency->id)
            ->set('mode', 'daily')->call('save')->assertHasErrors('mode')
            ->set('mode', 'detailed')->set('capacity', '9')->call('save')->assertHasNoErrors();

        $this->assertSame(9, $agency->fresh()?->daily_crew_capacity);
    }

    public function test_agencies_in_use_and_the_last_agency_cannot_be_deleted(): void
    {
        [$used, $empty] = Agency::factory()->count(2)->create()->all();
        User::factory()->create(['agency_id' => $used->id]);

        $component = Livewire::actingAs($this->admin)->test(AgencyIndex::class)
            ->call('delete', $used->id)->assertHasErrors('delete');
        $this->assertNotSoftDeleted($used);

        $component->call('delete', $empty->id);
        $this->assertSoftDeleted($empty);

        $used->users()->delete();
        $component->call('delete', $used->id)->assertHasErrors('delete');
        $this->assertNotSoftDeleted($used);
    }

    public function test_agencies_can_be_reordered(): void
    {
        [$first, $second] = [Agency::factory()->create(['sort_order' => 1]), Agency::factory()->create(['sort_order' => 2])];

        Livewire::actingAs($this->admin)->test(AgencyIndex::class)->call('move', $second->id, -1);

        $this->assertSame([$second->id, $first->id], Agency::query()->orderBy('sort_order')->pluck('id')->all());
    }
}
