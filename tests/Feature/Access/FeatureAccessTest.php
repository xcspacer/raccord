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
use App\Support\EffectiveAgency;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class FeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_administrators_have_every_feature_but_the_technician_space(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();

        $this->assertTrue(Gate::forUser($admin)->allows('use-feature', Feature::OtdrAcceptance));
        $this->assertTrue(Gate::forUser($admin)->allows('use-feature', Feature::Billing));
        $this->assertFalse(Gate::forUser($admin)->allows('use-feature', Feature::TechnicianSpace));
    }

    public function test_catalogue_defaults_apply_without_override(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();

        $this->assertTrue(Gate::forUser($technician)->allows('use-feature', Feature::TechnicianSpace));
        $this->assertFalse(Gate::forUser($technician)->allows('use-feature', Feature::Billing));
        $this->assertTrue(Gate::forUser($supervisor)->allows('use-feature', Feature::Billing));
    }

    public function test_stored_override_wins_over_the_default(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        RoleFeatureOverride::query()->create([
            'feature' => Feature::Billing,
            'role_id' => RoleModel::findByName(Role::Technician->value)->id,
            'is_enabled' => true,
        ]);

        $this->assertTrue(Gate::forUser($technician)->allows('use-feature', Feature::Billing));
    }

    public function test_disabled_module_removes_the_feature_for_everyone(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_diff($settings->enabled, [Module::OtdrAcceptance->value]));
        $settings->save();

        $this->assertFalse(Gate::forUser($admin)->allows('use-feature', Feature::OtdrAcceptance));
    }

    public function test_audits_follow_the_agency_mode_without_override(): void
    {
        $daily = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $detailed = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);
        $access = app(FeatureAccess::class);
        $agency = app(EffectiveAgency::class);

        $this->assertTrue($access->allowsAudits(User::factory()->withRole(Role::DesignOffice)->create(), $agency));
        $this->assertTrue($access->allowsAudits(User::factory()->withRole(Role::Technician)->create(['agency_id' => $daily->id]), $agency));
        $this->assertFalse($access->allowsAudits(User::factory()->withRole(Role::Technician)->create(['agency_id' => $detailed->id]), $agency));
        $this->assertFalse($access->allowsAudits(User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $detailed->id]), $agency));
    }
}
