<?php

namespace Tests\Feature\Absences;

use App\Enums\AbsenceType;
use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Absences\AbsenceIndex;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class AbsenceExportTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-20 10:00', 'Europe/Paris'));
        $this->seed([RoleSeeder::class, PublicHolidaySeeder::class]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => Agency::factory()->create(['planning_mode' => PlanningMode::Daily])->id]);
    }

    private function absence(User $user, AbsenceType $type, string $from, string $to): void
    {
        Absence::factory()->create(['user_id' => $user->id, 'type' => $type, 'starts_on' => $from, 'ends_on' => $to]);
    }

    private function sheet(Testable $component): Worksheet
    {
        $download = $component->effects['download'];
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, base64_decode($download['content']));

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    public function test_one_line_per_person_with_the_periods_and_the_month_totals(): void
    {
        $lea = User::factory()->withRole(Role::Technician)->create(['name' => 'Léa Martin']);
        $bob = User::factory()->withRole(Role::Technician)->create(['name' => 'Bob Stone']);
        $gone = User::factory()->withRole(Role::Technician)->create(['name' => 'Gone Person', 'departure_date' => '2026-04-30']);

        $this->absence($lea, AbsenceType::Leave, '2026-04-28', '2026-05-05');
        $this->absence($lea, AbsenceType::Sick, '2026-05-12', '2026-05-12');
        $this->absence($bob, AbsenceType::Absent, '2026-05-18', '2026-05-19');
        $this->absence($gone, AbsenceType::Absent, '2026-05-04', '2026-05-04');

        $component = Livewire::actingAs($this->supervisor)->test(AbsenceIndex::class)->set('month', '2026-05')
            ->call('export')->assertFileDownloaded('absences-2026-05.xlsx');
        $sheet = $this->sheet($component);

        $this->assertSame('Absences and Leave — May 2026', $sheet->getCell('C2')->getValue());
        $this->assertSame(['Technician', 'Periods', 'Absent', 'Leave', 'Sick leave', 'Total days', 'Working days'], $sheet->rangeToArray('C6:I6')[0]);

        $this->assertEquals(['Bob Stone', 'Absent: 18 → 19 May', 2, null, null, 2, 2], $sheet->rangeToArray('C7:I7', null, false)[0]);
        $this->assertEquals(['Léa Martin', "Leave: 28 Apr → 05 May\nSick: 12 May", null, 5, 1, 6, 3], $sheet->rangeToArray('C8:I8', null, false)[0]);
        $this->assertEquals(['TOTAL', '2 technicians', 2, 5, 1, 8, 5], $sheet->rangeToArray('C9:I9', null, false)[0]);
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('H8')->getDataType());
        $this->assertStringContainsString('Working days', (string) $sheet->getCell('C11')->getValue());
    }

    public function test_names_are_written_as_text_never_as_formulas(): void
    {
        $person = User::factory()->withRole(Role::Technician)->create(['name' => '=HYPERLINK("http://evil.test","x")']);
        $this->absence($person, AbsenceType::Absent, '2026-05-06', '2026-05-06');

        $sheet = $this->sheet(Livewire::actingAs($this->supervisor)->test(AbsenceIndex::class)->call('export'));

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C7')->getDataType());
        $this->assertSame('=HYPERLINK("http://evil.test","x")', $sheet->getCell('C7')->getValue());
    }

    public function test_a_month_without_absences_gives_a_notice_instead_of_a_file(): void
    {
        User::factory()->withRole(Role::Technician)->create();

        Livewire::actingAs($this->supervisor)->test(AbsenceIndex::class)->set('month', '2026-03')->call('export')
            ->assertNoFileDownloaded()
            ->assertDispatched('toast', message: __('absences.export.empty', ['month' => 'march 2026']));
    }

    public function test_the_export_follows_the_absences_feature(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->absence($technician, AbsenceType::Absent, '2026-05-06', '2026-05-06');

        Livewire::actingAs($technician)->test(AbsenceIndex::class)->assertForbidden();

        $component = Livewire::actingAs($this->supervisor)->test(AbsenceIndex::class);
        RoleFeatureOverride::query()->create(['role_id' => SpatieRole::findByName(Role::Supervisor->value)->id, 'feature' => Feature::Absences->value, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();

        $component->call('export')->assertForbidden();
    }
}
