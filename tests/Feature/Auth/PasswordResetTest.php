<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Logs\SecurityLog;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\Settings\CompanySettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * « Forgot password »: a link by e-mail, without ever telling whether an account uses the address.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_links_to_the_request_page(): void
    {
        $this->get(route('login'), ['Accept-Language' => 'fr-FR'])
            ->assertOk()
            ->assertSee(route('password.request'), false)
            ->assertSeeText('Mot de passe oublié ?');
    }

    public function test_the_request_page_tells_people_without_e_mail_to_ask_an_administrator(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSeeText(__('password_reset.no_email_hint'));
    }

    public function test_signed_in_people_do_not_reach_the_reset_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('password.request'))->assertRedirect('/');
        $this->actingAs($user)->get(route('password.reset', ['token' => 'any']))->assertRedirect('/');
    }

    public function test_every_request_gets_the_same_answer_and_only_active_accounts_get_the_e_mail(): void
    {
        Notification::fake();
        $active = User::factory()->create(['email' => 'active@example.test']);
        $departed = User::factory()->departed()->create(['email' => 'departed@example.test']);
        $deleted = User::factory()->create(['email' => 'deleted@example.test']);
        $deleted->delete();
        User::factory()->create(['email' => null]);

        foreach (['active@example.test', 'unknown@example.test', 'departed@example.test', 'deleted@example.test'] as $email) {
            $this->from(route('password.request'))
                ->post(route('password.email'), ['email' => $email])
                ->assertRedirect(route('password.request'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', __('passwords.sent'));
        }

        Notification::assertSentTo($active, PasswordResetLink::class);
        Notification::assertNotSentTo([$departed, $deleted], PasswordResetLink::class);
        Notification::assertCount(1);
    }

    public function test_a_second_request_right_away_gets_the_same_answer_without_a_second_e_mail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'active@example.test']);

        $this->post(route('password.email'), ['email' => 'active@example.test'])->assertSessionHas('status', __('passwords.sent'));
        $this->post(route('password.email'), ['email' => 'active@example.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));

        Notification::assertSentToTimes($user, PasswordResetLink::class, 1);
    }

    public function test_requests_are_throttled_per_e_mail(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$attempt])
                ->post(route('password.email'), ['email' => 'someone@example.test'])
                ->assertSessionHasNoErrors();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'Someone@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');
    }

    public function test_requests_are_throttled_per_address(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('password.email'), ['email' => "person{$attempt}@example.test"])->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'another@example.test'])->assertSessionHasErrors('email');
    }

    public function test_the_e_mail_is_in_the_language_of_the_person_from_the_platform_sender(): void
    {
        $company = app(CompanySettings::class);
        $company->name = 'Fibre Works';
        $company->mail_from_address = 'accounts@example.test';
        $company->save();
        User::factory()->create(['name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.test', 'locale' => 'fr']);

        $this->post(route('password.email'), ['email' => 'ada@example.test'])->assertSessionHasNoErrors();

        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->first()?->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('ada@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('accounts@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('Fibre Works', $email->getFrom()[0]->getName());
        $this->assertSame(__('password_reset.mail.subject', ['platform' => 'Fibre Works'], 'fr'), $email->getSubject());
        $this->assertStringContainsString('Bonjour Ada', (string) $email->getTextBody());
        $this->assertStringContainsString(__('password_reset.mail.expiry', ['count' => 60], 'fr'), (string) $email->getTextBody());
        $this->assertStringContainsString('ada', (string) $email->getTextBody());
        $this->assertStringContainsString(url('/reset-password').'/', (string) $email->getTextBody());
        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    public function test_the_reset_page_shows_the_form(): void
    {
        $this->get(route('password.reset', ['token' => 'a-token', 'email' => 'ada@example.test']))
            ->assertOk()
            ->assertSee('name="token" value="a-token"', false)
            ->assertSee('value="ada@example.test"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_a_valid_link_sets_the_new_password_and_signs_out_every_device(): void
    {
        $handler = $this->fakeSecurityLog();
        $user = User::factory()->create(['email' => 'ada@example.test', 'must_change_password' => true, 'legacy_password_hash' => 'salt:hash', 'legacy_password_expires_at' => now()->addDays(10)]);
        DB::table('sessions')->insert([
            ['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $rememberToken = $user->getRememberToken();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'ada@example.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'))->assertSessionHas('status', __('passwords.reset'));

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', (string) $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->legacy_password_hash);
        $this->assertNull($user->legacy_password_expires_at);
        $this->assertNotSame($rememberToken, $user->getRememberToken());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ada@example.test']);
        $this->assertGuest();

        $records = array_values(array_filter($handler->getRecords(), fn (LogRecord $record) => $record->message === SecurityLog::PASSWORD_RESET_BY_EMAIL));
        $this->assertCount(1, $records);
        $this->assertSame($user->id, $records[0]->context['user_id']);
        $this->assertStringNotContainsString('a-brand-new-password', json_encode(array_map(fn (LogRecord $record) => $record->toArray(), $handler->getRecords())) ?: '');

        $this->get(route('login'))->assertSeeText(__('passwords.reset'));
        $this->post(route('login'), ['username' => $user->username, 'password' => 'a-brand-new-password'])->assertRedirect('/');
    }

    public function test_the_new_password_follows_the_password_rules_of_the_role(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->withRole(Role::Administrator)->create(['email' => 'admin@example.test']);
        $token = Password::broker()->createToken($admin);

        $this->from(route('password.reset', ['token' => $token]))->post(route('password.update'), [
            'token' => $token,
            'email' => 'admin@example.test',
            'password' => 'eleven-char',
            'password_confirmation' => 'eleven-char',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'admin@example.test',
            'password' => 'twelve-chars-ok',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', (string) $admin->refresh()->password));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'admin@example.test']);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $token = Password::broker()->createToken($user);
        $this->travel(61)->minutes();

        $this->post(route('password.update'), $this->resetInput($token, 'ada@example.test'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('password', (string) $user->refresh()->password));
    }

    public function test_an_invalid_link_or_unknown_e_mail_gets_the_same_refusal(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), $this->resetInput('not-the-token', 'ada@example.test'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->post(route('password.update'), $this->resetInput($token, 'unknown@example.test'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('password', (string) $user->refresh()->password));
    }

    public function test_departed_and_deleted_people_can_never_complete_a_reset(): void
    {
        $departed = User::factory()->create(['email' => 'departed@example.test']);
        $departedToken = Password::broker()->createToken($departed);
        $departed->forceFill(['departure_date' => now()->subDay()->toDateString()])->save();
        $deleted = User::factory()->create(['email' => 'deleted@example.test']);
        $deletedToken = Password::broker()->createToken($deleted);
        $deleted->delete();

        $this->post(route('password.update'), $this->resetInput($departedToken, 'departed@example.test'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->post(route('password.update'), $this->resetInput($deletedToken, 'deleted@example.test'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('password', (string) $departed->refresh()->password));
        $this->assertTrue(Hash::check('password', (string) User::withTrashed()->findOrFail($deleted->id)->password));
    }

    public function test_changing_the_own_password_completes_without_error(): void
    {
        $forced = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($forced)->from(route('password.change'))->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('password.change'))->assertSessionHasNoErrors();

        $user = User::factory()->create();

        $this->actingAs($user)->from('/')->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'another-new-password',
            'password_confirmation' => 'another-new-password',
        ])->assertRedirect('/')->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('another-new-password', (string) $user->refresh()->password));
    }

    /**
     * @return array{token: string, email: string, password: string, password_confirmation: string}
     */
    private function resetInput(string $token, string $email): array
    {
        return ['token' => $token, 'email' => $email, 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'];
    }

    private function fakeSecurityLog(): TestHandler
    {
        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel(SecurityLog::CHANNEL);

        $logger = Log::channel(SecurityLog::CHANNEL);
        $this->assertInstanceOf(Logger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
