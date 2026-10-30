<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_shown_in_the_browser_language(): void
    {
        $this->get('/login', ['Accept-Language' => 'fr-FR'])
            ->assertOk()
            ->assertSeeText('Connexion')
            ->assertSeeText('Identifiant')
            ->assertSee('name="username"', false);
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_user_logs_in_with_username_and_password(): void
    {
        $user = User::factory()->create(['username' => 'jdoe']);

        $this->post('/login', ['username' => 'JDoe', 'password' => 'password'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->post('/login', ['username' => 'jdoe', 'password' => 'wrong-password'])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_unknown_username_is_rejected(): void
    {
        $this->post('/login', ['username' => 'nobody', 'password' => 'password'])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_departed_user_cannot_log_in(): void
    {
        User::factory()->departed()->create(['username' => 'gone']);

        $this->post('/login', ['username' => 'gone', 'password' => 'password'])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_legacy_password_is_upgraded_and_must_be_changed(): void
    {
        $user = User::factory()->withLegacyPassword('old-secret')->create(['username' => 'legacy']);

        $this->post('/login', ['username' => 'legacy', 'password' => 'old-secret'])->assertRedirect('/');

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->legacy_password_hash);
        $this->assertTrue(Hash::check('old-secret', (string) $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_wrong_legacy_password_is_rejected_and_hash_kept(): void
    {
        $user = User::factory()->withLegacyPassword('old-secret')->create(['username' => 'legacy']);

        $this->post('/login', ['username' => 'legacy', 'password' => 'guess'])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertNotNull($user->refresh()->legacy_password_hash);
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['username' => 'jdoe', 'password' => 'wrong-password']);
        }

        $this->from('/login')->post('/login', ['username' => 'jdoe', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertStringContainsString('Too many attempts', (string) session('errors')->first('username'));
    }
}
