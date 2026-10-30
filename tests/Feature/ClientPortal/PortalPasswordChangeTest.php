<?php

namespace Tests\Feature\ClientPortal;

use App\Livewire\ClientPortal\PortalPage;
use App\Models\Client;
use App\Models\ClientAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * An account given its password by the staff (new account or new password) chooses its own at the next sign-in,
 * before reaching anything else of the portal.
 */
class PortalPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('portal-login|127.0.0.1');
        $this->client = Client::factory()->create(['code' => 'ACME']);
        $this->account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'username' => 'jane']);
        $this->account->forceFill(['must_change_password' => true])->save();
    }

    /**
     * @return TestResponse<Response>
     */
    private function signIn(): TestResponse
    {
        return $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
    }

    /**
     * @param  array<string, string>  $input
     * @return TestResponse<Response>
     */
    private function change(array $input): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->from(route('portal.password', 'acme'))->put(route('portal.password.update', 'acme'), $input);
    }

    public function test_the_first_sign_in_leads_to_the_password_change_and_nothing_else_opens(): void
    {
        $this->signIn()->assertRedirect(route('portal.password', 'acme'));

        $this->get(route('portal.password', 'acme'))->assertOk()->assertSee(__('client_portal.password_change.title'));
        $this->get(route('portal.home', 'acme'))->assertRedirect(route('portal.password', 'acme'));
        $this->getJson(route('portal.pdf', ['acme', 'box', 1]))->assertForbidden();
        $this->post(route('portal.zip', 'acme'), ['items' => ['box:1']])->assertRedirect(route('portal.password', 'acme'));
    }

    public function test_the_portal_page_component_refuses_actions_until_the_password_is_changed(): void
    {
        $this->signIn();
        $this->app['auth']->forgetGuards();

        Livewire::test(PortalPage::class, ['portalClient' => $this->client])->assertRedirect(route('portal.password', 'acme'));
    }

    public function test_the_current_password_is_required_and_the_new_one_must_be_long_new_and_confirmed(): void
    {
        $this->signIn();

        $this->change(['current_password' => 'wrong', 'password' => 'a-brand-new-secret', 'password_confirmation' => 'a-brand-new-secret'])
            ->assertSessionHasErrors(['current_password' => __('client_portal.password_change.wrong_current')]);
        $this->change(['current_password' => 'portal-password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->change(['current_password' => 'portal-password', 'password' => 'a-brand-new-secret', 'password_confirmation' => 'other-secret-xx'])->assertSessionHasErrors('password');
        $this->change(['current_password' => 'portal-password', 'password' => 'portal-password', 'password_confirmation' => 'portal-password'])
            ->assertSessionHasErrors(['password' => __('client_portal.password_change.same')]);

        $this->assertTrue($this->account->refresh()->must_change_password);
    }

    public function test_a_new_password_opens_the_portal_and_keeps_the_session(): void
    {
        $this->signIn();

        $this->change(['current_password' => 'portal-password', 'password' => 'a-brand-new-secret', 'password_confirmation' => 'a-brand-new-secret'])
            ->assertRedirect(route('portal.home', 'acme'));

        $this->account->refresh();
        $this->assertFalse($this->account->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-secret', $this->account->password));

        $this->app['auth']->forgetGuards();
        $this->get(route('portal.home', 'acme'))->assertOk();
        $this->get(route('portal.password', 'acme'))->assertRedirect(route('portal.home', 'acme'));
    }

    public function test_the_password_change_needs_a_client_session(): void
    {
        $this->get(route('portal.password', 'acme'))->assertRedirect(route('portal.login', 'acme'));
        $this->put(route('portal.password.update', 'acme'), ['current_password' => 'portal-password', 'password' => 'a-brand-new-secret', 'password_confirmation' => 'a-brand-new-secret'])
            ->assertRedirect(route('portal.login', 'acme'));

        $this->assertTrue(Hash::check('portal-password', $this->account->refresh()->password));
    }
}
