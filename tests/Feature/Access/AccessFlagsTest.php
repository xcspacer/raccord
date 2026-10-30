<?php

namespace Tests\Feature\Access;

use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Admin\AccessManagement;
use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSite;
use App\Navigation\MainMenu;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The switches of « Gérer les accès » really change what each role can open (menu and server).
 */
class AccessFlagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Agency::factory()->create();
        $this->admin = User::factory()->withRole(Role::Administrator)->create();

        // Password confirmation of the access switches: Tests\Feature\Auth\PasswordConfirmationTest.
        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    /**
     * @return list<string>
     */
    private function menuKeys(User $user): array
    {
        app()->forgetInstance(FeatureAccess::class);

        return collect(app(MainMenu::class)->for($user))->flatMap(fn ($group) => collect($group->items)->pluck('key'))->all();
    }

    public function test_absences_switch_controls_the_page_and_the_menu(): void
    {
        $agency = Agency::factory()->create(['planning_mode' => 'daily']);
        $supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);

        $this->actingAs($supervisor)->get(route('absences.index'))->assertOk();
        $this->assertContains('absences', $this->menuKeys($supervisor));

        Livewire::actingAs($this->admin)->test(AccessManagement::class)->call('toggle', 'absences', 'supervisor');

        $this->actingAs($supervisor)->get(route('absences.index'))->assertForbidden();
        $this->assertNotContains('absences', $this->menuKeys($supervisor));

        Livewire::actingAs($this->admin)->test(AccessManagement::class)->call('toggle', 'absences', 'supervisor');
        $this->actingAs($supervisor)->get(route('absences.index'))->assertOk();
    }

    public function test_new_work_site_switch_controls_creation(): void
    {
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();

        $this->assertTrue(Gate::forUser($supervisor)->allows('create', WorkSite::class));

        Livewire::actingAs($this->admin)->test(AccessManagement::class)->call('toggle', 'create_work_site', 'supervisor');
        app()->forgetInstance(FeatureAccess::class);

        $this->assertFalse(Gate::forUser($supervisor)->allows('create', WorkSite::class));
        $this->assertNotContains('create_work_site', $this->menuKeys($supervisor));
    }

    public function test_my_space_switch_controls_the_technician_page(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();

        $this->actingAs($technician)->get(route('technician-space'))->assertOk();

        Livewire::actingAs($this->admin)->test(AccessManagement::class)->call('toggle', 'technician_space', 'technician');

        $this->actingAs($technician)->get(route('technician-space'))->assertForbidden();
        $this->assertNotContains('technician_space', $this->menuKeys($technician));
    }

    public function test_switching_a_module_off_overrides_every_role_switch(): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_diff($settings->enabled, ['technician_space']));
        $settings->save();

        $technician = User::factory()->withRole(Role::Technician)->create();

        $this->actingAs($technician)->get(route('technician-space'))->assertNotFound();
        $this->assertFalse(app(FeatureAccess::class)->allows($technician, Feature::TechnicianSpace));
    }

    public function test_only_configurable_roles_can_be_switched_and_only_by_administrators(): void
    {
        Livewire::actingAs($this->admin)->test(AccessManagement::class)->call('toggle', 'technician_space', 'supervisor')->assertStatus(422);
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(AccessManagement::class)->assertForbidden();
    }

    public function test_the_page_explains_every_feature_in_every_language(): void
    {
        foreach (Feature::cases() as $feature) {
            foreach (config('app.supported_locales') as $locale) {
                $this->assertTrue(Lang::has('access.descriptions.'.$feature->value, $locale, false), $feature->value.' '.$locale);
            }
        }
    }
}
