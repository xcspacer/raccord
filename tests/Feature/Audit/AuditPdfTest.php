<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperations;
use App\Audit\AuditPdf;
use App\Audit\OpenAudit;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Jobs\GenerateAuditPdf;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);

        Storage::fake('local');
        Http::fake(['*' => Http::response([], 500)]);
        config(['audit.box_suffixes' => ['FT']]);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice']);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);
        $this->operation($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001', 'support' => 'chamber']);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function operation(User $user, array $op): void
    {
        $this->actingAs($user);
        app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    private function photo(string $folder, array $extra = []): void
    {
        $this->actingAs($this->technician)->post(route('audits.photos.store', ['cabinet' => 'PM153']), $extra + [
            'operation_id' => 'o_'.bin2hex(random_bytes(5)), 'box' => 'BPE-0001', 'folder' => $folder, 'latitude' => 43.9298, 'longitude' => 2.148,
            'file' => UploadedFile::fake()->image(bin2hex(random_bytes(3)).'.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    private function box(): AuditBox
    {
        return $this->audit->boxes()->where('name', 'BPE-0001')->firstOrFail();
    }

    public function test_the_pdf_of_a_box_is_written_with_the_name_of_its_status_and_replaces_the_previous_one(): void
    {
        $this->photo('closed');
        $this->photo('surroundings');
        $this->operation($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'before', 'text' => 'Clean before works']);
        $this->operation($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'route', 'text' => 'RAS']);
        $this->operation($this->technician, ['op' => 'box_cr', 'box' => 'BPE-0001', 'items' => [['id' => 'A', 'label' => 'Lid replaced', 'qty' => 2]]]);
        $this->operation($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'ok']);

        app(AuditPdf::class)->forBox($this->box());
        $first = $this->box();

        $this->assertSame('BPE-0001 - OK - Done.pdf', $first->pdf_name);
        $this->assertNotNull($first->pdf_generated_at);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($first->pdfFile->path));

        $this->operation($this->manager, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'anomaly', 'reason' => 'Cracked']);
        app(AuditPdf::class)->forBox($this->box());

        $second = $this->box();
        $this->assertSame('BPE-0001 - Anomaly.pdf', $second->pdf_name);
        $this->assertNotSame($first->pdf_file_id, $second->pdf_file_id);
        $this->assertSame(1, StoredFile::query()->where('extension', 'pdf')->count());
    }

    public function test_the_pdf_of_a_control_point_carries_its_name(): void
    {
        $point = $this->audit->controlPoints()->where('kind', 'cabinet')->firstOrFail();
        $this->operation($this->technician, ['op' => 'cp_note', 'point' => $point->id, 'text' => 'Door ok']);

        app(AuditPdf::class)->forPoint($point);

        $this->assertSame('Cabinet.pdf', $point->refresh()->pdf_name);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($point->pdfFile->path));
    }

    public function test_a_status_and_the_pdf_operations_queue_the_generation_after_the_response(): void
    {
        Bus::fake();
        $point = $this->audit->controlPoints()->firstOrFail();

        $this->operation($this->technician, ['op' => 'box_pdf', 'box' => 'BPE-0001']);
        $this->operation($this->technician, ['op' => 'cp_pdf', 'point' => $point->id]);
        Bus::assertDispatchedAfterResponse(GenerateAuditPdf::class, 2);

        $this->operation($this->manager, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'partial']);
        Bus::assertDispatchedAfterResponse(GenerateAuditPdf::class, 3);

        $this->operation($this->manager, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => '']);
        Bus::assertDispatchedAfterResponse(GenerateAuditPdf::class, 3);
    }

    public function test_the_job_writes_the_pdf_and_skips_removed_boxes(): void
    {
        (new GenerateAuditPdf('box', $this->box()->id))->handle(app(AuditPdf::class));
        $this->assertSame('BPE-0001.pdf', $this->box()->pdf_name);

        $this->box()->update(['removed_at' => now(), 'pdf_file_id' => null, 'pdf_name' => null]);
        (new GenerateAuditPdf('box', $this->box()->id))->handle(app(AuditPdf::class));
        $this->assertNull($this->box()->pdf_name);
    }

    public function test_only_the_people_working_on_the_audit_download_the_pdf(): void
    {
        $box = $this->box();
        $url = route('audits.pdf', ['cabinet' => 'PM153', 'type' => 'box', 'id' => $box->id]);

        $this->actingAs($this->technician)->get($url)->assertNotFound();

        app(AuditPdf::class)->forBox($box);

        $this->actingAs($this->technician)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->manager)->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($url)->assertForbidden();
        $this->actingAs($this->technician)->get(route('audits.pdf', ['cabinet' => 'PM153', 'type' => 'box', 'id' => 99999]))->assertNotFound();

        $snapshot = $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertOk()->json('boxes.0.pdf');
        $this->assertSame('BPE-0001.pdf', $snapshot['name']);
        $this->assertSame($url, url($snapshot['url']));
    }
}
