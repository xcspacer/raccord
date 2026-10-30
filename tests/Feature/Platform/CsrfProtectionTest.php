<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The framework skips the CSRF check while tests run; here it is switched back on (CSRF-03) to prove that a request
 * changing state without its token, or sent by another site, is refused with 419.
 */
class CsrfProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        Route::middleware('web')->post('/_test/csrf', fn () => 'changed');
    }

    public function test_a_post_without_token_is_refused(): void
    {
        $this->post('/_test/csrf')->assertStatus(419);
        $this->post('/login', ['username' => 'jdoe', 'password' => 'secret'])->assertStatus(419);
        $this->post('/locale', ['locale' => 'fr'])->assertStatus(419);
    }

    public function test_a_post_from_another_site_is_refused_even_with_a_cookie_session(): void
    {
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->post('/_test/csrf')->assertStatus(419);
    }

    public function test_a_post_with_the_session_token_goes_through(): void
    {
        $this->withSession(['_token' => 'known-token'])->post('/_test/csrf', ['_token' => 'known-token'])->assertOk()->assertSee('changed');
    }
}
