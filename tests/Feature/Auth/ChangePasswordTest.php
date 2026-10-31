<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_who_must_change_password_cannot_reach_the_platform(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee('name="current_password"', false);
    }

    public function test_password_change_lifts_the_restriction(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->from(route('password.change'))->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-password', (string) $user->password));
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_forced_change_signs_out_the_other_devices_and_keeps_this_one_remembered(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->withRole(Role::Technician)->create(['must_change_password' => true]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = $user->getRememberToken();

        $response = $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNotSame($token, $user->getRememberToken());

        $recaller = $response->getCookie(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($recaller);
        $this->assertStringStartsWith($user->id.'|'.$user->getRememberToken().'|', (string) $recaller->getValue());
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $recaller->getExpiresTime(), 5);
    }

    public function test_new_password_must_be_long_enough_and_different(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_eight_characters_are_enough_for_every_role(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->withRole(Role::Administrator)->create(['must_change_password' => true]);

        $this->actingAs($admin)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'seven-c',
            'password_confirmation' => 'seven-c',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->actingAs($admin)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'eight-ch',
            'password_confirmation' => 'eight-ch',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('eight-ch', (string) $admin->refresh()->password));
    }

    public function test_password_change_page_is_not_shown_when_not_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('password.change'))->assertRedirect('/');
    }
}
