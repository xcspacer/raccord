<?php

namespace Tests\Feature\Navigation;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Navigation\MainMenu;
use App\Navigation\MenuGroup;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MainMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Agency::factory()->create();

        foreach (['planning', 'work-sites.index', 'technician-space', 'billing', 'otdr', 'fibre-code', 'users.index', 'logs.index'] as $name) {
            Route::get('/_menu/'.$name, fn () => '')->name($name);
        }

        Route::getRoutes()->refreshNameLookups();
    }

    public function test_items_only_appear_when_their_page_exists(): void
    {
        $keys = $this->keysFor(User::factory()->withRole(Role::Administrator)->create());

        $this->assertContains('otdr_acceptance', $keys);
        $this->assertNotContains('media_library', $keys);
    }

    public function test_menu_follows_the_role(): void
    {
        $admin = $this->keysFor(User::factory()->withRole(Role::Administrator)->create());
        $technician = $this->keysFor(User::factory()->withRole(Role::Technician)->create());
        $designOffice = $this->keysFor(User::factory()->withRole(Role::DesignOffice)->create());

        $this->assertContains('users', $admin);
        $this->assertNotContains('technician_space', $admin);

        $this->assertContains('technician_space', $technician);
        $this->assertNotContains('work_sites', $technician);
        $this->assertNotContains('users', $technician);

        $this->assertNotContains('planning', $designOffice);
        $this->assertContains('fibre_code', $designOffice);
    }

    public function test_the_report_articles_are_reached_from_the_audits_group_by_whoever_manages_audits(): void
    {
        Agency::query()->update(['planning_mode' => PlanningMode::Daily]);
        $daily = Agency::query()->firstOrFail();
        $admin = $this->keysFor(User::factory()->withRole(Role::Administrator)->create(['agency_id' => $daily->id]));
        $technician = $this->keysFor(User::factory()->withRole(Role::Technician)->create(['agency_id' => $daily->id]));

        $this->assertContains('audits_optical', $technician);
        $this->assertContains('audit_catalog', $admin);
        $this->assertNotContains('audit_catalog', $technician);
    }

    public function test_the_whole_administration_lives_in_the_side_menu_for_administrators_only(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();
        $administration = collect(app(MainMenu::class)->for($admin))->firstWhere('key', 'administration');

        $this->assertSame(['users', 'access', 'equipment', 'agencies', 'companies', 'holidays', 'platform_settings', 'logs'], array_map(fn ($item) => $item->key, $administration->items));

        $supervisor = $this->keysFor(User::factory()->withRole(Role::Supervisor)->create());
        foreach (['users', 'access', 'agencies', 'companies', 'holidays', 'platform_settings', 'logs'] as $key) {
            $this->assertNotContains($key, $supervisor);
        }

        $this->actingAs($admin)->get('/planning/tasks')->assertDontSee('dropdown__menu--settings', false);
    }

    /**
     * @return list<string>
     */
    private function keysFor(User $user): array
    {
        return collect(app(MainMenu::class)->for($user))
            ->flatMap(fn (MenuGroup $group) => array_map(fn ($item) => $item->key, $group->items))
            ->values()
            ->all();
    }
}
