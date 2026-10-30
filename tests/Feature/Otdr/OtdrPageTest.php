<?php

namespace Tests\Feature\Otdr;

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

class OtdrPageTest extends TestCase
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
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('otdr'))->assertOk();
        }
    }

    public function test_the_screen_receives_its_texts_locale_and_the_platform_name(): void
    {
        $company = app(CompanySettings::class);
        $company->name = 'Fibre Works';
        $company->save();

        $response = $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('otdr'))->assertOk();

        $response->assertSee('x-data="otdrLoader"', false);
        $response->assertSee('data-product="Fibre Works"', false);
        $response->assertSee('data-locale="'.app()->getLocale().'"', false);
        $response->assertSee(e(json_encode(__('otdr'), JSON_UNESCAPED_UNICODE)), false);
        $response->assertSee(__('otdr.subtitle'));
    }

    public function test_the_page_is_kept_by_the_offline_service_worker(): void
    {
        $this->assertStringContainsString("url.pathname === '/otdr'", (string) file_get_contents(public_path('audit-service-worker.js')));
    }

    public function test_a_role_switched_off_is_refused_on_the_server_but_never_administrators(): void
    {
        foreach ([Role::Technician, Role::Administrator] as $role) {
            RoleFeatureOverride::query()->create(['feature' => Feature::OtdrAcceptance, 'role_id' => RoleModel::findByName($role->value)->id, 'is_enabled' => false]);
        }
        app(FeatureAccess::class)->forgetOverrides();

        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('otdr'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('otdr'))->assertOk();
    }

    public function test_the_page_disappears_with_its_module(): void
    {
        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['otdr_acceptance']));
        $modules->save();

        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('otdr'))->assertNotFound();
    }
}
