<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Audit\Spreadsheet\AuditSynthesis;
use App\Audit\Spreadsheet\AuditTimesheet;
use App\Enums\AuditMode;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Audit\AuditTimesheetPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;

class AuditExcelReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.box_suffixes' => ['FT']]);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id, 'name' => 'Sam']);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice']);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->audit->update(['mode' => AuditMode::Optical, 'sro' => 'SRO-1']);
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001-FT', 'support' => 'chamber']);
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0002-FT', 'support' => 'chamber']);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function send(User $user, array $op): void
    {
        $this->actingAs($user);
        app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    private function photo(string $box, User $by, bool $imported = false, ?\DateTimeInterface $at = null): void
    {
        $file = StoredFile::query()->create(['sha256' => bin2hex(random_bytes(32)), 'disk' => 'local', 'path' => 'x/'.bin2hex(random_bytes(4)).'.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1, 'reference_count' => 1]);
        AuditPhoto::query()->create([
            'audit_id' => $this->audit->id, 'audit_box_id' => $this->audit->boxes()->where('name', $box)->value('id'), 'folder' => 'closed', 'subfolder' => '', 'stored_file_id' => $file->id,
            'captured_at' => $at ?? now(), 'user_id' => $by->id, 'user_name' => $by->name, 'is_imported' => $imported, 'received_at' => now(),
        ]);
    }

    public function test_the_synthesis_has_one_line_per_box_with_position_tco_and_status(): void
    {
        $this->photo('BPE-0001-FT', $this->technician);
        $this->send($this->technician, ['op' => 'box_pos', 'box' => 'BPE-0001-FT', 'latitude' => 43.9, 'longitude' => 2.1, 'accuracy' => 7]);
        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0002-FT', 'status' => 'partial']);

        $book = app(AuditSynthesis::class)->build($this->audit->refresh(), $this->manager);
        $sheet = $book->getActiveSheet();

        $this->assertSame('PM153 · ', substr((string) $sheet->getCell('A1')->getValue(), 0, 0).'PM153 · ');
        $this->assertSame(__('audit.synthesis.columns.box'), $sheet->getCell('A4')->getValue());
        $this->assertSame('BPE-0001-FT', $sheet->getCell('A5')->getValue());
        $this->assertStringContainsString('43.900000, 2.100000', (string) $sheet->getCell('E5')->getValue());
        $this->assertSame('± 7 m', $sheet->getCell('F5')->getValue());
        $this->assertSame(__('audit.synthesis.not_confirmed'), $sheet->getCell('E6')->getValue());
        $this->assertSame(__('enums.audit_box_statuses.partial'), $sheet->getCell('C6')->getValue());
        $this->assertSame(0, $sheet->getCell('G6')->getValue());
    }

    public function test_names_and_reasons_typed_by_people_are_written_as_text_never_as_formulas(): void
    {
        $formula = '=HYPERLINK("https://example.test","open")';
        $this->technician->update(['name' => $formula]);
        $this->photo('BPE-0001-FT', $this->technician->refresh());
        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'anomaly', 'reason' => '=1+1']);
        $today = CarbonImmutable::now(config('platform.timezone'));

        $sheets = [
            app(AuditSynthesis::class)->build($this->audit->refresh(), $this->manager)->getActiveSheet(),
            app(AuditTimesheet::class)->build($this->manager, $today->startOfWeek(), $today->startOfWeek()->addDays(6))?->getActiveSheet(),
        ];
        $texts = [];

        foreach ($sheets as $sheet) {
            $this->assertNotNull($sheet);

            foreach ($sheet->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType(), "{$coordinate} holds a formula.");
                $texts[] = (string) $cell->getValue();
            }
        }

        $this->assertContains($formula, $texts);
        $this->assertTrue(collect($texts)->contains(fn (string $text) => str_contains($text, '=1+1')));
    }

    public function test_the_synthesis_download_is_for_managers_and_the_optical_mode_only(): void
    {
        $url = route('audits.synthesis', ['cabinet' => 'PM153']);

        $this->actingAs($this->technician)->get($url)->assertForbidden();
        $this->actingAs($this->manager)->get($url)->assertOk()->assertDownload('Synthesis-PM153-optical.xlsx');

        $this->audit->update(['mode' => AuditMode::Full]);
        $this->actingAs($this->manager)->get($url)->assertNotFound();
    }

    public function test_the_timesheet_counts_what_each_technician_did_each_day_without_imports_or_manager_statuses(): void
    {
        $today = CarbonImmutable::now(config('platform.timezone'));
        $this->photo('BPE-0001-FT', $this->technician);
        $this->photo('BPE-0001-FT', $this->technician);
        $this->photo('BPE-0002-FT', $this->technician, imported: true);
        $this->photo('BPE-0002-FT', $this->technician, at: now()->subMonths(3));
        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'anomaly', 'reason' => 'Cracked']);
        $this->send($this->manager, ['op' => 'box_status', 'box' => 'BPE-0002-FT', 'status' => 'ok']);

        $book = app(AuditTimesheet::class)->build($this->manager, $today->startOfWeek(), $today->startOfWeek()->addDays(6));
        $sheet = $book->getActiveSheet();
        $found = collect($sheet->toArray(null, true, false))->first(fn (array $row) => $row[2] === 'Alice' && str_contains((string) $row[3], 'PM153'));

        $this->assertNotNull($found);
        $this->assertStringContainsString('BPE-0001-FT ⚠', $found[3]);
        $this->assertStringNotContainsString('BPE-0002-FT', $found[3]);
        $this->assertSame([1, 2], [$found[4], $found[5]]);
    }

    public function test_a_period_without_activity_gives_no_file_and_the_planned_technician_is_listed_without_photo(): void
    {
        $this->assertNull(app(AuditTimesheet::class)->build($this->manager, CarbonImmutable::parse('2020-01-06'), CarbonImmutable::parse('2020-01-11')));

        $site = WorkSite::query()->where('cabinet_ref', 'PM153')->firstOrFail();
        Task::query()->create(['user_id' => $this->technician->id, 'date' => '2020-01-07', 'state_code' => 'HO', 'work_site_id' => $site->id, 'label' => 'AUDIT']);
        $book = app(AuditTimesheet::class)->build($this->manager, CarbonImmutable::parse('2020-01-06'), CarbonImmutable::parse('2020-01-11'));

        $this->assertNotNull($book);
        $this->assertTrue(collect($book->getActiveSheet()->toArray())->contains(fn (array $row) => $row[2] === 'Alice' && $row[3] === __('audit.timesheet.planned_without_photo')));
    }

    public function test_only_managers_use_the_timesheet_page_and_download_validates_the_dates(): void
    {
        Livewire::actingAs($this->technician)->test(AuditTimesheetPage::class)->assertForbidden();
        Livewire::actingAs($this->manager)->test(AuditTimesheetPage::class)->call('preset', 'lastmonth')->assertSet('from', CarbonImmutable::today(config('platform.timezone'))->subMonthNoOverflow()->startOfMonth()->toDateString());

        $download = route('audits.weekly-timesheet.download');
        $this->actingAs($this->technician)->get($download.'?from=2026-01-01&to=2026-01-07')->assertForbidden();
        $this->actingAs($this->manager)->get($download.'?from=nope&to=2026-01-07')->assertSessionHasErrors('from');
        $this->actingAs($this->manager)->get($download.'?from=2020-01-06&to=2020-01-11')->assertNotFound();

        $today = CarbonImmutable::now(config('platform.timezone'));
        $this->photo('BPE-0001-FT', $this->technician);
        $response = $this->actingAs($this->manager)->get($download.'?from='.$today->startOfWeek()->toDateString().'&to='.$today->startOfWeek()->addDays(5)->toDateString())->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('content-disposition'));
    }
}
