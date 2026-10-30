<?php

namespace Tests\Feature\Planning;

use App\Enums\AbsenceType;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Enums\WorkSiteStatus;
use App\Livewire\Planning\CapacityPanel;
use App\Livewire\Planning\Timeline;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\CapacityCalculator;
use App\Planning\TimelineBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed, 'daily_crew_capacity' => 3]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
    }

    public function test_managers_of_a_detailed_agency_see_the_timeline(): void
    {
        $this->actingAs($this->supervisor)->get(route('planning'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]))->get(route('planning'))->assertForbidden();
    }

    public function test_daily_agencies_are_sent_to_their_list(): void
    {
        $daily = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $user = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $daily->id]);

        Livewire::actingAs($user)->test(Timeline::class)->assertRedirect(route('planning.tasks'));
    }

    public function test_empty_timeline_shows_the_message(): void
    {
        Livewire::actingAs($this->supervisor)->test(Timeline::class)->assertSee(__('planning.empty'));
    }

    public function test_lanes_reuse_a_lane_only_when_the_previous_site_ended_before(): void
    {
        $sites = collect([
            $this->site('2026-11-02', '2026-11-05'),
            $this->site('2026-11-05', '2026-11-07'),
            $this->site('2026-11-06', '2026-11-09'),
        ])->each->load('client');

        $timeline = app(TimelineBuilder::class)->build($sites, [], 3, CarbonImmutable::parse('2026-11-04'), collect());

        $this->assertNotNull($timeline);
        $this->assertSame([0, 1, 0], array_column($timeline['bars'], 'lane'));
        $this->assertSame(2, $timeline['lanes']);
        $this->assertSame(4, $timeline['bars'][0]['span']);
        $this->assertSame(3, $timeline['bars'][0]['start']);
        $this->assertSame(32, $timeline['dayWidth']);
        $this->assertSame(5, $timeline['todayIndex']);
    }

    public function test_window_pads_the_period_and_scales_the_day_width(): void
    {
        $short = app(TimelineBuilder::class)->build(collect([$this->site('2026-11-02', '2026-11-03')]), [], 3, CarbonImmutable::parse('2026-11-04'), collect());
        $long = app(TimelineBuilder::class)->build(collect([$this->site('2026-01-01', '2026-12-31')]), [], 3, CarbonImmutable::parse('2026-11-04'), collect());

        $this->assertNotNull($short);
        $this->assertNotNull($long);
        $this->assertSame(3 + 2 + 4, $short['total'] + 1);
        $this->assertSame(18, $long['dayWidth']);
        $this->assertSame(array_sum(array_column($long['months'], 'span')), $long['total'] + 1);
    }

    public function test_overbooked_ranges_group_consecutive_days_and_ignore_cancelled(): void
    {
        $this->site('2026-11-02', '2026-11-04', 2);
        $this->site('2026-11-03', '2026-11-05', 2);
        $this->site('2026-11-10', '2026-11-10', 9, WorkSiteStatus::Cancelled);
        $this->site('2026-11-12', '2026-11-12', 2);
        $this->site('2026-11-12', '2026-11-12', 2);

        $ranges = app(CapacityCalculator::class)->overbookedRanges($this->agency);

        $this->assertSame([['2026-11-03', '2026-11-04'], ['2026-11-12', '2026-11-12']], array_map(fn (array $range) => [$range[0]->toDateString(), $range[1]->toDateString()], $ranges));
    }

    public function test_alert_band_and_filters(): void
    {
        $this->site('2026-11-02', '2026-11-04', 2, WorkSiteStatus::Confirmed, 'Dijon');
        $this->site('2026-11-02', '2026-11-04', 2, WorkSiteStatus::Forecast, 'Tours');

        Livewire::actingAs($this->supervisor)->test(CapacityPanel::class)->assertSee(__('planning.overbooking'));

        Livewire::actingAs($this->supervisor)->test(Timeline::class)
            ->assertSee('Dijon')->assertSee('Tours')
            ->set('search', 'dijon')->assertSee('Dijon')->assertDontSee('Tours')
            ->set('search', '')->set('status', 'confirmed')->assertSee('Dijon')->assertDontSee('Tours');
    }

    public function test_the_capacity_box_sits_in_the_page_header_and_the_alert_above_the_content(): void
    {
        $this->site('2026-11-02', '2026-11-04', 2, WorkSiteStatus::Confirmed, 'Dijon');
        $this->site('2026-11-02', '2026-11-04', 2, WorkSiteStatus::Forecast, 'Tours');

        foreach (['planning', 'work-sites.index'] as $route) {
            $html = $this->actingAs($this->supervisor)->get(route($route))->assertOk()->getContent();
            $header = (string) str($html)->between('<header class="app-header">', '</header>');
            $content = (string) str($html)->after('<main id="content"');

            $this->assertStringContainsString('capacity-box', $header, $route);
            $this->assertStringNotContainsString('alert-band', $header, $route);
            $this->assertStringContainsString('alert-band', $content, $route);
            $this->assertStringNotContainsString('capacity-box', $content, $route);
        }
    }

    public function test_only_administrators_change_the_capacity_and_invalid_values_become_one(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create(['agency_id' => $this->agency->id]);

        Livewire::actingAs($this->supervisor)->test(CapacityPanel::class)->set('capacity', '9')->assertForbidden();
        $this->assertSame(3, $this->agency->fresh()?->daily_crew_capacity);

        Livewire::actingAs($admin)->test(CapacityPanel::class)->set('capacity', '7');
        $this->assertSame(7, $this->agency->fresh()?->daily_crew_capacity);

        Livewire::actingAs($admin)->test(CapacityPanel::class)->set('capacity', '0')->assertSet('capacity', '1');
        $this->assertSame(1, $this->agency->fresh()?->daily_crew_capacity);
    }

    public function test_absences_of_the_agency_technicians_are_listed(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Léa Martin']);
        $hidden = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Hidden One', 'is_hidden_from_planning' => true]);
        $other = User::factory()->withRole(Role::Technician)->create(['name' => 'Other Agency']);

        foreach ([$technician, $hidden, $other] as $user) {
            Absence::factory()->create(['user_id' => $user->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04']);
        }

        $this->site('2026-11-02', '2026-11-06');

        Livewire::actingAs($this->supervisor)->test(Timeline::class)
            ->assertSee('Léa Martin')->assertDontSee('Hidden One')->assertDontSee('Other Agency');
    }

    public function test_bars_open_the_work_site_window(): void
    {
        $site = $this->site('2026-11-02', '2026-11-03');

        Livewire::actingAs($this->supervisor)->test(Timeline::class)->call('openEditor', $site->id)->assertDispatched('work-site-open');
    }

    private function site(string $from, string $to, int $crews = 1, WorkSiteStatus $status = WorkSiteStatus::Confirmed, ?string $city = null): WorkSite
    {
        return WorkSite::factory()->create(array_filter([
            'agency_id' => $this->agency->id, 'starts_on' => $from, 'ends_on' => $to, 'crew_count' => $crews, 'status' => $status, 'city' => $city,
        ]))->load('client');
    }
}
