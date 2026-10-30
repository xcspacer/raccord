<?php

namespace Tests\Feature\Platform;

use App\Actions\Users\ChangeUserRole;
use App\Actions\Users\CreateUser;
use App\Audit\OpenAudit;
use App\Enums\Role;
use App\Jobs\AlertAdministrators;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Logs\AdministratorAlerts;
use App\Logs\SecurityLog;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\AdministratorAlert;
use App\Settings\CompanySettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Administrators are e-mailed (LOG-06, PORT-13) about a new client portal account, a client account given every
 * audit, a new administrator and sign-in lockouts.
 */
class AdministratorAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function assertAlerted(string $event, int $times = 1): void
    {
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->event === $event);
        $this->assertCount($times, Bus::dispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->event === $event), $event);
    }

    public function test_a_new_client_account_and_every_audit_given_to_one_are_announced(): void
    {
        Bus::fake([AlertAdministrators::class]);
        $office = User::factory()->withRole(Role::DesignOffice)->create(['name' => 'Olive Office']);
        $audit = app(OpenAudit::class)->handle($office, 'PM101');
        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

        $component = Livewire::actingAs($office)->test(ClientControlPage::class)
            ->set('name', 'Jane Doe')->set('company', 'Acme Networks')->set('username', 'jane')->set('auditIds', [$audit->id])
            ->call('create')->assertHasNoErrors();

        $this->assertAlerted(SecurityLog::CLIENT_ACCOUNT_CREATED);
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->replace === ['by' => 'Olive Office', 'account' => 'Jane Doe', 'username' => 'jane', 'company' => 'Acme Networks']);
        Bus::assertNotDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->event === SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS);

        $account = ClientAccount::query()->sole();
        $component->call('startAudits', $account->id)->set('editAllAudits', true)->call('saveAudits')->assertHasNoErrors();
        $component->call('startAudits', $account->id)->set('editAllAudits', true)->call('saveAudits')->assertHasNoErrors();
        $component->call('startAudits', $account->id)->set('editAllAudits', false)->set('editAuditIds', [$audit->id])->call('saveAudits')->assertHasNoErrors();
        $this->assertAlerted(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS);

        $component->set('name', 'Joe Bloggs')->set('company', 'Acme Networks')->set('username', 'joe')->set('allAudits', true)->call('create')->assertHasNoErrors();
        $this->assertAlerted(SecurityLog::CLIENT_ACCOUNT_CREATED, 2);
        $this->assertAlerted(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS, 2);
    }

    public function test_a_new_administrator_is_announced_other_people_are_not(): void
    {
        Bus::fake([AlertAdministrators::class]);
        $this->actingAs(User::factory()->withRole(Role::Administrator)->create(['name' => 'Ada Admin']));
        $data = ['name' => 'Bob Boss', 'username' => 'bob', 'agency_id' => null, 'email' => null, 'phone' => null, 'vehicle' => null, 'password' => 'initial-secret-1'];

        app(CreateUser::class)->handle([...$data, 'role' => Role::Administrator]);
        app(CreateUser::class)->handle([...$data, 'username' => 'tom', 'role' => Role::Technician]);

        $this->assertAlerted(SecurityLog::USER_CREATED);
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->replace === ['by' => 'Ada Admin', 'name' => 'Bob Boss', 'username' => 'bob']);
    }

    public function test_a_promotion_to_administrator_is_announced_other_role_changes_are_not(): void
    {
        Bus::fake([AlertAdministrators::class]);
        $admin = User::factory()->withRole(Role::Administrator)->create(['name' => 'Ada Admin']);
        $promoted = User::factory()->withRole(Role::Technician)->create(['name' => 'Tom Tech', 'username' => 'tom']);
        $moved = User::factory()->withRole(Role::Technician)->create();

        app(ChangeUserRole::class)->handle($admin, $promoted, Role::Administrator);
        app(ChangeUserRole::class)->handle($admin, $moved, Role::Supervisor);

        $this->assertAlerted(SecurityLog::ROLE_CHANGED);
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->replace === ['by' => 'Ada Admin', 'name' => 'Tom Tech', 'username' => 'tom']);
    }

    public function test_staff_and_portal_lockouts_are_announced_at_most_once_an_hour(): void
    {
        Bus::fake([AlertAdministrators::class]);
        Sleep::fake();
        RateLimiter::clear('portal-login|127.0.0.1');
        User::factory()->create(['username' => 'jdoe']);
        $client = Client::factory()->create(['code' => 'ACME', 'name' => 'Acme Networks']);
        ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane']);

        foreach (range(1, 10) as $attempt) {
            $this->post('/login', ['username' => 'JDoe', 'password' => 'wrong-secret']);
            $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'wrong-secret']);
        }

        $this->assertAlerted(SecurityLog::LOGIN_THROTTLED);
        $this->assertAlerted(SecurityLog::PORTAL_LOGIN_THROTTLED);
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->replace === ['username' => 'jdoe']);
        Bus::assertDispatchedAfterResponse(AlertAdministrators::class, fn (AlertAdministrators $job) => $job->replace === ['company' => 'Acme Networks', 'username' => 'jane']);

        $this->travel(AdministratorAlerts::LOCKOUT_ALERT_SECONDS + 1)->seconds();
        app(AdministratorAlerts::class)->lockout(SecurityLog::LOGIN_THROTTLED, ['username' => 'jdoe']);

        $this->assertAlerted(SecurityLog::LOGIN_THROTTLED, 2);
    }

    public function test_only_present_administrators_with_an_address_are_e_mailed_each_in_their_language(): void
    {
        Notification::fake();
        $french = User::factory()->withRole(Role::Administrator)->create(['locale' => 'fr']);
        $default = User::factory()->withRole(Role::Administrator)->create();
        $departed = User::factory()->departed()->withRole(Role::Administrator)->create();
        $withoutAddress = User::factory()->withRole(Role::Administrator)->create(['email' => null]);
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();

        Bus::dispatchSync(new AlertAdministrators(SecurityLog::USER_CREATED, ['by' => 'Ada', 'name' => 'Bob', 'username' => 'bob']));

        Notification::assertSentTo($french, AdministratorAlert::class, fn (AdministratorAlert $alert) => $alert->locale === 'fr' && $alert->event === SecurityLog::USER_CREATED);
        Notification::assertSentTo($default, AdministratorAlert::class, fn (AdministratorAlert $alert) => $alert->locale === config('app.locale'));
        Notification::assertNotSentTo([$departed, $withoutAddress, $supervisor], AdministratorAlert::class);
        Notification::assertCount(2);
    }

    public function test_the_e_mail_is_written_in_the_language_of_the_administrator_from_the_platform_sender(): void
    {
        $company = app(CompanySettings::class);
        $company->name = 'Fibre Works';
        $company->mail_from_address = 'alerts@example.test';
        $company->save();
        $administrator = User::factory()->withRole(Role::Administrator)->create(['name' => 'Ada', 'email' => 'ada@example.test', 'locale' => 'fr']);

        Bus::dispatchSync(new AlertAdministrators(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS, ['by' => 'Olive', 'account' => 'Jane Doe', 'username' => 'jane', 'company' => 'Acme']));

        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $messages = $transport->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('ada@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('alerts@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('Fibre Works', $email->getFrom()[0]->getName());
        $this->assertSame('[Fibre Works] '.__('alerts.events.client_account_all_audits.subject', [], 'fr'), $email->getSubject());
        $this->assertStringContainsString('Bonjour Ada', (string) $email->getTextBody());
        $this->assertStringContainsString(__('alerts.events.client_account_all_audits.body', ['by' => 'Olive', 'account' => 'Jane Doe', 'username' => 'jane', 'company' => 'Acme'], 'fr'), (string) $email->getTextBody());
        $this->assertStringContainsString('Olive', (string) $email->getHtmlBody());
        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    public function test_without_a_platform_sender_the_installation_default_is_used(): void
    {
        config(['mail.from.address' => 'default@example.test']);
        User::factory()->withRole(Role::Administrator)->create(['email' => 'ada@example.test']);

        Bus::dispatchSync(new AlertAdministrators(SecurityLog::LOGIN_THROTTLED, ['username' => 'jdoe']));

        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $email = $transport->messages()->first()?->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('default@example.test', $email->getFrom()[0]->getAddress());
    }
}
