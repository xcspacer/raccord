<?php

namespace Tests\Feature\DayPunch;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Planning\TaskGrid;
use App\Models\Agency;
use App\Models\DayPunch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DayPunchDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);
    }

    private function punch(string $date, array $attributes = []): DayPunch
    {
        return DayPunch::query()->create(array_merge([
            'user_id' => $this->technician->id, 'date' => $date,
            'start_at' => CarbonImmutable::parse($date.' 07:58', 'Europe/Paris')->utc(), 'start_received_at' => now(),
            'start_latitude' => 43.9298, 'start_longitude' => 2.148,
        ], $attributes));
    }

    public function test_managers_see_times_and_position_links_in_the_grid(): void
    {
        $this->punch('2026-11-02', ['end_at' => CarbonImmutable::parse('2026-11-02 17:12', 'Europe/Paris')->utc(), 'end_latitude' => 43.9, 'end_longitude' => 2.1]);
        $this->punch('2026-11-03', ['end_at' => CarbonImmutable::parse('2026-11-03 16:00', 'Europe/Paris')->utc(), 'end_declared' => true, 'end_geo_missing' => true]);
        $this->punch('2026-11-04');

        $html = Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->set('week', '2026-11-02')->html();

        $this->assertStringContainsString('▶ 07:58', $html);
        $this->assertStringContainsString('■ 17:12', $html);
        $this->assertStringContainsString('https://www.google.com/maps?q=43.9298,2.148', $html);
        $this->assertStringContainsString(__('day_punch.pin.running'), $html);
        $this->assertStringContainsString(__('day_punch.pin.declared'), $html);
    }

    public function test_a_past_day_without_an_end_is_flagged_and_missing_positions_are_marked(): void
    {
        $this->punch('2026-11-02', ['start_latitude' => null, 'start_longitude' => null, 'start_geo_missing' => true]);

        $html = Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->set('week', '2026-11-02')->html();

        $this->assertStringContainsString('■ —', $html);
        $this->assertStringContainsString('📍✗', $html);
    }

    public function test_managers_are_warned_of_imprecise_positions_and_technicians_are_not(): void
    {
        $this->punch('2026-11-02', ['start_accuracy' => 350, 'end_at' => CarbonImmutable::parse('2026-11-02 17:12', 'Europe/Paris')->utc(), 'end_latitude' => 43.9, 'end_longitude' => 2.1, 'end_accuracy' => 200]);
        $imprecise = __('day_punch.pin.imprecise', ['metres' => 350]);

        $managerView = Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->set('week', '2026-11-02')->html();
        $this->assertStringContainsString($imprecise, $managerView);
        $this->assertStringNotContainsString(__('day_punch.pin.imprecise', ['metres' => 200]), $managerView);

        $ownView = Livewire::actingAs($this->technician)->test(TaskGrid::class)->set('week', '2026-11-02')->html();
        $this->assertStringContainsString('▶ 07:58', $ownView);
        $this->assertStringNotContainsString($imprecise, $ownView);
    }

    public function test_technicians_only_ever_see_their_own_week(): void
    {
        $this->punch('2026-11-02');
        $other = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);
        DayPunch::query()->create(['user_id' => $other->id, 'date' => '2026-11-02', 'start_at' => CarbonImmutable::parse('2026-11-02 06:11', 'Europe/Paris')->utc(), 'start_received_at' => now(), 'start_latitude' => 10.5, 'start_longitude' => 20.5]);

        $html = Livewire::actingAs($this->technician)->test(TaskGrid::class)->set('week', '2026-11-02')->html();

        $this->assertStringContainsString('07:58', $html);
        $this->assertStringNotContainsString('06:11', $html);
        $this->assertStringNotContainsString('10.5,20.5', $html);
    }

    public function test_administrators_see_every_punch_of_the_agency(): void
    {
        $this->punch('2026-11-02');
        $administrator = User::factory()->withRole(Role::Administrator)->create(['agency_id' => $this->agency->id]);

        $html = Livewire::actingAs($administrator)->test(TaskGrid::class)->set('week', '2026-11-02')->html();

        $this->assertStringContainsString('▶ 07:58', $html);
        $this->assertStringContainsString('https://www.google.com/maps?q=43.9298,2.148', $html);
    }

    public function test_the_design_office_never_receives_other_peoples_punch_times_or_positions(): void
    {
        $this->punch('2026-11-02', ['end_at' => CarbonImmutable::parse('2026-11-02 17:12', 'Europe/Paris')->utc(), 'end_latitude' => 43.9, 'end_longitude' => 2.1]);
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]);
        $plannedDesignOffice = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id, 'is_plannable' => true]);

        $this->actingAs($designOffice)->get(route('planning.tasks').'?week=2026-11-02')->assertForbidden();

        $component = Livewire::actingAs($plannedDesignOffice)->test(TaskGrid::class)->set('week', '2026-11-02');
        $html = $component->html();

        $this->assertStringNotContainsString('07:58', $html);
        $this->assertStringNotContainsString('17:12', $html);
        $this->assertStringNotContainsString('43.9298', $html);
        $this->assertStringNotContainsString(__('day_punch.pin.open_map'), $html);
        $this->assertArrayNotHasKey($this->technician->id, $component->viewData('cells'));
    }

    public function test_old_positions_are_erased_but_the_times_stay(): void
    {
        $old = $this->punch('2025-09-01');
        $recent = $this->punch('2026-10-01');

        $this->artisan('punches:purge-locations')->assertSuccessful();

        $this->assertNull($old->fresh()?->start_latitude);
        $this->assertNotNull($old->fresh()?->location_purged_at);
        $this->assertSame('07:58', $old->fresh()?->startLocal());
        $this->assertNotNull($recent->fresh()?->start_latitude);
    }
}
