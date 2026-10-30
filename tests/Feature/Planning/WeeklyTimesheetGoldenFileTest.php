<?php

namespace Tests\Feature\Planning;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\DayPunch;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlanningHiddenWeek;
use App\Planning\WeeklyTimesheetExport;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ComparesWithLegacyWorkbooks;
use Tests\Support\WorkbookSnapshot;
use Tests\TestCase;

/**
 * Golden file of the weekly time sheet of the planning: compared cell by cell with what the legacy platform wrote for
 * the same invented week (tests/fixtures/legacy/planning-timesheet.xlsx), in French.
 */
class WeeklyTimesheetGoldenFileTest extends TestCase
{
    use ComparesWithLegacyWorkbooks;
    use RefreshDatabase;

    public function test_the_weekly_time_sheet_is_the_legacy_one(): void
    {
        app()->setLocale('fr');
        $this->travelTo(CarbonImmutable::parse('2026-10-20T13:00:00Z'));
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily, 'name' => 'Sud']);
        $other = Agency::factory()->create(['planning_mode' => PlanningMode::Daily, 'name' => 'Nord']);
        $person = fn (string $name, int $order, array $attributes = []) => User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => $name, 'planning_order' => $order] + $attributes);
        $alice = $person('Alice Martin', 2);
        $bruno = $person('Bruno Petit', 1);
        $chloe = $person('Chloé Roux', 0);
        $person('Denis Blanc', 3, ['departure_date' => '2026-10-22']);
        $elodie = $person('Élodie Faure', 4);
        $fabien = $person('Fabien Morel', 5);
        User::factory()->withRole(Role::Technician)->create(['agency_id' => $other->id, 'name' => 'Gaëlle Noir', 'planning_order' => 6]);
        $manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id, 'name' => 'Sam Leroy']);
        foreach ([$elodie, $fabien] as $hidden) {
            UserPlanningHiddenWeek::query()->create(['user_id' => $hidden->id, 'week_starts_on' => '2026-10-19']);
        }

        $task = fn (User $user, string $date, string $label, string $detail = '', ?string $note = null) => Task::factory()->create(['user_id' => $user->id, 'date' => $date, 'label' => $label, 'detail' => $detail, 'note' => $note]);
        $task($bruno, '2026-10-19', 'RACCORDEMENT', 'PM901 rue des Lilas', 'Prévoir nacelle');
        $task($alice, '2026-10-19', 'AUDIT');
        $task($alice, '2026-10-20', 'AUDIT', 'PM902');
        $task($chloe, '2026-10-21', 'Formation');
        $task($elodie, '2026-10-22', 'Tirage', 'Lot 3');
        $task($alice, '2026-10-24', 'Astreinte');
        Absence::factory()->create(['user_id' => $bruno->id, 'type' => 'sick', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'note' => 'certificat']);
        Absence::factory()->create(['user_id' => $chloe->id, 'type' => 'leave', 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-23']);

        $punch = fn (User $user, string $date, array $attributes) => DayPunch::query()->create(['user_id' => $user->id, 'date' => $date, 'start_received_at' => $attributes['start_at'], 'end_received_at' => $attributes['end_at'] ?? null] + $attributes);
        $punch($bruno, '2026-10-19', ['start_at' => '2026-10-19T05:58:00Z', 'start_latitude' => 43.9281, 'start_longitude' => 2.1484, 'start_accuracy' => 10, 'end_at' => '2026-10-19T15:02:00Z', 'end_latitude' => 43.9301, 'end_longitude' => 2.1502, 'end_accuracy' => 12]);
        $punch($alice, '2026-10-19', ['start_at' => '2026-10-19T06:10:00Z', 'start_latitude' => 43.91, 'start_longitude' => 2.15, 'start_accuracy' => 8]);
        $punch($alice, '2026-10-20', ['start_at' => '2026-10-20T06:05:00Z', 'start_latitude' => 43.92, 'start_longitude' => 2.16, 'start_accuracy' => 8]);
        $punch($chloe, '2026-10-19', ['start_at' => '2026-10-19T06:00:00Z', 'start_geo_missing' => true, 'end_at' => '2026-10-19T14:30:00Z', 'end_latitude' => 43.9, 'end_longitude' => 2.1, 'end_accuracy' => 15, 'end_declared' => true]);
        $punch($bruno, '2026-10-20', ['start_at' => '2026-10-20T05:30:00Z', 'start_latitude' => 43.9, 'start_longitude' => 2.1, 'start_accuracy' => 9]);

        $book = app(WeeklyTimesheetExport::class)->build($manager, $agency, CarbonImmutable::parse('2026-10-19'));
        $path = (string) tempnam(sys_get_temp_dir(), 'golden');
        (new Xlsx($book))->save($path);
        $actual = WorkbookSnapshot::of($path);
        @unlink($path);

        $this->assertSameAsLegacy('planning-timesheet.xlsx', $actual, ['{team}' => 'SUD', '{app}' => app(CompanySettings::class)->displayName()], function (array $expected): array {
            // Decided (D9): a line under the table says the positions are for internal use.
            $sheet = 'Pointage S43';
            $expected[$sheet]['merges'][] = 'A39:F39';
            sort($expected[$sheet]['merges']);
            $expected[$sheet]['cells']['A39'] = ['value' => __('weekly_timesheet.location_notice', ['metres' => (int) config('platform.location_accuracy_warning_metres')])] + array_diff_key($expected[$sheet]['cells']['A38'], ['value' => true]);

            return $expected;
        });
    }
}
