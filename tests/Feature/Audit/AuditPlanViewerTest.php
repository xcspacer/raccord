<?php

namespace Tests\Feature\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\OpenAudit;
use App\Audit\Spreadsheet\SheetReader;
use App\Enums\AuditPlanKind;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPlan;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AuditPlanViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Audit $audit;

    private AuditPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('BOITE');
        $sheet->setCellValue('A1', 'Title')->mergeCells('A1:C1');
        $sheet->setCellValue('A2', 'Red')->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FF0000');
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getColumnDimension('B')->setVisible(false);
        $hidden = $book->createSheet()->setTitle('SECRET');
        $hidden->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        (new Xlsx($book))->save($path);

        $file = app(StoreUploadedFile::class)->handle(new UploadedFile($path, 'plan.xlsx', null, null, true));
        $this->plan = AuditPlan::query()->create(['audit_id' => $this->audit->id, 'kind' => AuditPlanKind::Box, 'name' => 'plan.xlsx', 'stored_file_id' => $file->id]);
    }

    public function test_the_reader_keeps_visible_sheets_merges_colours_and_hidden_columns(): void
    {
        $book = app(SheetReader::class)->read(Storage::disk('local')->path($this->plan->file->path));

        $this->assertSame(['BOITE'], array_column($book['sheets'], 'name'));
        $sheet = $book['sheets'][0];
        $this->assertSame([[1, 1, 1, 3]], $sheet['merges']);
        $this->assertTrue($sheet['columns'][1]['hidden']);
        $this->assertSame('Red', $sheet['cells'][1][0]['v']);
        $style = $book['styles'][$sheet['cells'][1][0]['s']];
        $this->assertSame('#ff0000', $style['bg']);
        $this->assertTrue($style['bold']);
    }

    public function test_a_sheet_without_merged_cells_is_read(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'only');
        $path = tempnam(sys_get_temp_dir(), 'plain').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->assertSame('only', app(SheetReader::class)->read($path)['sheets'][0]['cells'][0][0]['v']);
    }

    public function test_the_people_working_on_the_audit_open_the_viewer_and_the_others_do_not(): void
    {
        $page = route('audits.plans.show', ['cabinet' => 'PM153', 'plan' => $this->plan->id]);
        $data = route('audits.plans.data', ['cabinet' => 'PM153', 'plan' => $this->plan->id]);

        $this->actingAs($this->technician)->get($page)->assertOk()->assertSee('plan.xlsx');
        $this->actingAs($this->manager)->getJson($data)->assertOk()->assertJsonPath('sheets.0.name', 'BOITE');
        $stranger = User::factory()->withRole(Role::Technician)->create();
        $this->actingAs($stranger)->get($page)->assertForbidden();
        $this->actingAs($stranger)->getJson($data)->assertForbidden();
        $this->actingAs($this->manager)->get(route('audits.plans.show', ['cabinet' => 'PM153', 'plan' => 9999]))->assertNotFound();
    }
}
