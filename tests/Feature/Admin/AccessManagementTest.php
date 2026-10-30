<?php

namespace Tests\Feature\Admin;

use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Admin\AccessManagement;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create(['locale' => 'fr']);

        // The password confirmation itself is covered by Tests\Feature\Auth\PasswordConfirmationTest.
        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    public function test_only_administrators_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('access.index'))->assertOk()->assertSeeText('Gérer les accès');

        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('access.index'))->assertForbidden();
    }

    public function test_switching_a_feature_is_enforced_on_the_server(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->assertFalse(Gate::forUser($technician)->allows('use-feature', Feature::Billing));

        Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::Billing->value, Role::Technician->value)
            ->assertDispatched('toast');

        $this->assertDatabaseHas('role_feature_overrides', ['feature' => Feature::Billing->value, 'is_enabled' => true]);
        $this->assertTrue(Gate::forUser($technician->fresh())->allows('use-feature', Feature::Billing));
    }

    public function test_switching_back_to_the_default_removes_the_override(): void
    {
        Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::Billing->value, Role::Technician->value)
            ->call('toggle', Feature::Billing->value, Role::Technician->value);

        $this->assertSame(0, RoleFeatureOverride::query()->count());
    }

    public function test_role_outside_the_catalogue_cannot_be_switched(): void
    {
        Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::TechnicianSpace->value, Role::Supervisor->value)
            ->assertStatus(422);
    }

    public function test_non_administrator_cannot_call_the_actions(): void
    {
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())
            ->test(AccessManagement::class)
            ->assertForbidden();
    }

    public function test_reset_restores_every_default(): void
    {
        Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::Billing->value, Role::Technician->value)
            ->call('toggle', Feature::Audits->value, Role::Supervisor->value)
            ->call('resetToDefaults');

        $this->assertSame(0, RoleFeatureOverride::query()->count());
    }
}
