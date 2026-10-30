<?php

namespace Tests\Feature\Access;

use App\Enums\Feature;
use App\Enums\Module;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * TST-03: the « Gérer les accès » truth table (D5). Every feature × role × switch (catalogue default, stored ON,
 * stored OFF) × module on/off gives the expected answer, both from FeatureAccess and from a real page of the feature,
 * and a page already open stops answering as soon as its feature or module is switched off.
 */
class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const string SUPERVISOR = 'supervisor';

    private const string TECHNICIAN = 'technician';

    private const string DESIGN_OFFICE = 'design_office';

    /**
     * The approved table: module, roles that have the feature by default, roles the administrator may switch, the page
     * that opens it and, where the page alone does not decide, the Livewire action of that page the feature allows.
     *
     * @var array<string, array{module: string, default: list<string>, configurable: list<string>, route: string, action?: array{string, string, list<mixed>}}>
     */
    private const array TABLE = [
        'absences' => ['module' => 'planning', 'default' => [self::SUPERVISOR], 'configurable' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'route' => 'absences.index'],
        'create_work_site' => ['module' => 'planning', 'default' => [self::SUPERVISOR], 'configurable' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'route' => 'work-sites.create', 'action' => ['work-sites.work-site-editor', 'openFor', [null]]],
        'technician_space' => ['module' => 'technician_space', 'default' => [self::TECHNICIAN], 'configurable' => [self::TECHNICIAN], 'route' => 'technician-space'],
        'billing' => ['module' => 'billing', 'default' => [self::SUPERVISOR], 'configurable' => [self::SUPERVISOR, self::TECHNICIAN], 'route' => 'billing'],
        'otdr_acceptance' => ['module' => 'otdr_acceptance', 'default' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'route' => 'otdr'],
        'equipment_management' => ['module' => 'equipment', 'default' => [self::SUPERVISOR], 'configurable' => [self::SUPERVISOR], 'route' => 'equipment.index'],
        'audits' => ['module' => 'audits', 'default' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'route' => 'audits.full'],
        'weekly_timesheet' => ['module' => 'daily_clock_in', 'default' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'route' => 'audits.weekly-timesheet'],
        'expense_reports' => ['module' => 'expense_reports', 'default' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'route' => 'expense-reports'],
        'client_control' => ['module' => 'client_portal', 'default' => [self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'route' => 'client-control'],
        'media_library' => ['module' => 'media_library', 'default' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::DESIGN_OFFICE], 'route' => 'media-library'],
        'fibre_code' => ['module' => 'fibre_code', 'default' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'route' => 'fibre-code'],
        'design_office_workflow' => ['module' => 'design_office_workflow', 'default' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'configurable' => [self::SUPERVISOR, self::TECHNICIAN, self::DESIGN_OFFICE], 'route' => 'design-office.queue'],
    ];

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);

        foreach (Role::cases() as $role) {
            $this->users[$role->value] = User::factory()->withRole($role)->create(['agency_id' => $agency->id]);
        }
    }

    /**
     * @return array<string, array{Feature}>
     */
    public static function features(): array
    {
        return collect(Feature::cases())->mapWithKeys(fn (Feature $feature) => [$feature->value => [$feature]])->all();
    }

    public function test_the_table_covers_the_whole_catalogue(): void
    {
        $this->assertSame(array_keys(self::TABLE), array_map(fn (Feature $feature) => $feature->value, Feature::cases()));
    }

    #[DataProvider('features')]
    public function test_every_role_switch_and_module_state_gives_the_expected_access(Feature $feature): void
    {
        $row = self::TABLE[$feature->value];

        $this->assertSame($row['module'], $feature->module()->value);
        $this->assertSame($row['default'], array_map(fn (Role $role) => $role->value, $feature->defaultRoles()));
        $this->assertSame($row['configurable'], array_map(fn (Role $role) => $role->value, $feature->configurableRoles()));

        foreach ([true, false] as $moduleOn) {
            $this->switchModule(Module::from($row['module']), $moduleOn);

            foreach (Role::cases() as $role) {
                foreach ($moduleOn ? ['default', 'on', 'off'] : ['default'] as $switch) {
                    $this->store($feature, $role, $switch);
                    $expected = $moduleOn && $this->expected($feature, $role, $switch);
                    $case = "{$feature->value} / {$role->value} / switch {$switch} / module ".($moduleOn ? 'on' : 'off');

                    $this->assertSame($expected, $this->access()->opens($this->users[$role->value], $feature), $case.' (FeatureAccess)');
                    $this->assertServerAnswer($row, $this->users[$role->value], $expected, $case);
                }

                $this->store($feature, $role, 'default');
            }
        }
    }

    /**
     * @return array<string, array{Feature, Role, string, string}>
     */
    public static function openPages(): array
    {
        return [
            'absences' => [Feature::Absences, Role::Supervisor, 'absences.index', 'absences.absence-index'],
            'technician space' => [Feature::TechnicianSpace, Role::Technician, 'technician-space', 'technician-space.space'],
            'billing' => [Feature::Billing, Role::Supervisor, 'billing', 'billing.billing-page'],
            'otdr' => [Feature::OtdrAcceptance, Role::Supervisor, 'otdr', 'otdr.otdr-page'],
            'equipment' => [Feature::EquipmentManagement, Role::Supervisor, 'equipment.index', 'inventory.page'],
            'weekly timesheet' => [Feature::WeeklyTimesheet, Role::Supervisor, 'audits.weekly-timesheet', 'audit.audit-timesheet-page'],
            'expense reports' => [Feature::ExpenseReports, Role::Technician, 'expense-reports', 'expense-reports.editor'],
            'client control' => [Feature::ClientControl, Role::DesignOffice, 'client-control', 'client-portal.client-control-page'],
            'media library' => [Feature::MediaLibrary, Role::DesignOffice, 'media-library', 'media-library.media-library-page'],
            'fibre code' => [Feature::FibreCode, Role::Supervisor, 'fibre-code', 'fibre-code.fibre-code-page'],
            'design office queue' => [Feature::DesignOfficeWorkflow, Role::DesignOffice, 'design-office.queue', 'design-office-workflow.queue-page'],
        ];
    }

    #[DataProvider('openPages')]
    public function test_a_page_already_open_stops_working_once_its_feature_or_module_is_switched_off(Feature $feature, Role $role, string $route, string $component): void
    {
        $user = $this->users[$role->value];
        $this->switchModule($feature->module(), true);
        $html = (string) $this->actingAs($user)->get(route($route))->assertOk()->getContent();

        $this->callComponent($html, $component)->assertOk();

        $this->store($feature, $role, 'off');
        $this->callComponent($html, $component)->assertForbidden();

        $this->store($feature, $role, 'default');
        $this->callComponent($html, $component)->assertOk();

        $this->switchModule($feature->module(), false);
        $this->callComponent($html, $component)->assertStatus($feature === Feature::WeeklyTimesheet ? 403 : 404);
    }

    /**
     * Expected answer on a daily-mode agency with the module on (the D5 table).
     */
    private function expected(Feature $feature, Role $role, string $switch): bool
    {
        if ($role === Role::Administrator) {
            return $feature !== Feature::TechnicianSpace;
        }

        $row = self::TABLE[$feature->value];
        $switchedOn = $switch === 'default' || ! in_array($role->value, $row['configurable'], true)
            ? in_array($role->value, $row['default'], true)
            : $switch === 'on';

        return match ($feature) {
            Feature::Audits => $role === Role::Technician || $switchedOn,
            Feature::ClientControl, Feature::WeeklyTimesheet => $switchedOn && $this->expected(Feature::Audits, $role, 'default'),
            Feature::MediaLibrary => $switchedOn && $role !== Role::Technician,
            Feature::DesignOfficeWorkflow => $switchedOn && $role !== Role::Technician,
            default => $switchedOn,
        };
    }

    /**
     * @param  array{route: string, action?: array{string, string, list<mixed>}}  $row
     */
    private function assertServerAnswer(array $row, User $user, bool $allowed, string $case): void
    {
        $response = $this->actingAs($user)->get(route($row['route']));
        $status = $response->getStatusCode();

        if ($status === 200 && isset($row['action'])) {
            [$component, $method, $params] = $row['action'];
            $status = $this->callComponent((string) $response->getContent(), $component, $method, $params)->getStatusCode();
        }

        if ($allowed) {
            $this->assertSame(200, $status, $case.' (server should allow)');
        } else {
            $this->assertContains($status, [403, 404], $case.' (server should refuse)');
        }
    }

    /**
     * Store the switch of a role, also for roles the feature cannot be switched for (it must then be ignored).
     */
    private function store(Feature $feature, Role $role, string $switch): void
    {
        $roleId = RoleModel::findByName($role->value)->id;
        RoleFeatureOverride::query()->where('feature', $feature)->where('role_id', $roleId)->delete();

        if ($switch !== 'default') {
            RoleFeatureOverride::query()->create(['feature' => $feature, 'role_id' => $roleId, 'is_enabled' => $switch === 'on']);
        }

        $this->access()->forgetOverrides();
    }

    private function switchModule(Module $module, bool $enabled): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_unique($enabled ? [...$settings->enabled, $module->value] : array_diff($settings->enabled, [$module->value])));
        $settings->save();
    }

    private function access(): FeatureAccess
    {
        return app(FeatureAccess::class);
    }

    /**
     * Sends an action (a plain screen update by default) of a component of the page through the real Livewire end
     * point, as the browser does for a page left open.
     *
     * @param  list<mixed>  $params
     * @return TestResponse<Response>
     */
    private function callComponent(string $html, string $component, string $method = '$refresh', array $params = []): TestResponse
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                $this->access()->forgetOverrides();
                Livewire::flushState();

                return $this->withHeader('X-Livewire', '1')->postJson(route('livewire.update'), [
                    'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]]]],
                ]);
            }
        }

        $this->fail("No « {$component} » component on the page.");
    }
}
