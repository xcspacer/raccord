<?php

namespace Tests\Feature\Platform;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Rules the whole code base keeps: every route needs a signed-in session unless it is on the public list, Blade
 * never prints unescaped output outside the reviewed places, and no client or vendor identity is written anywhere.
 */
class ArchitectureTest extends TestCase
{
    /**
     * Routes reachable without a signed-in staff or client session, and why.
     *
     * @var array<string, string>
     */
    private const array PUBLIC_ROUTES = [
        'login' => 'staff sign-in page',
        'login.store' => 'staff sign-in',
        'password.request' => 'staff « forgot password » page (guests only)',
        'password.email' => 'staff « forgot password » request: same answer for every e-mail, throttled per e-mail and address',
        'password.reset' => 'staff new password page, reached from the e-mailed link (guests only)',
        'password.update' => 'staff new password: only with a valid token of the broker (60 minutes), never for departed people',
        'locale.update' => 'language switch, also on the sign-in pages',
        'branding.logo' => 'company logo shown on the sign-in pages',
        'web-manifest' => 'PWA manifest',
        'fibre-code.app.manifest' => 'Code Fibre PWA manifest',
        'portal.login' => 'client portal sign-in page',
        'portal.login.store' => 'client portal sign-in',
        'portal.logout' => 'client portal sign-out (ends whatever session there is)',
        'portal.logo' => 'client company logo shown on its sign-in page',
        'storage.local' => 'private files, only through a temporary signed URL',
        'storage.local.upload' => 'private uploads, only through a temporary signed URL',
        'livewire.update' => 'Livewire requests (each component authorises itself; rate limited)',
        'livewire.upload-file' => 'Livewire uploads, only through a signed URL',
        'livewire.preview-file' => 'Livewire upload previews, only through a signed URL',
    ];

    /** Unnamed public routes: the health check and the Livewire scripts. */
    private const string PUBLIC_UNNAMED_URI = '#^(up|livewire-[a-f0-9]+/(livewire(\.csp)?(\.min)?\.js(\.map)?|js/\{component\}\.js|css/\{component\}(\.global)?\.css))$#';

    /** Middleware that require a signed-in staff (auth) or client (client.session) session. */
    private const string AUTHENTICATED = '#^(auth(:.*)?|client\.session|Illuminate\\\\Auth\\\\Middleware\\\\Authenticate(:.*)?|App\\\\Http\\\\Middleware\\\\EnsureClientSession)$#';

    /**
     * Views allowed to print unescaped output, with how many times: each place escapes what it inserts itself (the
     * plain-text part of an e-mail is never read as HTML).
     *
     * @var array<string, int>
     */
    private const array RAW_OUTPUT = [
        'livewire/billing/dashboard.blade.php' => 1,
        'livewire/billing/my-entries.blade.php' => 3,
        'components/navigation/icon.blade.php' => 1,
        'mail/administrator-alert-text.blade.php' => 4,
        'mail/password-reset-text.blade.php' => 6,
    ];

    /** Folders that must stay free of any client or vendor identity. */
    private const array WHITE_LABEL_FOLDERS = ['app', 'resources', 'lang', 'config', 'database', 'routes', 'public'];

    private const array TEXT_EXTENSIONS = ['php', 'js', 'mjs', 'ts', 'css', 'json', 'svg', 'webmanifest', 'txt', 'md', 'html', 'xml', 'csv', 'neon', 'yml', 'yaml'];

    public function test_every_route_needs_a_session_unless_it_is_on_the_public_list(): void
    {
        $unprotected = collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $route) => collect($route->gatherMiddleware())->contains(fn (mixed $middleware) => is_string($middleware) && preg_match(self::AUTHENTICATED, $middleware) === 1))
            ->reject(fn (Route $route) => array_key_exists((string) $route->getName(), self::PUBLIC_ROUTES))
            ->reject(fn (Route $route) => $route->getName() === null && preg_match(self::PUBLIC_UNNAMED_URI, $route->uri()) === 1)
            ->reject(fn (Route $route) => str_starts_with($route->uri(), '_boost/'))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri().' ('.($route->getName() ?? 'unnamed').')')
            ->values()
            ->all();

        $this->assertSame([], $unprotected, 'Routes without auth outside the public list');
    }

    public function test_the_public_list_has_no_stale_entry(): void
    {
        foreach (array_keys(self::PUBLIC_ROUTES) as $name) {
            $this->assertTrue(Router::has($name), "Public route « {$name} » no longer exists: remove it from the list.");
        }
    }

    public function test_blade_views_print_unescaped_output_only_in_the_reviewed_places(): void
    {
        $found = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $count = substr_count($file->getContents(), '{!!');

            if ($count > 0) {
                $found[str_replace('\\', '/', $file->getRelativePathname())] = $count;
            }
        }

        ksort($found);
        $allowed = self::RAW_OUTPUT;
        ksort($allowed);

        $this->assertSame($allowed, $found, 'Unescaped {!! !!} output outside the reviewed places');
    }

    public function test_no_client_or_vendor_identity_is_written_in_the_code(): void
    {
        $hits = [];

        foreach (self::WHITE_LABEL_FOLDERS as $folder) {
            /** @var SplFileInfo $file */
            foreach (File::allFiles(base_path($folder)) as $file) {
                $path = str_replace('\\', '/', $file->getRelativePathname());

                if (($folder === 'public' && (str_starts_with($path, 'build/') || $path === 'hot')) || ! in_array(strtolower($file->getExtension()), self::TEXT_EXTENSIONS, true)) {
                    continue;
                }

                if (preg_match(self::identityPattern(), $file->getContents(), $match) === 1) {
                    $hits[] = "{$folder}/{$path}: « {$match[0]} »";
                }
            }
        }

        $this->assertSame([], $hits, 'Client or vendor identity written in the code');
    }

    /**
     * Names of the first customer and its area, kept ROT13-encoded so they never appear in the repository either.
     */
    private static function identityPattern(): string
    {
        $words = array_map(str_rot13(...), ['gbznceb', 'tnvyynp']);
        $phrases = array_map(str_rot13(...), ['RBF', 'QFC[ -]?GNEA']);

        return '/'.implode('|', [...$words, ...array_map(fn (string $phrase) => '\\b'.$phrase.'\\b', $phrases)]).'/i';
    }
}
