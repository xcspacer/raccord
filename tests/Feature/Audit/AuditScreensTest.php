<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Enums\AuditMode;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Audit\AuditConfigure;
use App\Livewire\Audit\AuditIndex;
use App\Livewire\Audit\AuditPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditScreensTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $manager;

    private User $technician;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.box_suffixes' => ['FT']]);
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id, 'name' => 'Sam']);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Alice']);
        $this->stranger = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);
    }

    private function site(string $cabinet, ?string $mode = 'full', ?User $lead = null): WorkSite
    {
        return WorkSite::factory()->create([
            'agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit->value, 'audit_mode' => $mode, 'cabinet_ref' => $cabinet, 'city' => 'Dijon',
            'starts_on' => '2026-11-03', 'ends_on' => '2026-11-05', 'lead_technician_id' => ($lead ?? $this->technician)->id,
        ]);
    }

    private function operate(User $user, Audit $audit, array $op): void
    {
        $this->actingAs($user);
        app(AuditOperations::class)->apply($user, $audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    public function test_the_list_shows_planned_cabinets_with_state_and_progress_for_the_chosen_mode(): void
    {
        $this->site('PM153');
        $this->site('PM200', 'optical');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1']);
        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'BPE-0001']);
        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'BPE-0002']);
        $this->operate($this->technician, $audit, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'not_installed']);

        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'full'])
            ->assertSee('PM153')->assertSee('SRO-1')->assertDontSeeHtml('audit__chip--in_progress')->assertSee(__('audit.boxes_done', ['done' => 1, 'total' => 2]))->assertDontSee('PM200');
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'optical'])->assertSee('PM200')->assertSee(__('audit.states.not_configured'))->assertDontSee('PM153');
    }

    public function test_an_opened_audit_without_sro_shows_its_real_state_and_progress(): void
    {
        $this->site('PM300');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM300');
        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'BPE-0001']);
        $this->operate($this->technician, $audit, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'not_installed']);
        $this->assertNull($audit->refresh()->sro);

        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'full'])
            ->assertSee('PM300')->assertDontSee(__('audit.states.not_configured'))
            ->assertSee(__('audit.boxes_done', ['done' => 1, 'total' => 1]))->assertSeeHtml('audit__bar');
    }

    public function test_technicians_only_see_the_cabinets_they_are_planned_on(): void
    {
        $this->site('PM153');
        $this->site('PM300', 'full', $this->stranger);

        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'full'])->assertSee('PM153')->assertDontSee('PM300');
        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'optical'])->assertSee(__('audit.empty_technician'));
        Livewire::actingAs($this->manager)->test(AuditIndex::class, ['mode' => 'full'])->assertSee('PM153')->assertSee('PM300');
    }

    public function test_the_menu_pages_follow_the_module_switch_and_the_role(): void
    {
        $this->site('PM153');

        $this->actingAs($this->manager)->get(route('audits.full'))->assertOk()->assertSee('PM153');
        $this->actingAs($this->technician)->get(route('audits.optical'))->assertOk();

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['audits']));
        $modules->save();
        $this->actingAs($this->manager)->get(route('audits.full'))->assertNotFound();
    }

    public function test_the_page_of_a_cabinet_shows_progress_boxes_points_and_filters(): void
    {
        $this->site('PM153');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1']);

        foreach (['BPE-0001-FT', 'BPE-0002', 'PB0005'] as $name) {
            $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => $name]);
        }

        $this->operate($this->technician, $audit, ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'before', 'text' => 'Clean']);
        $this->operate($this->technician, $audit, ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'route', 'text' => 'RAS']);
        $this->operate($this->technician, $audit, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'anomaly', 'reason' => 'Cracked']);
        $this->operate($this->manager, $audit, ['op' => 'remark', 'box' => 'BPE-0002', 'text' => 'Missing photo']);
        $this->operate($this->technician, $audit, ['op' => 'presence', 'state' => 'on_box', 'box' => 'BPE-0002']);

        $page = Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])
            ->assertSee('BPE-0001-FT')->assertSee('BPE-0002')->assertSee('PB0005')->assertSee('SRO-1')->assertSee(__('audit.points_title'))
            ->assertSee('Satellite chamber')->assertSee('📣')->assertSee('📍 Alice → BPE-0002')->assertSee(__('enums.audit_box_statuses.anomaly'));

        $page->set('kindFilter', 'pbo')->assertSee('PB0005')->assertDontSee('BPE-0001-FT');
        $page->set('kindFilter', '')->set('search', 'bpe-0002')->assertSee('BPE-0002')->assertDontSee('PB0005');
    }

    public function test_the_cabinet_page_groups_boxes_by_family_with_support_filters_and_opens_the_add_windows(): void
    {
        $this->site('PM153');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1', 'mode' => 'full']);
        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'BPE-0001', 'support' => 'chamber']);
        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'PB0005', 'support' => 'pole']);

        $page = Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])
            ->assertSeeInOrder(['SRO-1', __('audit.points_title'), __('audit.add_point'), __('audit.boxes'), 'BPE-0001', 'PB0005', __('audit.add_box')])
            ->assertSee('🔌 '.__('audit.support_filters.aerial'))->assertSee('🕳 '.__('audit.support_filters.underground'))->assertDontSee(__('audit.support_filters.none'))
            ->assertSee(__('enums.audit_supports.chamber'))->assertSee('0%')->assertSee(__('audit.field.chip_synced'));

        $page->set('supportFilter', 'aerial')->assertSee('PB0005')->assertDontSee('BPE-0001');
        $page->set('supportFilter', 'none')->assertSee('PB0005')->assertSee('BPE-0001');

        $page->call('openWindow', 'point')->assertSee(__('audit.new_point_label'))->set('newPoint', 'Extra point')->call('addPoint')->assertSet('window', '');
        $page->call('openWindow', 'box')->assertSee(__('audit.new_box_label'))->call('closeWindow')->assertDontSee(__('audit.new_box_label'));
        $page->call('openWindow', 'navigate')->assertSet('window', 'navigate')->assertDontSee(__('audit.toolbar.navigate_cabinet'));
        $this->assertTrue($audit->controlPoints()->where('name', 'Extra point')->exists());

        Livewire::actingAs($this->stranger)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertNotFound();
    }

    public function test_a_technician_adds_boxes_and_points_finishes_and_a_manager_validates(): void
    {
        $this->site('PM153');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1']);

        $page = Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])
            ->set('newBox', 'bpe-0009-ft')->call('addBox')->assertHasNoErrors()
            ->set('newBox', 'Odd name')->call('addBox')->assertSee(__('audit.errors.name_format'))
            ->set('newBox', 'Odd name')->set('newBoxKind', 'jd')->call('addBox')->assertHasNoErrors()
            ->set('newPoint', 'Extra point')->call('addPoint')->assertHasNoErrors();
        $this->assertSame(['BPE-0009-FT', 'ODD NAME'], $audit->boxes()->orderBy('id')->pluck('name')->all());
        $this->assertTrue($audit->controlPoints()->where('name', 'Extra point')->exists());

        $page->call('finish', false);
        $this->assertTrue($audit->refresh()->isFinished());
        $page->call('validateAudit')->assertSee(__('audit.errors.forbidden'));
        $this->assertFalse($audit->refresh()->isValidated());

        Livewire::actingAs($this->manager)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertSee(__('audit.finished_by', ['name' => 'Alice']))->call('validateAudit');
        $this->assertTrue($audit->refresh()->isValidated());

        Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertSee(__('audit.validated_by', ['name' => 'Sam']))->assertDontSee(__('audit.add_box'));
    }

    public function test_finishing_with_open_boxes_asks_for_confirmation_and_managers_can_finish_and_validate(): void
    {
        $this->site('PM153');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1']);
        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'BPE-0001']);

        Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])->call('askFinish')->assertDispatched('confirm-action');
        Livewire::actingAs($this->manager)->test(AuditPage::class, ['cabinet' => 'PM153'])->call('finish', true);

        $audit->refresh();
        $this->assertTrue($audit->isFinished());
        $this->assertTrue($audit->isValidated());
    }

    public function test_managers_configure_a_cabinet_that_was_never_opened_and_the_mode_lock_is_reported(): void
    {
        $this->site('PM777', 'full');

        Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM777'])->assertOk()->assertSee(__('audit.not_configured_technician', ['cabinet' => 'PM777']))->assertDontSeeLivewire(AuditConfigure::class);

        Livewire::actingAs($this->manager)->test(AuditPage::class, ['cabinet' => 'PM777'])->assertSee(__('audit.not_configured_manager'))->assertSeeLivewire(AuditConfigure::class);
        $component = Livewire::actingAs($this->manager)->test(AuditConfigure::class, ['cabinet' => 'PM777'])->set('sro', 'SRO-9')->set('mode', 'optical')->call('save')->assertHasNoErrors();
        $audit = Audit::query()->where('cabinet_key', 'PM777')->firstOrFail();
        $this->assertSame('SRO-9', $audit->sro);
        $this->assertSame(AuditMode::Optical, $audit->mode);

        $this->operate($this->manager, $audit, ['op' => 'box_add', 'name' => 'BPE-0001']);
        $stored = StoredFile::query()->create(['sha256' => 'f', 'path' => 'files/f.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);
        AuditPhoto::query()->create(['audit_id' => $audit->id, 'audit_box_id' => $audit->boxes()->first()->id, 'folder' => 'closed', 'stored_file_id' => $stored->id, 'captured_at' => now(), 'received_at' => now()]);
        $component->set('mode', 'full')->call('save')->assertHasErrors('form');

        Livewire::actingAs($this->stranger)->test(AuditPage::class, ['cabinet' => 'PM777'])->assertNotFound();
        $this->site('PM888', 'full', $this->stranger);
        $this->actingAs($this->technician)->get(route('audits.show', ['cabinet' => 'PM888']))->assertNotFound();
        Livewire::actingAs($this->technician)->test(AuditConfigure::class, ['cabinet' => 'PM777'])->assertForbidden();
    }

    public function test_the_optical_mode_hides_the_empty_clean_up_point_and_unrelated_people_cannot_open_the_page(): void
    {
        $this->site('PM153', 'optical');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1', 'mode' => 'optical']);

        Livewire::actingAs($this->manager)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertSee('Satellite chamber')->assertDontSee('Clean-up PM153');
        Livewire::actingAs($this->stranger)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertNotFound();
    }

    public function test_the_design_office_is_told_when_the_audit_moves_on_while_they_look_at_it(): void
    {
        $this->site('PM153');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1']);
        $office = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]);

        $page = Livewire::actingAs($office)->test(AuditPage::class, ['cabinet' => 'PM153'])->call('checkUpdates')->assertNotDispatched('toast');

        $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => 'BPE-0001']);
        $this->operate($this->technician, $audit, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'before', 'text' => 'Clean']);
        $this->operate($this->technician, $audit, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'route', 'text' => 'RAS']);
        $this->operate($this->technician, $audit, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'ok']);
        $this->actingAs($office);

        $page->call('checkUpdates')->assertDispatched('toast', message: __('audit.be_update', ['cabinet' => 'PM153', 'photos' => 0, 'boxes' => 1]));
        $page->call('checkUpdates')->assertNotDispatched('toast');
        $page->assertSee('● '.__('audit.new_mark'));
        $this->assertCount(1, $page->get('newBoxes'));

        $technician = Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153']);
        $this->operate($this->manager, $audit, ['op' => 'box_add', 'name' => 'BPE-0002']);
        $this->actingAs($this->technician);
        $technician->call('checkUpdates')->assertNotDispatched('toast');
    }

    public function test_the_boxes_show_their_distance_and_can_be_sorted_nearest_first(): void
    {
        $this->site('PM153');
        $audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $this->operate($this->manager, $audit, ['op' => 'setup', 'sro' => 'SRO-1']);

        foreach (['BPE-0001-FT', 'BPE-0002', 'PB0005'] as $name) {
            $this->operate($this->technician, $audit, ['op' => 'box_add', 'name' => $name]);
        }

        $audit->boxes()->where('name', 'BPE-0001-FT')->update(['latitude' => 43.9400, 'longitude' => 2.1480]);
        $audit->boxes()->where('name', 'BPE-0002')->update(['latitude' => 43.9300, 'longitude' => 2.1480]);

        $page = Livewire::actingAs($this->technician)->test(AuditPage::class, ['cabinet' => 'PM153'])->assertDontSee('📍 1,1 km');
        $page->call('locate', 43.9300, 2.1480, 15)->assertSee('📍 0 m')->assertSee('📍 1.1 km');
        $page->call('toggleNearest')->assertSeeInOrder(['BPE-0002', 'BPE-0001-FT', 'PB0005']);
        $page->call('locate', 120, 2.1, 15)->assertSet('here', ['latitude' => 43.93, 'longitude' => 2.148]);
        $page->call('locate', 43.0, 2.1, 900)->assertSet('here', ['latitude' => 43.93, 'longitude' => 2.148]);
    }
}
