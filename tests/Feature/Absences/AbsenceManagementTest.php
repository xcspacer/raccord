<?php

namespace Tests\Feature\Absences;

use App\Absences\AbsenceCalendar;
use App\Enums\AbsenceType;
use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Absences\AbsenceIndex;
use App\Livewire\Absences\AbsenceManager;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\RoleFeatureOverride;
use App\Models\Task;
use App\Models\User;
use App\Support\FeatureAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class AbsenceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-20 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => Agency::factory()->create(['planning_mode' => PlanningMode::Daily])->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Léa Martin']);
    }

    public function test_page_requires_the_absences_feature(): void
    {
        $this->actingAs($this->supervisor)->get(route('absences.index'))->assertOk()->assertSee('Léa Martin');
        $this->actingAs($this->technician)->get(route('absences.index'))->assertForbidden();

        RoleFeatureOverride::query()->create(['role_id' => SpatieRole::findByName(Role::Supervisor->value)->id, 'feature' => Feature::Absences->value, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->supervisor)->get(route('absences.index'))->assertForbidden();
    }

    public function test_page_lists_only_people_still_in_the_planning(): void
    {
        User::factory()->withRole(Role::Technician)->departed()->create(['name' => 'Gone Person']);

        Livewire::actingAs($this->supervisor)->test(AbsenceIndex::class)
            ->assertSee('Léa Martin')
            ->assertDontSee('Gone Person')
            ->set('search', 'zzz')
            ->assertDontSee('Léa Martin');
    }

    public function test_absence_without_tasks_is_added_directly(): void
    {
        $this->manager()
            ->set('type', AbsenceType::Leave->value)->set('from', '2026-05-18')->set('to', '2026-05-22')
            ->call('add')
            ->assertHasNoErrors()
            ->assertNotDispatched('confirm-action')
            ->assertDispatched('absences-changed');

        $this->assertDatabaseHas('absences', ['user_id' => $this->technician->id, 'type' => 'leave', 'starts_on' => '2026-05-18', 'ends_on' => '2026-05-22', 'created_by' => $this->supervisor->id]);
    }

    public function test_planned_tasks_are_kept_after_confirmation(): void
    {
        $task = Task::factory()->create(['user_id' => $this->technician->id, 'date' => '2026-05-19']);

        $this->manager()
            ->set('type', AbsenceType::Sick->value)->set('from', '2026-05-18')->set('to', '2026-05-19')
            ->call('add')
            ->assertDispatched('confirm-action');

        $this->assertModelExists($task);
        $this->assertDatabaseCount('absences', 0);

        $this->manager()->call('confirmAdd', 'sick', '2026-05-18', '2026-05-19');

        $this->assertModelExists($task);
        $this->assertDatabaseHas('absences', ['user_id' => $this->technician->id, 'type' => 'sick']);
    }

    public function test_invalid_period_is_refused(): void
    {
        $this->manager()->set('from', '2026-05-22')->set('to', '2026-05-18')->call('add')->assertHasErrors('from');
        $this->manager()->set('from', '2026-02-31')->set('to', '2026-03-02')->call('add')->assertHasErrors('from');
        $this->manager()->set('type', 'holiday')->set('from', '2026-05-18')->set('to', '2026-05-18')->call('add')->assertHasErrors('type');

        $this->assertDatabaseCount('absences', 0);
    }

    public function test_new_absence_replaces_overlapping_days_and_removal_frees_exactly_the_period(): void
    {
        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-05-04', 'ends_on' => '2026-05-15']);

        $this->manager()->call('confirmAdd', 'sick', '2026-05-07', '2026-05-08');

        $periods = $this->calendar()['periods'];
        $this->assertSame(
            [['leave', '2026-05-09', '2026-05-15'], ['sick', '2026-05-07', '2026-05-08'], ['leave', '2026-05-04', '2026-05-06']],
            array_map(fn (array $p) => [$p['type']->value, $p['start']->toDateString(), $p['end']->toDateString()], $periods),
        );

        $this->manager()->call('remove', '2026-05-07', '2026-05-08');
        $this->assertSame(10, $this->calendar()['counts']['leave']);
        $this->assertSame(0, $this->calendar()['counts']['sick']);
    }

    public function test_calendar_merges_across_weekends_and_counts_calendar_days(): void
    {
        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-05-14', 'ends_on' => '2026-05-15']);
        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-05-18', 'ends_on' => '2026-05-20']);

        $calendar = $this->calendar();

        $this->assertCount(1, $calendar['periods']);
        $this->assertSame(7, $calendar['periods'][0]['days']);
        $this->assertSame(5, $calendar['total']);
        $this->assertSame(AbsenceType::Leave, $calendar['today']);
    }

    public function test_technician_cannot_manage_absences(): void
    {
        Livewire::actingAs($this->technician)->test(AbsenceManager::class, ['user' => $this->technician])->assertForbidden();
    }

    public function test_people_outside_the_planning_cannot_get_absences(): void
    {
        $office = User::factory()->withRole(Role::DesignOffice)->create();

        Livewire::actingAs($this->supervisor)->test(AbsenceManager::class, ['user' => $office])
            ->set('from', '2026-05-18')->set('to', '2026-05-18')
            ->call('add')
            ->assertForbidden();
    }

    private function manager(): Testable
    {
        return Livewire::actingAs($this->supervisor)->test(AbsenceManager::class, ['user' => $this->technician, 'month' => '2026-05']);
    }

    /**
     * @return array{periods: list<array{type: AbsenceType, start: CarbonImmutable, end: CarbonImmutable, days: int}>, counts: array<string, int>, total: int, today: ?AbsenceType}
     */
    private function calendar(): array
    {
        return app(AbsenceCalendar::class)->forMonth($this->technician->id, CarbonImmutable::parse('2026-05-01'));
    }
}
