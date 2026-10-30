<?php

namespace Tests\Feature\Access;

use App\Enums\AbsenceType;
use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Admin\AccessManagement;
use App\Livewire\Planning\TaskEditor;
use App\Livewire\Planning\TechnicianEditor;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\RoleFeatureOverride;
use App\Models\Task;
use App\Models\User;
use App\Navigation\MainMenu;
use App\Navigation\MenuGroup;
use App\Support\EffectiveAgency;
use App\Support\FeatureAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The « Gérer les accès » decisions (D5): a switch exists only where the server respects it, and the server applies
 * the same agency rules as the menu.
 */
class AccessRulesTest extends TestCase
{
    use RefreshDatabase;

    /** Pages of the features whose menu entry depends on the agency or on the audits. */
    private const array PAGES = [
        'absences.index', 'technician-space', 'billing', 'otdr', 'equipment.index', 'audits.full', 'audits.optical',
        'audits.weekly-timesheet', 'expense-reports', 'client-control', 'media-library', 'fibre-code',
    ];

    private Agency $daily;

    private Agency $detailed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->daily = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->detailed = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);
        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    public function test_the_design_office_has_no_equipment_switch_and_a_stored_one_is_ignored(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();
        $office = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->daily->id]);

        Livewire::actingAs($admin)->test(AccessManagement::class)->call('toggle', 'equipment_management', 'design_office')->assertStatus(422);
        Livewire::actingAs($admin)->test(AccessManagement::class)->call('toggle', 'billing', 'design_office')->assertStatus(422);

        $this->switchOn(Feature::EquipmentManagement, Role::DesignOffice, true);

        $this->assertNotContains('equipment', $this->menuKeys($office));
        $this->actingAs($office)->get(route('equipment.index'))->assertForbidden();
    }

    public function test_without_absences_the_task_window_offers_and_accepts_only_tasks(): void
    {
        $supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->daily->id]);
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->daily->id]);
        Absence::factory()->create(['user_id' => $technician->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-10']);
        $this->switchOn(Feature::Absences, Role::Supervisor, false);

        $editor = fn () => Livewire::actingAs($supervisor)->test(TaskEditor::class)->call('openFor', $technician->id, '2026-11-04');

        $editor()->assertDontSee(__('tasks.kinds.sick'))->assertDontSee(__('tasks.kinds.leave'));
        $editor()->set('mode', 'sick')->call('save')->assertForbidden();
        $editor()->set('mode', 'absent')->call('confirmAbsence')->assertForbidden();
        $this->assertDatabaseCount('absences', 1);

        Livewire::actingAs($supervisor)->test(TaskEditor::class)->call('openFor', $technician->id, '2026-11-09')
            ->assertSet('mode', 'leave')->assertDontSee(__('tasks.save'))
            ->call('clear')->assertForbidden();
        $this->assertDatabaseCount('absences', 1);

        Livewire::actingAs($supervisor)->test(TaskEditor::class)->call('openFor', $technician->id, '2026-11-04')
            ->set('label', 'SPLICING')->call('save')->assertHasNoErrors();
        $this->assertSame('SPLICING', Task::query()->sole()->label);
    }

    public function test_without_absences_the_technician_window_lists_them_without_changing_them(): void
    {
        $supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->daily->id]);
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->daily->id]);
        Absence::factory()->create(['user_id' => $technician->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-05']);

        Livewire::actingAs($supervisor)->test(TechnicianEditor::class)->call('openFor', $technician->id, '2026-11-02')
            ->assertSeeHtml('askRemoveAbsence');

        $this->switchOn(Feature::Absences, Role::Supervisor, false);

        $window = fn () => Livewire::actingAs($supervisor)->test(TechnicianEditor::class)->call('openFor', $technician->id, '2026-11-02');

        $window()->assertSee(__('absences.days_short', ['count' => 3]))->assertDontSeeHtml('askRemoveAbsence');
        $window()->call('askRemoveAbsence', '2026-11-03', '2026-11-05')->assertForbidden();
        $window()->call('removeAbsence', '2026-11-03', '2026-11-05')->assertForbidden();
        $window()->call('editAbsence', '2026-11-03')->assertForbidden();
        $this->assertDatabaseCount('absences', 1);
    }

    public function test_supervisor_audits_depend_on_the_agency_even_when_switched_on_and_the_page_says_so(): void
    {
        $onDaily = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->daily->id]);
        $onDetailed = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->detailed->id]);
        $this->switchOn(Feature::Audits, Role::Supervisor, true);

        $this->actingAs($onDaily)->get(route('audits.full'))->assertOk();
        $this->actingAs($onDetailed)->get(route('audits.full'))->assertForbidden();

        $this->switchOn(Feature::Audits, Role::Supervisor, false);
        $this->actingAs($onDaily)->get(route('audits.full'))->assertForbidden();

        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('access.index'))
            ->assertSee(__('access.descriptions.audits'));
        $this->assertStringContainsString('jour par jour', trans('access.descriptions.audits', [], 'fr'));
    }

    public function test_without_audits_the_audit_deliverables_and_client_control_are_closed(): void
    {
        $office = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->daily->id]);

        $this->actingAs($office)->get(route('media-library'))->assertOk()->assertSee(route('media-library.audits'));
        $this->actingAs($office)->get(route('media-library.audits'))->assertOk();
        $this->actingAs($office)->get(route('client-control'))->assertOk();

        $this->switchOn(Feature::Audits, Role::DesignOffice, false);

        $this->actingAs($office)->get(route('media-library'))->assertOk()->assertDontSee(route('media-library.audits'));
        $this->actingAs($office)->get(route('media-library.audits'))->assertForbidden();
        $this->actingAs($office)->get(route('media-library.audits.file', ['path' => 'PM1/a.jpg']))->assertForbidden();
        $this->actingAs($office)->post(route('media-library.audits.zip'), ['items' => ['PM1']])->assertForbidden();
        $this->actingAs($office)->get(route('client-control'))->assertForbidden();
        $this->assertNotContains('client_control', $this->menuKeys($office));
    }

    public function test_the_server_follows_the_menu_for_every_role_agency_mode_and_switch(): void
    {
        foreach ([true, false] as $everythingOn) {
            foreach ([Role::Supervisor, Role::DesignOffice, Role::Technician] as $role) {
                foreach (Feature::cases() as $feature) {
                    if (in_array($role, $feature->configurableRoles(), true)) {
                        $this->switchOn($feature, $role, $everythingOn ?: null);
                    }
                }
            }

            foreach (Role::cases() as $role) {
                foreach ([$this->daily, $this->detailed] as $agency) {
                    $user = User::factory()->withRole($role)->create(['agency_id' => $agency->id]);
                    session([EffectiveAgency::SESSION_KEY => $agency->id]);
                    $menu = $this->menuRoutes($user);

                    foreach (self::PAGES as $page) {
                        $case = "{$role->value} on a {$agency->planning_mode->value} agency, switches ".($everythingOn ? 'all on' : 'default').": {$page}";
                        $this->assertSame(in_array($page, $menu, true), $this->opens($this->actingAs($user)->get(route($page))), $case);
                    }
                }
            }
        }
    }

    public function test_the_planning_weekly_timesheet_with_gps_is_for_managers_only(): void
    {
        $office = User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->daily->id]);
        $supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->daily->id]);

        $this->actingAs($office)->get(route('audits.weekly-timesheet'))->assertOk();
        $this->actingAs($office)->get(route('tasks.timesheet'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('tasks.timesheet'))->assertOk();

        $this->switchOn(Feature::WeeklyTimesheet, Role::Supervisor, false);
        $this->actingAs($supervisor)->get(route('tasks.timesheet'))->assertForbidden();
    }

    public function test_switching_my_space_off_stops_its_inner_screens_already_open(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->daily->id]);
        $html = (string) $this->actingAs($technician)->get(route('technician-space', ['tab' => 'vehicle']))->assertOk()->getContent();

        $this->callComponent($html, 'technician-space.vehicle-inspection', '$refresh')->assertOk();

        $this->switchOn(Feature::TechnicianSpace, Role::Technician, false);
        $this->callComponent($html, 'technician-space.vehicle-inspection', '$refresh')->assertForbidden();
        $this->callComponent($html, 'technician-space.space', 'saveVehicle')->assertForbidden();
    }

    /**
     * Store a switch (null: back to the default).
     */
    private function switchOn(Feature $feature, Role $role, ?bool $enabled): void
    {
        $roleId = RoleModel::findByName($role->value)->id;
        RoleFeatureOverride::query()->where('feature', $feature)->where('role_id', $roleId)->delete();

        if ($enabled !== null) {
            RoleFeatureOverride::query()->create(['feature' => $feature, 'role_id' => $roleId, 'is_enabled' => $enabled]);
        }

        app(FeatureAccess::class)->forgetOverrides();
    }

    /**
     * @return list<string>
     */
    private function menuKeys(User $user): array
    {
        app()->forgetScopedInstances();

        return collect(app(MainMenu::class)->for($user))->flatMap(fn (MenuGroup $group) => collect($group->items)->pluck('key'))->all();
    }

    /**
     * @return list<string>
     */
    private function menuRoutes(User $user): array
    {
        app()->forgetScopedInstances();

        return collect(app(MainMenu::class)->for($user))->flatMap(fn (MenuGroup $group) => collect($group->items)->pluck('route'))->all();
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function opens(TestResponse $response): bool
    {
        $this->assertContains($response->getStatusCode(), [200, 403, 404]);

        return $response->getStatusCode() === 200;
    }

    /**
     * @param  list<mixed>  $params
     * @return TestResponse<Response>
     */
    private function callComponent(string $html, string $component, string $method, array $params = []): TestResponse
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                app(FeatureAccess::class)->forgetOverrides();
                Livewire::flushState();

                return $this->withHeader('X-Livewire', '1')->postJson(route('livewire.update'), [
                    'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]]]],
                ]);
            }
        }

        $this->fail("No « {$component} » component on the page.");
    }
}
