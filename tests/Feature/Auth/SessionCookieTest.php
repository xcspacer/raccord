<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class SessionCookieTest extends TestCase
{
    public function test_production_session_cookie_is_host_only_and_secure(): void
    {
        $session = $this->sessionConfigFor(['APP_ENV' => 'production']);

        $this->assertTrue($session['secure']);
        $this->assertSame('__Host-session', $session['cookie']);

        config(['session.secure' => $session['secure'], 'session.cookie' => $session['cookie']]);

        $setCookie = implode("\n", $this->get('/login')->headers->all('set-cookie'));

        $this->assertMatchesRegularExpression('/^__Host-session=[^;]+; expires=[^;]+; Max-Age=\d+; path=\/; secure; httponly; samesite=lax$/mi', $setCookie);
        $this->assertStringNotContainsStringIgnoringCase('domain=', $setCookie);
    }

    public function test_local_http_keeps_a_plain_cookie(): void
    {
        $session = $this->sessionConfigFor(['APP_ENV' => 'local']);

        $this->assertFalse($session['secure']);
        $this->assertSame('session', $session['cookie']);
    }

    public function test_cookie_domain_drops_the_host_prefix(): void
    {
        $session = $this->sessionConfigFor(['APP_ENV' => 'production', 'SESSION_DOMAIN' => '.example.test']);

        $this->assertTrue($session['secure']);
        $this->assertSame('session', $session['cookie']);
    }

    /**
     * Reads config/session.php as it would be with these environment variables.
     *
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function sessionConfigFor(array $environment): array
    {
        $keys = ['APP_ENV', 'SESSION_SECURE_COOKIE', 'SESSION_COOKIE', 'SESSION_DOMAIN'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            if (isset($environment[$key])) {
                $_ENV[$key] = $_SERVER[$key] = $environment[$key];
                putenv($key.'='.$environment[$key]);
            }
        }

        try {
            return require config_path('session.php');
        } finally {
            foreach ($saved as $key => [$env, $server, $put]) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                if ($env !== null) {
                    $_ENV[$key] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }

                if ($put !== false) {
                    putenv($key.'='.$put);
                }
            }
        }
    }
}
