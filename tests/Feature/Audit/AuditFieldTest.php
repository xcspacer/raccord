<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.box_suffixes' => ['FT']]);
        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice']);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);

        $this->actingAs($this->technician);
        $operations = app(AuditOperations::class);
        $operations->apply($this->technician, $this->audit, 'o_1', ['op' => 'box_add', 'name' => 'BPE-0001-FT', 'support' => 'pole']);
        $operations->apply($this->technician, $this->audit, 'o_2', ['op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'cables', 'value' => [['code' => 'CAB1X', 'capacity' => '12FO', 'fibre_type' => 'g657']]]);
        $operations->apply($this->technician, $this->audit, 'o_3', ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => 'Hello', 'id' => 'r_hello123']);
        $file = StoredFile::query()->create(['sha256' => 'f', 'path' => 'files/f.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);
        AuditPhoto::query()->create(['audit_id' => $this->audit->id, 'audit_box_id' => $this->audit->boxes()->first()->id, 'folder' => 'pole', 'subfolder' => 'whole', 'stored_file_id' => $file->id, 'captured_at' => now(), 'user_id' => $this->technician->id, 'user_name' => 'Alice', 'received_at' => now()]);
    }

    public function test_the_snapshot_describes_the_whole_audit_for_the_phone(): void
    {
        $json = $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertOk()->assertJsonPath('ok', true)->json();

        $this->assertSame($this->audit->refresh()->revision, $json['revision']);
        $this->assertSame(['id' => $this->technician->id, 'name' => 'Alice', 'manager' => false], $json['viewer']);
        $this->assertSame('PM153', $json['audit']['cabinet']);
        $this->assertSame('full', $json['audit']['mode']);

        $box = $json['boxes'][0];
        $this->assertSame('BPE-0001-FT', $box['name']);
        $this->assertSame('pole', $box['support']);
        $this->assertSame([['code' => 'CAB1X', 'has_no_label' => false, 'capacity' => '12FO', 'fibre_type' => 'g657', 'note' => null]], $box['cables']);
        $this->assertSame('r_hello123', $box['remarks'][0]['id']);
        $this->assertSame('whole', $box['photos'][0]['subfolder']);
        $this->assertStringStartsWith('/audits/photos/', $box['photos'][0]['thumb_url']);
        $this->assertContains('12FO', $json['rules']['capacities']);
        $this->assertCount(3, $json['points']);
        $this->assertTrue($this->actingAs($this->manager)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->json('viewer.manager'));
    }

    public function test_the_snapshot_says_unchanged_when_the_phone_has_the_revision(): void
    {
        $revision = $this->audit->refresh()->revision;

        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153', 'revision' => $revision]))->assertOk()->assertJson(['ok' => true, 'unchanged' => true, 'revision' => $revision]);
        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153', 'revision' => $revision - 1]))->assertOk()->assertJsonPath('boxes.0.name', 'BPE-0001-FT');
    }

    public function test_a_phone_running_an_older_sync_protocol_is_told_to_update_before_reading_the_snapshot(): void
    {
        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']), [EnsureCurrentSyncProtocol::HEADER => (string) (EnsureCurrentSyncProtocol::VERSION - 1)])
            ->assertStatus(426)->assertJson(['ok' => false, 'error' => 'update_required']);
        $this->withoutHeader(EnsureCurrentSyncProtocol::HEADER)->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertStatus(426);
    }

    public function test_only_people_working_on_the_audit_get_the_snapshot_and_the_box_page(): void
    {
        $stranger = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->technician->agency_id]);

        $this->actingAs($stranger)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertNotFound()->assertExactJson(['ok' => false, 'error' => 'audit_not_found']);
        $this->actingAs($stranger)->get(route('audits.box', ['cabinet' => 'PM153', 'name' => 'BPE-0001-FT']))->assertNotFound();
        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM999']))->assertNotFound();
        $this->actingAs($this->technician)->get(route('audits.box', ['cabinet' => 'PM999']))->assertNotFound();
        auth()->logout();
        $this->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertUnauthorized();
        $this->get(route('audits.box', ['cabinet' => 'PM153']))->assertRedirect(route('login'));

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['audits']));
        $modules->save();
        $this->actingAs($this->technician)->getJson(route('audits.snapshot', ['cabinet' => 'PM153']))->assertNotFound();
    }

    public function test_the_box_page_is_a_data_free_shell_with_the_urls_and_texts_the_phone_needs(): void
    {
        $response = $this->actingAs($this->technician)->get(route('audits.box', ['cabinet' => 'PM153', 'name' => 'BPE-0001-FT']))->assertOk();

        $response->assertSee('data-audit-box', false)->assertSee('/audits/PM153/operations', false)->assertSee('/audits/PM153/photos', false)->assertSee('/audits/PM153/snapshot', false);
        $response->assertSee(__('audit.field.take_photo'), false)->assertSee(__('enums.audit_folders.closed'), false);
        $response->assertDontSee('BPE-0001-FT', false);
    }

    public function test_the_pm_page_links_each_box_to_its_screen_and_the_service_worker_is_served(): void
    {
        $this->actingAs($this->technician)->get(route('audits.show', ['cabinet' => 'PM153']))->assertOk()->assertSee(route('audits.box', ['cabinet' => 'PM153', 'name' => 'BPE-0001-FT']), false);

        $this->assertFileExists(public_path('audit-service-worker.js'));
        $this->assertStringContainsString('audit-pages-v1', (string) file_get_contents(public_path('audit-service-worker.js')));
    }
}
