<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\TrustProxies;
use App\Models\Client;
use App\Models\ClientAccount;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Behind the reverse proxy of an installation (TRUSTED_PROXIES) the visitor's own address is used, so per-address
 * limits (client portal sign-in) hold each visitor apart; nobody else can pretend to be a proxy. Host names are
 * limited to APP_URL's and TRUSTED_HOSTS.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::getTrustedHeaderSet());

        parent::tearDown();
    }

    private function ipSeenFrom(string $remoteAddress, string $forwardedFor): string
    {
        return (string) $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->get('/_test/ip')->getContent();
    }

    public function test_the_forwarded_address_is_only_trusted_from_the_configured_proxies(): void
    {
        Route::middleware('web')->get('/_test/ip', fn (Request $request) => $request->ip());
        $kernel = app(Kernel::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);
        $this->assertTrue($kernel->hasMiddleware(TrustProxies::class));

        $this->assertSame('198.51.100.9', $this->ipSeenFrom('198.51.100.9', '203.0.113.7'), 'No proxy is trusted by default.');

        config(['platform.trusted_proxies' => '10.0.0.1, 10.0.0.2']);
        $this->assertSame('198.51.100.9', $this->ipSeenFrom('198.51.100.9', '203.0.113.7'), 'Another address cannot pretend to be a proxy.');
        $this->assertSame('203.0.113.7', $this->ipSeenFrom('10.0.0.2', '203.0.113.7'));
    }

    public function test_behind_the_proxy_the_portal_sign_in_limit_holds_each_visitor_apart(): void
    {
        Sleep::fake();
        config(['platform.trusted_proxies' => '10.0.0.1']);
        RateLimiter::clear('portal-login|203.0.113.7');
        RateLimiter::clear('portal-login|203.0.113.8');
        $client = Client::factory()->create(['code' => 'ACME']);
        ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane']);

        $signIn = fn (string $visitor, string $password) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', $visitor)
            ->from(route('portal.login', 'acme'))
            ->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => $password]);

        foreach (range(1, 8) as $attempt) {
            $signIn('203.0.113.7', 'wrong');
        }

        $signIn('203.0.113.7', 'portal-password')->assertSessionHasErrors(['username' => __('client_portal.login.throttled')]);
        $this->assertGuest('client');

        $signIn('203.0.113.8', 'portal-password')->assertRedirect(route('portal.home', 'acme'));
        $this->assertAuthenticated('client');
    }

    public function test_trusted_host_names_are_the_application_one_and_the_configured_ones(): void
    {
        config(['app.url' => 'https://planning.example.test', 'platform.trusted_hosts' => ['portal.example.test']]);

        $hosts = app(TrustHosts::class)->hosts();

        $this->assertContains('^portal\.example\.test$', $hosts);
        $this->assertContains('^(.+\.)?planning\.example\.test$', $hosts);
        $this->assertCount(2, $hosts);
    }
}
