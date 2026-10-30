<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\OpenAudit;
use App\Enums\Role;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('portal-login|127.0.0.1');
        $this->client = Client::factory()->create(['code' => 'ACME', 'name' => 'Acme Networks']);
        $this->account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'username' => 'jane.doe', 'name' => 'Jane Doe']);
    }

    private function signIn(string $username = 'jane.doe', string $password = 'portal-password', string $slug = 'acme'): TestResponse
    {
        return $this->from(route('portal.login', $slug))->post(route('portal.login.store', $slug), ['username' => $username, 'password' => $password]);
    }

    /**
     * Each real request loads the account again; the test application keeps guards between requests.
     */
    private function home(string $slug = 'acme'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get(route('portal.home', $slug));
    }

    public function test_sign_in_page_shows_the_client_company(): void
    {
        $this->client->update(['portal_subtitle' => 'North network']);

        $this->get(route('portal.login', 'acme'))->assertOk()->assertSee('Acme Networks')->assertSee('North network');
        $this->get('/portal/unknown/login')->assertNotFound();
    }

    public function test_an_active_account_signs_in_and_out(): void
    {
        $this->signIn(' Jane.Doe ')->assertRedirect(route('portal.home', 'acme'));

        $this->assertNotNull($this->account->refresh()->last_login_at);
        $this->get(route('portal.home', 'acme'))->assertOk()->assertSee('Jane Doe');

        $this->post(route('portal.logout', 'acme'))->assertRedirect(route('portal.login', 'acme'));
        $this->get(route('portal.home', 'acme'))->assertRedirect(route('portal.login', 'acme'));
    }

    public function test_wrong_password_inactive_account_or_other_company_are_refused(): void
    {
        $this->signIn(password: 'wrong')->assertSessionHasErrors('username');
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 400.0, 1);

        ClientAccount::factory()->inactive()->create(['client_id' => $this->client->id, 'username' => 'off']);
        $this->signIn('off')->assertSessionHasErrors('username');

        Client::factory()->create(['code' => 'OTHER']);
        $this->signIn(slug: 'other')->assertSessionHasErrors('username');

        $this->assertGuest('client');
    }

    public function test_eight_failures_from_one_address_block_sign_in(): void
    {
        foreach (range(1, 8) as $attempt) {
            $this->signIn(password: 'wrong');
        }

        $this->signIn()->assertSessionHasErrors(['username' => __('client_portal.login.throttled')]);
        $this->assertGuest('client');
    }

    public function test_session_ends_after_twelve_hours_or_when_the_account_changes(): void
    {
        $this->signIn();
        $this->travel(13)->hours();
        $this->home()->assertRedirect(route('portal.login', 'acme'))->assertSessionHas('portal_expired', true);
        $this->travelBack();

        $this->signIn();
        $this->account->update(['password' => 'another-password']);
        $this->home()->assertRedirect(route('portal.login', 'acme'));

        $this->signIn(password: 'another-password');
        $this->account->update(['is_active' => false]);
        $this->home()->assertRedirect(route('portal.login', 'acme'));

        $this->account->update(['is_active' => true]);
        $this->signIn(password: 'another-password');
        $this->account->delete();
        $this->home()->assertRedirect(route('portal.login', 'acme'));
    }

    public function test_a_session_is_only_valid_on_its_own_company_portal(): void
    {
        Client::factory()->create(['code' => 'OTHER']);
        $this->signIn();

        $this->home('other')->assertRedirect(route('portal.login', 'other'));
    }

    public function test_a_staff_session_does_not_open_the_portal(): void
    {
        $this->seed(RoleSeeder::class);

        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())
            ->get(route('portal.home', 'acme'))->assertRedirect(route('portal.login', 'acme'));
    }

    public function test_the_account_only_sees_audits_with_boxes_that_it_was_given(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->withRole(Role::Administrator)->create();
        $given = app(OpenAudit::class)->handle($manager, 'PM10');
        $other = app(OpenAudit::class)->handle($manager, 'PM2');
        $empty = app(OpenAudit::class)->handle($manager, 'PM3');
        $given->boxes()->create(['name' => 'BPE-1', 'kind' => 'bpe', 'source' => 'manager']);
        $other->boxes()->create(['name' => 'BPE-2', 'kind' => 'bpe', 'source' => 'manager']);

        $this->assertSame(['PM10', 'PM2'], $this->account->visibleAudits()->pluck('cabinet_key')->sort()->values()->all());
        $this->assertNotContains($empty->cabinet_key, $this->account->visibleAudits()->pluck('cabinet_key'));

        $this->account->update(['has_all_audits' => false]);
        $this->account->audits()->sync([$given->id]);

        $this->assertSame(['PM10'], $this->account->visibleAudits()->pluck('cabinet_key')->all());
        $this->assertInstanceOf(Audit::class, $this->account->visibleAudits()->first());
    }

    public function test_the_portal_is_closed_when_its_module_is_off(): void
    {
        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['client_portal']));
        $modules->save();

        $this->get(route('portal.login', 'acme'))->assertNotFound();
    }
}
