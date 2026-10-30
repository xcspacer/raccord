<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\OpenAudit;
use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Logs\SecurityLog;
use App\Models\Agency;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class ClientControlPageTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->office = User::factory()->withRole(Role::DesignOffice)->create();
        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    public function test_administrators_and_design_office_manage_client_accounts_supervisors_only_when_switched_on(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('client-control'))->assertOk();
        $this->actingAs($this->office)->get(route('client-control'))->assertOk();

        $supervisor = User::factory()->withRole(Role::Supervisor)->create();
        $this->actingAs($supervisor)->get(route('client-control'))->assertForbidden();
        Livewire::actingAs($supervisor)->test(ClientControlPage::class)->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('client-control'))->assertForbidden();

        RoleFeatureOverride::query()->create(['feature' => Feature::ClientControl, 'role_id' => RoleModel::findByName(Role::Supervisor->value)->id, 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($supervisor)->get(route('client-control'))->assertOk();
    }

    public function test_an_account_is_created_for_a_client_company_with_chosen_audits(): void
    {
        $audit = app(OpenAudit::class)->handle($this->office, 'PM101');

        Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->set('name', 'Jane Doe')->set('company', 'Acme Networks')->set('username', ' Jane.Doe ')
            ->set('auditIds', [$audit->id])
            ->call('create')->assertHasNoErrors();

        $account = ClientAccount::query()->with('client', 'audits')->sole();
        $this->assertSame('jane.doe', $account->username);
        $this->assertSame('Acme Networks', $account->client->name);
        $this->assertFalse($account->has_all_audits);
        $this->assertSame([$audit->id], $account->audits->pluck('id')->all());
    }

    public function test_invalid_accounts_are_refused(): void
    {
        ClientAccount::factory()->create(['username' => 'taken']);

        Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->set('name', 'Jane')->set('company', 'Acme')->set('username', 'taken')->set('allAudits', true)
            ->call('create')->assertHasErrors('username')
            ->set('username', 'bad name!')->call('create')->assertHasErrors('username')
            ->set('username', 'fine')->set('allAudits', false)->set('auditIds', [])->call('create')->assertHasErrors('auditIds');

        $this->assertSame(1, ClientAccount::query()->count());
    }

    public function test_accounts_are_switched_off_given_a_new_password_new_audits_and_deleted(): void
    {
        $account = ClientAccount::factory()->create();
        $audit = app(OpenAudit::class)->handle($this->office, 'PM5');

        $component = Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->call('toggle', $account->id);
        $this->assertFalse($account->refresh()->is_active);

        $component->call('startPassword', $account->id)->set('newPassword', 'short')->call('savePassword')->assertHasErrors('newPassword');
        $this->assertTrue(Hash::check('portal-password', $account->refresh()->password));

        $component->set('newPassword', '')->call('savePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check($this->revealedPassword($component), $account->refresh()->password));
        $this->assertTrue($account->must_change_password);

        $component->call('startAudits', $account->id)->set('editAllAudits', false)->set('editAuditIds', [$audit->id])->call('saveAudits')->assertHasNoErrors();
        $this->assertFalse($account->refresh()->has_all_audits);
        $this->assertSame([$audit->id], $account->audits()->pluck('audits.id')->all());

        $component->call('delete', $account->id);
        $this->assertModelMissing($account);
    }

    public function test_client_company_logo_and_subtitle_are_saved(): void
    {
        Storage::fake('local');
        $client = Client::factory()->create(['code' => 'ACME']);
        ClientAccount::factory()->create(['client_id' => $client->id]);

        Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->assertSee(route('portal.login', 'acme'))
            ->call('startBranding', $client->id)
            ->set('subtitle', 'North network')->set('logo', UploadedFile::fake()->image('logo.png', 200, 60))
            ->call('saveBranding')->assertHasNoErrors();

        $client->refresh();
        $this->assertSame('North network', $client->portal_subtitle);
        Storage::disk('local')->assertExists($client->logo_path);
        $this->get(route('portal.logo', 'acme'))->assertOk();
        $this->assertStringContainsString('/portal/acme/logo?v=', (string) $client->logoUrl());
        $this->get(route('portal.login', 'acme'))->assertOk()->assertSee($client->logoUrl());
    }

    public function test_switching_the_module_off_removes_the_screen_and_its_menu_entry(): void
    {
        $this->actingAs($this->office)->get(route('audits.full'))->assertSee(route('client-control'));

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['client_portal']));
        $modules->save();

        $this->actingAs($this->office)->get(route('client-control'))->assertNotFound();
        $this->actingAs($this->office)->get(route('audits.full'))->assertDontSee(route('client-control'));
        Livewire::actingAs($this->office)->test(ClientControlPage::class)->assertForbidden();
    }

    public function test_the_server_makes_the_first_password_shows_it_once_and_the_client_must_change_it(): void
    {
        $audit = app(OpenAudit::class)->handle($this->office, 'PM7');

        $component = Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->set('name', 'Jane Doe')->set('company', 'Acme Networks')->set('username', 'jane')
            ->set('auditIds', [$audit->id])
            ->call('create')->assertHasNoErrors()
            ->assertSee(__('client_portal.control.password_title'))
            ->assertSee('role="dialog"', false);

        $password = $this->revealedPassword($component);
        $account = ClientAccount::query()->sole();
        $this->assertGreaterThanOrEqual(ClientControlPage::PASSWORD_MIN, strlen($password));
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertTrue($account->must_change_password);

        $this->assertStringNotContainsString($password, json_encode($component->instance()->all()) ?: '', 'The password stays out of the component state.');
        $this->assertStringNotContainsString($password, $component->call('$refresh')->html(), 'The password is shown once.');
    }

    public function test_the_staff_cannot_choose_the_first_password(): void
    {
        $audit = app(OpenAudit::class)->handle($this->office, 'PM7');

        Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->assertDontSee('wire:model="password"', false)
            ->set('name', 'Jane Doe')->set('company', 'Acme')->set('username', 'jane')->set('auditIds', [$audit->id])
            ->call('create');

        $this->assertFalse(Hash::check('portal-password', ClientAccount::query()->sole()->password));
    }

    public function test_every_audit_is_an_explicit_confirmed_choice_recorded_for_the_administrators(): void
    {
        $handler = $this->securityLog();

        $component = Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->assertSet('allAudits', false)
            ->set('allAudits', true)
            ->assertSeeHtml('wire:confirm="'.e(__('client_portal.control.confirm_all_audits')).'"')
            ->set('name', 'Jane Doe')->set('company', 'Acme')->set('username', 'jane')
            ->call('create')->assertHasNoErrors();

        $account = ClientAccount::query()->sole();
        $this->assertTrue($account->has_all_audits);
        $this->assertSame([SecurityLog::CLIENT_ACCOUNT_CREATED, SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS], Activity::query()->where('log_name', 'client_accounts')->orderBy('id')->pluck('event')->all());
        $this->assertSame($this->office->id, Activity::query()->where('event', SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS)->sole()->causer_id);
        $this->assertTrue($handler->hasRecord(SecurityLog::CLIENT_ACCOUNT_CREATED, Level::Warning));
        $this->assertTrue($handler->hasRecord(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS, Level::Warning));

        $other = ClientAccount::factory()->create(['has_all_audits' => false]);
        $component->call('startAudits', $other->id)->set('editAllAudits', true)
            ->assertSeeHtml('wire:confirm="'.e(__('client_portal.control.confirm_all_audits')).'"')
            ->call('saveAudits')->assertHasNoErrors();

        $this->assertTrue($other->refresh()->has_all_audits);
        $this->assertSame(1, Activity::query()->where('event', SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS)->where('subject_id', $other->id)->count());

        $component->call('toggle', $other->id)->call('toggle', $other->id);
        $this->assertTrue($handler->hasRecord(SecurityLog::CLIENT_ACCOUNT_DEACTIVATED, Level::Info));
        $this->assertTrue($handler->hasRecord(SecurityLog::CLIENT_ACCOUNT_REACTIVATED, Level::Warning));

        $component->call('delete', $other->id);
        $this->assertSame(1, Activity::query()->where('event', SecurityLog::CLIENT_ACCOUNT_DELETED)->where('subject_id', $other->id)->count());
    }

    public function test_the_security_log_never_holds_the_password(): void
    {
        $handler = $this->securityLog();
        $account = ClientAccount::factory()->create();

        $component = Livewire::actingAs($this->office)->test(ClientControlPage::class)
            ->call('startPassword', $account->id)->call('savePassword');
        $password = $this->revealedPassword($component);

        $this->assertTrue($handler->hasRecord(SecurityLog::CLIENT_ACCOUNT_PASSWORD_RESET, Level::Info));
        $this->assertStringNotContainsString($password, json_encode(array_map(fn ($record) => $record->toArray(), $handler->getRecords())) ?: '');
        $this->assertStringNotContainsString($password, Activity::query()->get()->toJson());
    }

    /**
     * @param  Testable<Component>  $component
     */
    private function revealedPassword(Testable $component): string
    {
        preg_match_all('/<code class="client-control__secret">([^<]+)<\/code>/', $component->html(), $matches);
        $this->assertCount(2, $matches[1], 'The username and the password are shown.');

        return html_entity_decode($matches[1][1]);
    }

    private function securityLog(): TestHandler
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
