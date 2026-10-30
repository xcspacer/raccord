<?php

namespace Tests\Feature\FibreCode;

use App\Enums\Feature;
use App\Enums\Role;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class FibreCodePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_every_role_opens_it_by_default_including_the_design_office(): void
    {
        foreach ([Role::Administrator, Role::Supervisor, Role::Technician, Role::DesignOffice] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())
                ->get(route('fibre-code'))
                ->assertOk()
                ->assertSee('x-data="fibreCode"', false)
                ->assertSee(__('fibre_code.table'));
        }
    }

    public function test_a_role_switched_off_is_refused_on_the_server_but_never_administrators(): void
    {
        foreach ([Role::Supervisor, Role::Administrator] as $role) {
            RoleFeatureOverride::query()->create(['feature' => Feature::FibreCode, 'role_id' => RoleModel::findByName($role->value)->id, 'is_enabled' => false]);
        }
        app(FeatureAccess::class)->forgetOverrides();

        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('fibre-code'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('fibre-code'))->assertOk();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('fibre-code'))->assertRedirect(route('login'));
    }

    public function test_the_standalone_app_needs_a_sign_in_and_follows_the_role_switch(): void
    {
        $this->get(route('fibre-code.app'))->assertRedirect(route('login'));

        RoleFeatureOverride::query()->create(['feature' => Feature::FibreCode, 'role_id' => RoleModel::findByName(Role::Technician->value)->id, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();

        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('fibre-code.app'))->assertForbidden();
    }

    public function test_the_standalone_app_and_its_public_manifest(): void
    {
        $company = app(CompanySettings::class);
        $company->name = 'Example Networks';
        $company->save();

        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('fibre-code.app'))
            ->assertOk()
            ->assertSee('fibre-code--standalone', false)
            ->assertSee(route('fibre-code.app.manifest'), false)
            ->assertSee('fibre-code-service-worker.js', false);

        $this->get(route('fibre-code.app.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => __('fibre_code.app.name', ['platform' => 'Example Networks']),
                'short_name' => __('fibre_code.app.short_name'),
                'start_url' => '/fibre-code-app/',
                'scope' => '/fibre-code-app/',
                'display' => 'standalone',
            ]);
    }

    public function test_everything_disappears_with_the_module(): void
    {
        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['fibre_code']));
        $modules->save();

        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('fibre-code'))->assertNotFound();

        $this->get(route('fibre-code.app'))->assertNotFound();
        $this->get(route('fibre-code.app.manifest'))->assertNotFound();
    }

    public function test_the_standalone_app_follows_the_platform_switch(): void
    {
        config(['platform.enabled' => false]);

        $this->get(route('fibre-code.app'))->assertStatus(503);
    }
}
