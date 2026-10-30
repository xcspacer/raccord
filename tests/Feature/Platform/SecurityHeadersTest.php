<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_web_responses_carry_security_headers(): void
    {
        Route::middleware('web')->get('/_test/headers', fn () => 'ok');

        $response = $this->get('/_test/headers');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }

    public function test_the_inline_styles_written_by_the_server_are_allowed_by_their_hash_only(): void
    {
        Route::middleware('web')->get('/_test/styles', fn () => response('<html><head><style>a{color:red}</style></head><body></body></html>'));

        $policy = (string) $this->get('/_test/styles')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'sha256-".base64_encode(hash('sha256', 'a{color:red}', true))."'", $policy);
        $this->assertStringNotContainsString('unsafe-inline', $policy);
    }

    public function test_reading_the_livewire_styles_does_not_stop_livewire_from_writing_them(): void
    {
        app(FrontendAssets::class)->hasRenderedStyles = false;

        (new SecurityHeaders)->handle(Request::create('/'), fn () => response('<html><head></head><body></body></html>'));

        $this->assertFalse(app(FrontendAssets::class)->hasRenderedStyles);
    }

    public function test_pages_do_not_preload_their_stylesheet(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('rel="preload" as="style"', false);
    }
}
