<?php

namespace Tests\Feature\Auth;

use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Admin\AccessManagement;
use App\Livewire\Admin\Users\UserCard;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Models\Agency;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
    }

    public function test_role_change_waits_for_the_administrator_password(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        $token = $technician->getRememberToken();

        $card = Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->set('role', Role::Supervisor->value)
            ->assertSet('role', Role::Technician->value)
            ->assertSet('pendingConfirmation', ['method' => 'changeRole', 'params' => [Role::Supervisor->value]])
            ->assertSee('confirm-password-'.$technician->id.'-password');

        $this->assertTrue($technician->fresh()->hasRole(Role::Technician->value));

        $card->set('confirmablePassword', 'wrong-password')
            ->call('confirmPassword')
            ->assertHasErrors('confirmablePassword');
        $this->assertTrue($technician->fresh()->hasRole(Role::Technician->value));

        $card->set('confirmablePassword', 'password')
            ->call('confirmPassword')
            ->assertHasNoErrors()
            ->assertSet('pendingConfirmation', null)
            ->assertSet('role', Role::Supervisor->value);

        $this->assertTrue($technician->fresh()->hasRole(Role::Supervisor->value));
        $this->assertNotSame($token, $technician->fresh()->getRememberToken());
    }

    public function test_recent_confirmation_is_not_asked_again(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->set('role', Role::Supervisor->value)
            ->assertSet('pendingConfirmation', null);

        $this->assertTrue($technician->fresh()->hasRole(Role::Supervisor->value));
    }

    public function test_confirmation_expires(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->withSession(['auth.password_confirmed_at' => now()->subSeconds((int) config('auth.password_timeout') + 1)->getTimestamp()]);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->set('role', Role::Supervisor->value)
            ->assertNotSet('pendingConfirmation', null);

        $this->assertTrue($technician->fresh()->hasRole(Role::Technician->value));
    }

    public function test_pending_action_cannot_be_forged_by_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => User::factory()->withRole(Role::Technician)->create()])
            ->set('pendingConfirmation', ['method' => 'delete', 'params' => []]);
    }

    public function test_access_switches_wait_for_the_password(): void
    {
        $component = Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::Billing->value, Role::Technician->value)
            ->assertNotSet('pendingConfirmation', null);

        $this->assertDatabaseCount('role_feature_overrides', 0);

        $component->set('confirmablePassword', 'password')->call('confirmPassword')->assertHasNoErrors();

        $this->assertDatabaseHas('role_feature_overrides', ['feature' => Feature::Billing->value, 'is_enabled' => true]);
    }

    public function test_cancelling_leaves_everything_unchanged(): void
    {
        Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::Billing->value, Role::Technician->value)
            ->call('cancelPasswordConfirmation')
            ->assertSet('pendingConfirmation', null)
            ->set('confirmablePassword', 'password')
            ->call('confirmPassword');

        $this->assertDatabaseCount('role_feature_overrides', 0);
    }

    public function test_password_attempts_are_limited(): void
    {
        $component = Livewire::actingAs($this->admin)->test(AccessManagement::class)
            ->call('toggle', Feature::Billing->value, Role::Technician->value);

        foreach (range(1, 5) as $attempt) {
            $component->set('confirmablePassword', 'wrong-password')->call('confirmPassword');
        }

        $component->set('confirmablePassword', 'password')->call('confirmPassword')->assertHasErrors('confirmablePassword');
        $this->assertDatabaseCount('role_feature_overrides', 0);
    }

    public function test_creating_a_client_account_waits_for_the_password(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ClientControlPage::class)
            ->set('name', 'Jane Doe')->set('company', 'Acme')->set('username', 'jane')->set('allAudits', true)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('pendingConfirmation', ['method' => 'create', 'params' => []])
            ->assertSee('confirm-password-client-control-password');

        $this->assertDatabaseCount('client_accounts', 0);

        $component->set('confirmablePassword', 'wrong-password')->call('confirmPassword')->assertHasErrors('confirmablePassword');
        $this->assertDatabaseCount('client_accounts', 0);

        $component->set('confirmablePassword', 'password')->call('confirmPassword')
            ->assertHasNoErrors()
            ->assertSet('pendingConfirmation', null)
            ->assertSee(__('client_portal.control.password_title'));

        $this->assertSame('jane', ClientAccount::query()->sole()->username);
    }

    public function test_an_invalid_client_account_is_refused_before_asking_for_the_password(): void
    {
        Livewire::actingAs($this->admin)->test(ClientControlPage::class)
            ->set('name', 'Jane Doe')->set('company', 'Acme')->set('username', 'bad name!')->set('allAudits', true)
            ->call('create')
            ->assertHasErrors('username')
            ->assertSet('pendingConfirmation', null);
    }

    public function test_a_new_client_password_waits_for_the_password(): void
    {
        $account = ClientAccount::factory()->create();

        $component = Livewire::actingAs($this->admin)->test(ClientControlPage::class)
            ->call('startPassword', $account->id)->set('newPassword', 'a-new-portal-password')
            ->call('savePassword')
            ->assertSet('pendingConfirmation', ['method' => 'savePassword', 'params' => []]);

        $this->assertTrue(Hash::check('portal-password', $account->refresh()->password));

        $component->call('cancelPasswordConfirmation');
        $this->assertTrue(Hash::check('portal-password', $account->refresh()->password));

        $component->call('savePassword')->set('confirmablePassword', 'password')->call('confirmPassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check('a-new-portal-password', $account->refresh()->password));
        $this->assertTrue($account->must_change_password);
    }
}
