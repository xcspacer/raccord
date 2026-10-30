<?php

namespace Tests\Feature\Platform;

use App\Actions\Users\CreateUser;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Logs\SecurityLog;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Support\UserSessions;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * The security log (LOG-05): its own daily file kept a year, with sign-in failures, lockouts, new administrators and
 * revoked sessions, each with the address and the browser, never a password.
 */
class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel(SecurityLog::CHANNEL);

        $logger = Log::channel(SecurityLog::CHANNEL);
        $this->assertInstanceOf(Logger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);
        $this->handler = $handler;
    }

    private function record(string $event): LogRecord
    {
        $records = array_values(array_filter($this->handler->getRecords(), fn (LogRecord $record) => $record->message === $event));
        $this->assertNotEmpty($records, "No « {$event} » in the security log.");

        return $records[0];
    }

    private function assertNoPassword(string $password): void
    {
        $this->assertStringNotContainsString($password, json_encode(array_map(fn (LogRecord $record) => $record->toArray(), $this->handler->getRecords())) ?: '');
    }

    public function test_the_channel_is_its_own_daily_file_kept_a_year(): void
    {
        $channel = require config_path('logging.php');

        $this->assertSame('daily', $channel['channels']['security']['driver']);
        $this->assertSame(storage_path('logs/security.log'), $channel['channels']['security']['path']);
        $this->assertSame('info', $channel['channels']['security']['level']);
        $this->assertSame(365, $channel['channels']['security']['days']);
    }

    public function test_staff_sign_in_failures_and_lockouts_are_logged_without_the_password(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        foreach (range(1, 6) as $attempt) {
            $this->withServerVariables(['HTTP_USER_AGENT' => 'TestBrowser/1.0'])->post('/login', ['username' => 'JDoe', 'password' => 'wrong-secret-'.$attempt]);
        }

        $failure = $this->record(SecurityLog::LOGIN_FAILED);
        $this->assertSame(Level::Info, $failure->level);
        $this->assertSame('jdoe', $failure->context['username']);
        $this->assertSame('127.0.0.1', $failure->context['ip']);
        $this->assertSame('TestBrowser/1.0', $failure->context['user_agent']);
        $this->assertSame(Level::Warning, $this->record(SecurityLog::LOGIN_THROTTLED)->level);
        $this->assertNoPassword('wrong-secret');
    }

    public function test_client_portal_sign_in_failures_and_lockouts_are_logged(): void
    {
        Sleep::fake();
        RateLimiter::clear('portal-login|127.0.0.1');
        $client = Client::factory()->create(['code' => 'ACME']);
        ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane']);

        foreach (range(1, 9) as $attempt) {
            $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'wrong-secret']);
        }

        $this->assertSame($client->id, $this->record(SecurityLog::PORTAL_LOGIN_FAILED)->context['client_id']);
        $this->assertSame(Level::Warning, $this->record(SecurityLog::PORTAL_LOGIN_THROTTLED)->level);
        $this->assertNoPassword('wrong-secret');
    }

    public function test_a_new_administrator_is_a_warning(): void
    {
        $this->seed(RoleSeeder::class);
        $data = ['name' => 'Ada Admin', 'username' => 'ada', 'agency_id' => null, 'email' => null, 'phone' => null, 'vehicle' => null, 'password' => 'initial-secret-1'];

        $admin = app(CreateUser::class)->handle([...$data, 'role' => Role::Administrator]);
        app(CreateUser::class)->handle([...$data, 'username' => 'tom', 'role' => Role::Technician]);

        $records = array_values(array_filter($this->handler->getRecords(), fn (LogRecord $record) => $record->message === SecurityLog::USER_CREATED));
        $this->assertCount(2, $records);
        $this->assertSame(Level::Warning, $records[0]->level);
        $this->assertSame($admin->id, $records[0]->context['user_id']);
        $this->assertSame(Level::Info, $records[1]->level);
        $this->assertNoPassword('initial-secret-1');
    }

    public function test_sessions_signed_out_on_other_devices_are_logged(): void
    {
        $user = User::factory()->create();

        event(new OtherDeviceLogout('web', $user));

        $this->assertSame($user->id, $this->record(SecurityLog::SESSIONS_REVOKED)->context['user_id']);
    }

    public function test_devices_signed_out_remotely_are_logged(): void
    {
        $user = User::factory()->create();
        foreach (['first-device', 'second-device'] as $id) {
            DB::table(config('session.table'))->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }

        app(UserSessions::class)->revokeAll($user, null);

        $record = $this->record(SecurityLog::SESSIONS_REVOKED);
        $this->assertSame($user->id, $record->context['user_id']);
        $this->assertSame(2, $record->context['sessions']);
        $this->assertSame(SessionEndReason::SessionRevoked->value, $record->context['reason']);
    }
}
