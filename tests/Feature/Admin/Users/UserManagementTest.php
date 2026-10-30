<?php

namespace Tests\Feature\Admin\Users;

use App\Actions\Users\CreateUser;
use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Absences\AbsenceIndex;
use App\Livewire\Admin\Users\UserCard;
use App\Livewire\Admin\Users\UserIndex;
use App\Models\Agency;
use App\Models\Task;
use App\Models\User;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create(['username' => 'boss']);
        $this->agency = Agency::factory()->create();
    }

    public function test_only_administrators_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('users.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('users.index'))->assertForbidden();
    }

    public function test_user_is_created_with_a_temporary_password(): void
    {
        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->set('form.name', 'Lucas Moreau')
            ->set('form.username', ' LMoreau ')
            ->set('form.role', Role::Technician->value)
            ->set('form.agencyId', (string) $this->agency->id)
            ->set('form.vehicle', 'Clio - AB-123-CD')
            ->set('form.password', 'temporary-pass')
            ->call('create')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $user = User::query()->where('username', 'lmoreau')->firstOrFail();
        $this->assertTrue($user->hasRole(Role::Technician->value));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('temporary-pass', (string) $user->password));
        $this->assertSame('Clio', $user->vehicle?->model);
        $this->assertSame('AB-123-CD', $user->vehicle?->plate);
    }

    public function test_creation_rules(): void
    {
        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->set('form.name', 'X')
            ->set('form.username', 'BOSS')
            ->set('form.role', Role::Technician->value)
            ->set('form.password', 'short')
            ->call('create')
            ->assertHasErrors(['form.username' => 'unique', 'form.agencyId' => 'required', 'form.password']);

        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->set('form.name', 'Second admin')
            ->set('form.username', 'admin2')
            ->set('form.role', Role::Administrator->value)
            ->set('form.password', 'eleven-char')
            ->call('create')
            ->assertHasErrors(['form.password'])
            ->assertHasNoErrors(['form.agencyId']);
    }

    public function test_vehicle_text_is_split_on_the_last_separator(): void
    {
        $this->assertSame(['Peugeot 208 - GT', 'AB-123-CD'], CreateUser::splitVehicle('Peugeot 208 - GT - AB-123-CD'));
        $this->assertSame(['Clio', null], CreateUser::splitVehicle('Clio'));
    }

    public function test_fields_are_saved_on_change(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $user])
            ->set('agency', 'all')
            ->set('phone', ' 0611223344 ')
            ->set('vehiclePlate', 'XY-987-ZT');

        $user->refresh();
        $this->assertTrue($user->all_agencies);
        $this->assertNull($user->agency_id);
        $this->assertSame('0611223344', $user->phone);
        $this->assertSame('XY987ZT', $user->vehicle?->plate_normalized);
    }

    public function test_the_last_administrator_cannot_be_demoted_or_deleted(): void
    {
        $other = User::factory()->withRole(Role::Administrator)->create();
        $other->delete();

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $this->admin])
            ->set('role', Role::Supervisor->value)
            ->assertHasErrors('role');

        $this->assertTrue($this->admin->fresh()->hasRole(Role::Administrator->value));
    }

    public function test_nobody_deletes_their_own_account(): void
    {
        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $this->admin])
            ->call('delete')
            ->assertHasErrors('user');

        $this->assertNotSoftDeleted($this->admin);
    }

    public function test_deleting_keeps_the_tasks(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        Task::factory()->create(['user_id' => $technician->id]);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])->call('delete')->assertHasNoErrors();

        $this->assertSoftDeleted($technician);
        $this->assertSame(1, Task::query()->where('user_id', $technician->id)->count());
    }

    public function test_departure_asks_for_confirmation_then_is_recorded(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        Task::factory()->create(['user_id' => $technician->id, 'date' => '2030-01-10']);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->set('departureDate', '2030-01-01')
            ->assertDispatched('confirm-action', method: 'confirmDeparture', params: ['2030-01-01'])
            ->assertSet('departureDate', '')
            ->call('confirmDeparture', '2030-01-01');

        $this->assertSame('2030-01-01', $technician->fresh()->departure_date?->toDateString());
    }

    public function test_cancelling_a_departure_brings_the_person_back(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->departed()->create(['name' => 'Back Again', 'username' => 'back']);
        $this->agency->update(['planning_mode' => PlanningMode::Daily]);

        Livewire::actingAs($this->admin)->test(AbsenceIndex::class)->assertDontSee('Back Again');

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->set('departureDate', '')
            ->assertDispatched('confirm-action', method: 'confirmDeparture', params: [''])
            ->call('confirmDeparture', '')
            ->assertDispatched('toast', message: __('users.departure_cancelled'))
            ->assertSet('departureDate', '');

        $technician->refresh();
        $this->assertNull($technician->departure_date);
        $this->assertFalse($technician->hasDeparted());

        Livewire::actingAs($this->admin)->test(AbsenceIndex::class)->assertSee('Back Again');

        auth()->logout();
        $this->post('/login', ['username' => 'back', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($technician);
    }

    public function test_own_departure_is_refused(): void
    {
        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $this->admin])
            ->call('confirmDeparture', '2030-01-01')
            ->assertHasErrors('departureDate');

        $this->assertNull($this->admin->fresh()->departure_date);
    }

    public function test_password_reset_forces_a_change_and_signs_out_other_sessions(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $technician->id, 'payload' => '', 'last_activity' => time()]);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->set('newPassword', 'a-temporary-pass')
            ->call('changePassword')
            ->assertHasNoErrors();

        $technician->refresh();
        $this->assertTrue($technician->must_change_password);
        $this->assertTrue(Hash::check('a-temporary-pass', (string) $technician->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $technician->id]);
    }

    public function test_the_card_links_to_the_equipment_of_the_person_except_for_the_design_office(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $technician])
            ->assertSee(__('users.equipment'))
            ->assertSee(route('equipment.users.show', $technician), false);

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => User::factory()->withRole(Role::DesignOffice)->create()])
            ->assertDontSee(__('users.equipment'));

        $this->actingAs($this->admin)->get(route('equipment.users.show', $technician))->assertOk();
    }

    public function test_the_equipment_link_disappears_with_its_module(): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_diff($settings->enabled, [Feature::EquipmentManagement->module()->value]));
        $settings->save();

        Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => User::factory()->withRole(Role::Technician)->create()])
            ->assertDontSee(__('users.equipment'));
    }

    public function test_non_administrator_cannot_use_the_card(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create();

        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())
            ->test(UserCard::class, ['user' => $technician])
            ->assertForbidden();
    }
}
