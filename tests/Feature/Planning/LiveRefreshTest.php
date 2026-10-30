<?php

namespace Tests\Feature\Planning;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Planning\TaskGrid;
use App\Models\Agency;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_planning_pages_refresh_themselves_every_minute(): void
    {
        $detailed = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);
        $daily = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $north = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $detailed->id]);
        $south = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $daily->id]);

        $this->actingAs($north)->get(route('planning'))->assertSee('wire:poll.60s.visible', false);
        $this->actingAs($north)->get(route('work-sites.index'))->assertSee('wire:poll.60s.visible', false);
        $this->actingAs($south)->get(route('planning.tasks'))->assertSee('wire:poll.60s.visible', false);
    }

    public function test_the_grid_stops_polling_while_selecting(): void
    {
        $daily = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $daily->id]);

        Livewire::actingAs($manager)->test(TaskGrid::class)->assertSee('wire:poll.60s.visible', false)
            ->call('toggleSelecting')->assertDontSee('wire:poll.60s.visible', false);
    }

    public function test_phones_get_a_view_select_on_planning_pages_only(): void
    {
        $detailed = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);
        $manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $detailed->id]);

        $this->actingAs($manager)->get(route('planning'))->assertSee('data-view-select', false)->assertSee(route('work-sites.index'), false);
        $this->actingAs($manager)->get(route('work-sites.index'))->assertSee('data-view-select', false);
        $this->actingAs($manager)->get(route('home'))->assertDontSee('data-view-select', false);
    }
}
