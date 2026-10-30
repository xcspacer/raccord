<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Vite;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $devOrigin = $this->viteDevOrigin();
        $viteDevServer = $devOrigin !== null ? ' '.$devOrigin : '';
        $viteDevSocket = $devOrigin !== null ? ' '.preg_replace('/^http/', 'ws', $devOrigin) : '';

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$viteDevServer}",
            "style-src 'self' 'nonce-{$nonce}'{$this->inlineStyleHashes($response)}{$viteDevServer}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'{$viteDevServer}{$viteDevSocket}",
            "worker-src 'self' blob:",
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);

        // Stored files carry their own, stricter policy (App\Support\FileResponse).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $policy);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Origin of the Vite dev server (local hot reload only), allowed by the policy during development.
     */
    private function viteDevOrigin(): ?string
    {
        $hotFile = public_path('hot');

        if (! app()->environment('local') || ! is_file($hotFile)) {
            return null;
        }

        return rtrim(trim((string) file_get_contents($hotFile)), '/');
    }

    /**
     * Hashes of the inline style blocks the server itself writes (the fonts and the Livewire styles). Livewire reads the
     * HTML of the next page before showing it, and that copy carries another nonce: the hashes keep it from being
     * reported as a violation, while nothing but these exact blocks is allowed.
     */
    private function inlineStyleHashes(Response $response): string
    {
        $html = $response instanceof HttpResponse && str_contains((string) $response->headers->get('Content-Type'), 'text/html') ? (string) $response->getContent() : '';

        if ($html === '') {
            return '';
        }

        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $html.$this->livewireStyles(), $blocks);

        return collect($blocks[1])->unique()->map(fn (string $css) => " 'sha256-".base64_encode(hash('sha256', $css, true))."'")->implode('');
    }

    /**
     * The style block Livewire adds to the page, read without telling Livewire it was already written.
     */
    private function livewireStyles(): string
    {
        $assets = app(FrontendAssets::class);
        $written = $assets->hasRenderedStyles;
        $styles = FrontendAssets::styles();
        $assets->hasRenderedStyles = $written;

        return $styles;
    }
}
