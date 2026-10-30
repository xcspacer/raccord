<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Livewire\Profile\ActiveSessions;
use App\Models\User;
use App\Support\UserSessions;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const string PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_departed_person_is_signed_out_at_the_next_request(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create(['username' => 'leaving']);
        $this->post('/login', ['username' => 'leaving', 'password' => 'password'])->assertRedirect('/');
        $token = $user->fresh()?->getRememberToken();

        $user->update(['departure_date' => now()->toDateString()]);
        Auth::forgetGuards();

        $this->getJson(route('geo.reverse', ['lat' => 48.85, 'lng' => 2.35]))
            ->assertUnauthorized()
            ->assertJson(['reason' => 'user_disabled']);
        $this->assertGuest();
        $this->assertNotSame($token, $user->fresh()?->getRememberToken());

        Auth::forgetGuards();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_deleted_person_is_signed_out_at_the_next_request(): void
    {
        $user = User::factory()->withRole(Role::Supervisor)->create(['username' => 'removed']);
        $this->post('/login', ['username' => 'removed', 'password' => 'password'])->assertRedirect('/');

        $user->delete();
        Auth::forgetGuards();

        $this->getJson(route('geo.reverse', ['lat' => 48.85, 'lng' => 2.35]))
            ->assertUnauthorized()
            ->assertJson(['reason' => 'user_disabled']);
    }

    public function test_plain_expiry_has_no_reason(): void
    {
        $this->getJson(route('geo.reverse', ['lat' => 48.85, 'lng' => 2.35]))
            ->assertUnauthorized()
            ->assertExactJson(['message' => __('sessions.end_messages.unauthenticated'), 'reason' => null]);
    }

    public function test_person_sees_and_revokes_their_other_devices(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();
        $phone = $this->openSession($user, self::PHONE, '203.0.113.42');
        $token = $user->getRememberToken();

        $component = Livewire::actingAs($user)->test(ActiveSessions::class, ['user' => $user])
            ->call('toggle')
            ->assertSee('Chrome · Android')
            ->assertSee('203.0.113.0/24')
            ->assertDontSee('203.0.113.42')
            ->assertDontSee($phone);

        $component->call('revoke', hash('sha256', $phone))->assertDispatched('toast');

        $this->assertDatabaseMissing('sessions', ['id' => $phone]);
        $this->assertNotSame($token, $user->fresh()?->getRememberToken());
    }

    public function test_revoked_device_gets_401_with_the_reason(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();
        $phone = $this->openSession($user, self::PHONE);

        Livewire::actingAs($user)->test(ActiveSessions::class, ['user' => $user])->call('revokeAll');
        Auth::forgetGuards();

        $this->withCredentials()->withCookie(config('session.cookie'), $phone)
            ->getJson(route('geo.reverse', ['lat' => 48.85, 'lng' => 2.35]))
            ->assertUnauthorized()
            ->assertJson(['reason' => 'session_revoked']);
    }

    public function test_the_current_device_is_never_revoked_from_the_list(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();
        $current = $this->openSession($user, self::PHONE);
        $other = $this->openSession($user, self::PHONE);
        $sessions = app(UserSessions::class);

        $this->assertFalse($sessions->revoke($user, hash('sha256', $current), $current));
        $this->assertSame(1, $sessions->revokeAll($user, $current));

        $this->assertDatabaseHas('sessions', ['id' => $current]);
        $this->assertDatabaseMissing('sessions', ['id' => $other]);
    }

    public function test_administrator_revokes_every_session_of_someone(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();
        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->openSession($technician, self::PHONE);
        $this->openSession($technician, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/131.0');

        Livewire::actingAs($admin)->test(ActiveSessions::class, ['user' => $technician])
            ->call('toggle')
            ->assertSee('Firefox · Windows')
            ->call('revokeAll');

        $this->assertDatabaseMissing('sessions', ['user_id' => $technician->id]);
    }

    public function test_nobody_else_sees_or_revokes_someone_s_sessions(): void
    {
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();
        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->openSession($technician, self::PHONE);

        Livewire::actingAs($supervisor)->test(ActiveSessions::class, ['user' => $technician])->assertNotFound();

        $this->assertDatabaseHas('sessions', ['user_id' => $technician->id]);
    }

    public function test_profile_and_user_card_show_the_sessions_section(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create(['locale' => 'fr']);

        $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertSee('Sessions actives');
    }

    private function openSession(User $user, string $userAgent, string $ip = '198.51.100.7'): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'payload' => '', 'last_activity' => time()]);

        return $id;
    }
}
