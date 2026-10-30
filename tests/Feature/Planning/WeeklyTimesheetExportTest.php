<?php

namespace Tests\Feature\Planning;

use App\Enums\AbsenceType;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Planning\TaskGrid;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\DayPunch;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlanningHiddenWeek;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class WeeklyTimesheetExportTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily, 'name' => 'North']);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id, 'name' => 'Sam Supervisor']);
        $this->alice = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Alice', 'planning_order' => 1]);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Bruno', 'planning_order' => 2]);
    }

    private function sheet(?string $week = '2026-11-02', ?User $as = null): Worksheet
    {
        $response = $this->actingAs($as ?? $this->supervisor)->get(route('tasks.timesheet', ['week' => $week]));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path)->getActiveSheet();
    }

    /**
     * @return list<string>
     */
    private function column(Worksheet $sheet, string $column): array
    {
        $values = [];

        for ($row = 7; $row <= $sheet->getHighestRow(); $row++) {
            $values[] = (string) $sheet->getCell($column.$row)->getValue();
        }

        return $values;
    }

    public function test_the_sheet_lists_each_day_and_person_with_work_absence_and_punches(): void
    {
        Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'label' => 'Splicing', 'detail' => 'Box 12', 'note' => 'Bring the ladder']);
        Absence::factory()->create(['user_id' => $this->bruno->id, 'type' => AbsenceType::Sick, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03']);
        DayPunch::query()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'start_at' => CarbonImmutable::parse('2026-11-02 07:58', 'Europe/Paris')->utc(), 'start_received_at' => now(), 'start_latitude' => 43.9298, 'start_longitude' => 2.148, 'end_at' => CarbonImmutable::parse('2026-11-02 17:12', 'Europe/Paris')->utc(), 'end_declared' => true]);
        DayPunch::query()->create(['user_id' => $this->alice->id, 'date' => '2026-11-03', 'start_at' => CarbonImmutable::parse('2026-11-03 08:01', 'Europe/Paris')->utc(), 'start_received_at' => now()]);

        $sheet = $this->sheet();

        $this->assertSame('WEEKLY TIME SHEET — TEAM NORTH', $sheet->getCell('C2')->getValue());
        $this->assertSame('Time sheet W45', $sheet->getTitle());
        $this->assertSame('Technician', $sheet->getCell('C6')->getValue());
        $this->assertSame('FFF2A104', $sheet->getStyle('A6')->getFill()->getStartColor()->getARGB());

        $people = $this->column($sheet, 'C');
        $this->assertSame(['Alice', 'Bruno', 'Alice', 'Bruno', 'Alice', 'Bruno', 'Alice', 'Bruno', 'Alice', 'Bruno'], array_slice($people, 0, 10));

        $work = $this->column($sheet, 'D');
        $this->assertSame("Splicing — Box 12\nNote: Bring the ladder", $work[0]);
        $this->assertSame(AbsenceType::Sick->label(), $work[1]);
        $this->assertSame('—', $work[2]);

        $this->assertSame('07:58  📍', $sheet->getCell('E7')->getValue());
        $this->assertSame('https://www.google.com/maps?q=43.9298,2.148', $sheet->getCell('E7')->getHyperlink()->getUrl());
        $this->assertSame('17:12 *', $sheet->getCell('F7')->getValue());
        $this->assertSame('', $sheet->getCell('F7')->getHyperlink()->getUrl());
        $this->assertSame('Not finished', $sheet->getCell('F9')->getValue());
        $this->assertNull($sheet->getCell('E8')->getValue());
    }

    public function test_imprecise_positions_are_marked_and_the_sheet_says_locations_are_internal(): void
    {
        DayPunch::query()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'start_at' => CarbonImmutable::parse('2026-11-02 07:58', 'Europe/Paris')->utc(), 'start_received_at' => now(), 'start_latitude' => 43.9298, 'start_longitude' => 2.148, 'start_accuracy' => 250, 'end_at' => CarbonImmutable::parse('2026-11-02 17:12', 'Europe/Paris')->utc(), 'end_latitude' => 43.9, 'end_longitude' => 2.1, 'end_accuracy' => 30]);

        $sheet = $this->sheet();

        $this->assertSame('07:58  📍 ⚠', $sheet->getCell('E7')->getValue());
        $this->assertSame('https://www.google.com/maps?q=43.9298,2.148', $sheet->getCell('E7')->getHyperlink()->getUrl());
        $this->assertSame('17:12  📍', $sheet->getCell('F7')->getValue());
        $this->assertContains(__('weekly_timesheet.location_notice', ['metres' => 200]), $this->column($sheet, 'A'));
    }

    public function test_names_and_task_texts_starting_like_a_formula_stay_text(): void
    {
        $this->alice->update(['name' => '=HYPERLINK("https://example.test","open")']);
        Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-02', 'label' => '+SUM(1,2)', 'detail' => null, 'note' => '@cmd']);

        $sheet = $this->sheet();

        $this->assertSame('=HYPERLINK("https://example.test","open")', $sheet->getCell('C7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C7')->getDataType());
        $this->assertStringStartsWith('+SUM(1,2)', (string) $sheet->getCell('D7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D7')->getDataType());
    }

    public function test_a_running_day_is_marked_in_progress_and_saturday_only_appears_with_a_task(): void
    {
        DayPunch::query()->create(['user_id' => $this->alice->id, 'date' => '2026-11-04', 'start_at' => CarbonImmutable::parse('2026-11-04 08:00', 'Europe/Paris')->utc(), 'start_received_at' => now()]);

        $names = fn (Worksheet $sheet): array => array_filter($this->column($sheet, 'C'), fn (string $name) => in_array($name, ['Alice', 'Bruno'], true));

        $withoutSaturday = $this->sheet();
        $this->assertSame('In progress', $withoutSaturday->getCell('F11')->getValue());
        $this->assertCount(10, $names($withoutSaturday));

        Task::factory()->create(['user_id' => $this->alice->id, 'date' => '2026-11-07', 'label' => 'Saturday job']);

        $withSaturday = $this->sheet();
        $this->assertCount(12, $names($withSaturday));
    }

    public function test_people_removed_from_the_week_only_appear_when_they_have_entries_and_departed_people_are_marked(): void
    {
        UserPlanningHiddenWeek::query()->create(['user_id' => $this->bruno->id, 'week_starts_on' => '2026-11-02']);

        $this->assertNotContains('Bruno', $this->column($this->sheet(), 'C'));

        Task::factory()->create(['user_id' => $this->bruno->id, 'date' => '2026-11-03', 'label' => 'Extra']);
        $this->assertContains('Bruno', $this->column($this->sheet(), 'C'));

        $this->alice->update(['departure_date' => '2026-11-04']);
        $sheet = $this->sheet();
        $rows = array_keys(array_filter($this->column($sheet, 'C'), fn (string $name) => $name === 'Alice'));
        $this->assertContains('Left the company', array_map(fn (int $index) => $sheet->getCell('D'.($index + 7))->getValue(), $rows));
    }

    public function test_only_managers_with_the_feature_can_export_and_the_button_follows(): void
    {
        $this->actingAs($this->alice)->get(route('tasks.timesheet'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]))->get(route('tasks.timesheet'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('tasks.timesheet'))->assertOk();
        auth()->logout();
        $this->get(route('tasks.timesheet'))->assertRedirect(route('login'));

        Livewire::actingAs($this->supervisor)->test(TaskGrid::class)->assertSee(__('weekly_timesheet.button'));
        Livewire::actingAs($this->alice)->test(TaskGrid::class)->assertDontSee(__('weekly_timesheet.button'));
    }

    public function test_detailed_agencies_have_no_task_export(): void
    {
        $detailed = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);

        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $detailed->id]))->get(route('tasks.timesheet'))->assertNotFound();
    }
}
