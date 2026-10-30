<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Enums\AuditPlanKind;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AUTZ-09/16: for a technician who is not planned on the cabinet the audit does not exist (404) on every page and end
 * point; one who works on it but asks for a managers' action is refused (403), as is anyone without the audits access.
 */
class AuditScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private User $stranger;

    private Audit $audit;

    private int $boxId;

    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);
        Storage::fake('local');
        config(['audit.box_suffixes' => ['FT']]);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $this->stranger = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);

        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->actingAs($this->technician);
        app(AuditOperations::class)->apply($this->technician, $this->audit, 'o_1', ['op' => 'box_add', 'name' => 'BPE-0001-FT', 'support' => 'pole']);

        Storage::disk('local')->put('files/plan.pdf', '%PDF-1.4 x');
        $file = StoredFile::query()->create(['sha256' => str_repeat('a', 64), 'disk' => 'local', 'path' => 'files/plan.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 10]);
        $this->audit->update(['synoptic_file_id' => $file->id, 'synoptic_name' => 'synoptic.pdf']);
        $box = $this->audit->boxes()->firstOrFail();
        $box->update(['pdf_file_id' => $file->id, 'pdf_name' => 'BPE-0001-FT.pdf']);
        $this->boxId = $box->id;
        $this->planId = AuditPlan::query()->create(['audit_id' => $this->audit->id, 'kind' => AuditPlanKind::Box, 'name' => 'plan.pdf', 'stored_file_id' => $file->id])->id;
    }

    /**
     * Pages and downloads the technician planned on the cabinet opens.
     *
     * @return list<string>
     */
    private function urls(): array
    {
        return [
            route('audits.show', ['cabinet' => 'PM153']),
            route('audits.box', ['cabinet' => 'PM153']),
            route('audits.point', ['cabinet' => 'PM153']),
            route('audits.snapshot', ['cabinet' => 'PM153']),
            route('audits.files', ['cabinet' => 'PM153', 'kind' => 'synoptic']),
            route('audits.files', ['cabinet' => 'PM153', 'kind' => 'plan', 'plan' => $this->planId]),
            route('audits.pdf', ['cabinet' => 'PM153', 'type' => 'box', 'id' => $this->boxId]),
            route('audits.plans.show', ['cabinet' => 'PM153', 'plan' => $this->planId]),
        ];
    }

    public function test_a_technician_not_planned_on_the_cabinet_gets_not_found_everywhere(): void
    {
        foreach ($this->urls() as $url) {
            $this->actingAs($this->technician)->get($url)->assertOk();
            $this->actingAs($this->stranger)->get($url)->assertNotFound();
        }

        $this->actingAs($this->stranger)->get(route('audits.recheck', ['cabinet' => 'PM153', 'box' => $this->boxId]))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('audits.drawer', ['cabinet' => 'PM153']))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('audits.synthesis', ['cabinet' => 'PM153']))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('audits.show', ['cabinet' => 'PM999']))->assertNotFound();
    }

    public function test_the_phone_end_points_answer_not_found_the_same_way_as_for_a_missing_audit(): void
    {
        $operation = fn (string $id) => ['op' => ['op' => 'box_add', 'name' => 'BPE-0002', 'operation_id' => $id]];
        $photo = ['operation_id' => 'o_p', 'box' => 'BPE-0001-FT', 'folder' => 'closed', 'file' => UploadedFile::fake()->image('a.jpg', 40, 30)];
        $missing = ['ok' => false, 'error' => 'audit_not_found'];

        foreach (['PM153', 'PM999'] as $cabinet) {
            $this->actingAs($this->stranger)->getJson(route('audits.snapshot', ['cabinet' => $cabinet]))->assertNotFound()->assertExactJson($missing);
            $this->actingAs($this->stranger)->postJson(route('audits.operations', ['cabinet' => $cabinet]), $operation('o_'.$cabinet))->assertNotFound()->assertExactJson($missing);
            $this->actingAs($this->stranger)->post(route('audits.photos.store', ['cabinet' => $cabinet]), $photo, ['Accept' => 'application/json'])->assertNotFound()->assertExactJson($missing);
        }

        $this->assertSame(1, $this->audit->boxes()->count());
        $this->actingAs($this->technician)->postJson(route('audits.operations', ['cabinet' => 'PM153']), $operation('o_ok'))->assertOk();
    }

    public function test_a_managers_action_on_a_visible_audit_and_the_audits_access_switched_off_are_forbidden(): void
    {
        $this->actingAs($this->technician)->get(route('audits.synthesis', ['cabinet' => 'PM153']))->assertForbidden();

        $outsider = User::factory()->withRole(Role::Technician)->create(['agency_id' => Agency::factory()->create(['planning_mode' => PlanningMode::Detailed])->id]);
        $this->actingAs($outsider)->get(route('audits.show', ['cabinet' => 'PM153']))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertForbidden();
        $this->actingAs($outsider)->postJson(route('audits.operations', ['cabinet' => 'PM153']), ['op' => ['op' => 'box_add', 'name' => 'BPE-0003', 'operation_id' => 'o_x']])
            ->assertForbidden()->assertJson(['ok' => false, 'error' => 'forbidden']);
    }
}
