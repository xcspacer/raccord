<?php

namespace Tests\Feature\Platform;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Livewire end points are public: anyone signed in can call any public method of a component they have a snapshot
 * of. So every public method that changes data checks authorization on the server (AUTZ-01, LW), and helpers that
 * are not actions are not public. The checks read the source: an action is « authorized » when it, a helper of the
 * component it calls, or a service method it calls holds an authorization call.
 */
class AuthorizationArchitectureTest extends TestCase
{
    /**
     * Calls that check authorization: policies and gates; the ownership scope of the component's signed-in person
     * (records looked up through a relation `$this->user()->relation()->…` or `where('user_id', $this->user()->id)` can only be the person's
     * own); and, on the client portal, the account resolved from the portal session (`$this->account($portal)`),
     * whose audits are always reached through `visibleAudits()`.
     */
    private const string AUTHORIZATION = '/\$this->authorize\(|Gate::|->authorize\(|->can\(|->cannot\(|\$this->user\(\)->\w+\(\)->|where\(\'user_id\', \$(this->user\(\)|user)->id\)|\$this->account\(\$portal\)/';

    /** Calls that write: Eloquent writes, relation writes, and action classes (`handle`). */
    private const string WRITES = '/->(save|update|delete|forceDelete|restore|create|createMany|insert|upsert|updateOrCreate|firstOrCreate|increment|decrement|attach|detach|sync|syncWithoutDetaching|forceFill|handle|release)\(|::(create|insert|upsert|destroy)\(/';

    /** Livewire lifecycle hooks: not callable from the browser. */
    private const string LIFECYCLE = '/^(mount|boot|booted|hydrate|dehydrate|render|rendering|rendered|exception|placeholder)([A-Z]\w*)?$/';

    /** Property hooks: run when the browser sets a property, so they are actions when they write. */
    private const string PROPERTY_HOOKS = '/^(updating|updated)([A-Z]\w*)?$/';

    /**
     * Public methods that write without an authorization call, and why that is safe.
     *
     * @var array<string, string>
     */
    private const array UNGUARDED_WRITES = [
        'App\Livewire\Inventory\BulkAdd::close' => 'only releases the photos this dialog stored itself (locked ids), after the manager check of « analyse »',
    ];

    /**
     * Public methods not called by any view or script, and why they stay public.
     *
     * @var array<string, string>
     */
    private const array PUBLIC_HELPERS = [];

    /**
     * Controller actions that check no policy or gate themselves, and why that is safe.
     *
     * @var array<string, string>
     */
    private const array UNGUARDED_CONTROLLER_ACTIONS = [
        'App\Http\Controllers\LocaleController::__invoke' => 'public: switches the language of the visitor (session or own profile)',
        'App\Http\Controllers\BrandingLogoController::__invoke' => 'public: the logo of the platform settings, shown on the sign-in page',
        'App\Http\Controllers\WebManifestController::__invoke' => 'public: the web app manifest',
        'App\Http\Controllers\FibreCode\FibreCodeAppController::manifest' => 'public: the manifest of the installable « Code Fibre » app',
        'App\Http\Controllers\ChangePasswordController::__invoke' => 'session: the signed-in person changes their own password',
        'App\Http\Controllers\ClientPortal\PortalSessionController::create' => 'client portal sign-in page',
        'App\Http\Controllers\ClientPortal\PortalSessionController::store' => 'client portal sign-in',
        'App\Http\Controllers\ClientPortal\PortalSessionController::destroy' => 'client portal sign-out of the current session',
        'App\Http\Controllers\ClientPortal\PortalLogoController::__invoke' => 'public: the logo of the client, shown on its portal sign-in page',
        'App\Http\Controllers\ClientPortal\PortalPasswordController::edit' => 'client portal: the account comes from the portal session (401 without one)',
        'App\Http\Controllers\ClientPortal\PortalPasswordController::update' => 'client portal: the account comes from the portal session, current password required',
        'App\Http\Controllers\ClientPortal\PortalFileController::photo' => 'client portal: visible() refuses (404) audits outside the session account\'s clients',
        'App\Http\Controllers\ClientPortal\PortalFileController::pdf' => 'client portal: visible() refuses (404) audits outside the session account\'s clients',
        'App\Http\Controllers\ClientPortal\PortalFileController::zip' => 'client portal: visible() refuses (404) audits outside the session account\'s clients',
        'App\Http\Controllers\ClientPortal\PortalFileController::dossier' => 'client portal: visible() refuses (404) audits outside the session account\'s clients; only published dossier versions',
        'App\Http\Controllers\EquipmentReportController::__invoke' => 'route `feature:technician_space` gate; only the equipment the signed-in person holds',
        'App\Http\Controllers\FibreCode\FibreCodeAppController::show' => 'route `feature:fibre_code` gate; a static page with no data',
        'App\Http\Controllers\MediaLibrary\AuditDeliverablesController::file' => 'route `feature:media_library` and `feature:audits` gates, the same checks as its page',
        'App\Http\Controllers\MediaLibrary\AuditDeliverablesController::zip' => 'route `feature:media_library` and `feature:audits` gates, the same checks as its page',
        'App\Http\Controllers\ReverseGeocodeController::__invoke' => 'signed-in only: names the place of coordinates the person sends; nothing stored nor read',
    ];

    /**
     * Every controller action behind a route checks a policy or a gate (in the action, a helper it calls, a route
     * `can:` middleware, the portal session of the client portal), or is on the commented allowlist (AUTZ-01).
     */
    public function test_every_controller_action_checks_authorization(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_starts_with($action, 'App\\Http\\Controllers\\')) {
                continue;
            }

            [$class, $name] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
            $key = $class.'::'.$name;

            if (isset(self::UNGUARDED_CONTROLLER_ACTIONS[$key]) || $this->guardedByRoute($route)) {
                continue;
            }

            $controller = new ReflectionClass($class);

            if (! $this->authorizes($controller, $controller->getMethod($name))) {
                $unguarded[] = $key;
            }
        }

        $this->assertSame([], array_values(array_unique($unguarded)), 'Controller actions without a policy or gate check');
    }

    public function test_the_controller_allowlist_has_no_stale_entry(): void
    {
        $routed = collect(Route::getRoutes()->getRoutes())->map(fn (RoutingRoute $route) => str_replace('@', '::', $route->getActionName()))
            ->map(fn (string $action) => str_contains($action, '::') ? $action : $action.'::__invoke')->all();

        foreach (array_keys(self::UNGUARDED_CONTROLLER_ACTIONS) as $key) {
            $this->assertContains($key, $routed, "Stale controller allowlist entry {$key}");
        }
    }

    public function test_every_livewire_action_that_writes_checks_authorization(): void
    {
        $unguarded = [];

        foreach ($this->components() as $component) {
            foreach ($this->actions($component) as $method) {
                if (isset(self::UNGUARDED_WRITES[$this->fullKey($method)]) || ! $this->writes($component, $method)) {
                    continue;
                }

                if (! $this->authorizes($component, $method)) {
                    $unguarded[] = $this->fullKey($method);
                }
            }
        }

        $this->assertSame([], $unguarded, 'Livewire actions that write without checking authorization');
    }

    public function test_the_allowlists_have_no_stale_entry(): void
    {
        foreach ([...array_keys(self::UNGUARDED_WRITES), ...array_keys(self::PUBLIC_HELPERS)] as $key) {
            [$class, $name] = explode('::', $key);
            $this->assertTrue(method_exists($class, $name) && (new ReflectionMethod($class, $name))->isPublic(), "Stale allowlist entry {$key}");
        }

        foreach (array_keys(self::UNGUARDED_WRITES) as $key) {
            [$class, $name] = explode('::', $key);
            $component = new ReflectionClass($class);
            $method = new ReflectionMethod($class, $name);
            $this->assertTrue($this->writes($component, $method) && ! $this->authorizes($component, $method), "Allowlist entry {$key} is no longer needed");
        }
    }

    public function test_livewire_helpers_that_are_not_actions_are_not_public(): void
    {
        $sources = $this->frontEndSources();
        $unused = [];

        foreach ($this->components() as $component) {
            foreach ($this->actions($component) as $method) {
                $name = $method->getName();

                if (preg_match(self::PROPERTY_HOOKS, $name) === 1 || $method->getAttributes(On::class) !== [] || isset(self::PUBLIC_HELPERS[$this->fullKey($method)])) {
                    continue;
                }

                if (! $this->replayedAfterPasswordConfirmation($method) && preg_match('/(?<![\w$])'.preg_quote($name, '/').'(?!\w)/', $sources) !== 1) {
                    $unused[] = $this->fullKey($method);
                }
            }
        }

        $this->assertSame([], $unused, 'Public Livewire methods no view or script calls: make them protected or private');
    }

    /**
     * Authorized before the controller runs: a `can:` middleware, or the client portal session (the portal resolves
     * the client account and only reaches that account's audits).
     */
    private function guardedByRoute(RoutingRoute $route): bool
    {
        return collect($route->gatherMiddleware())->contains(fn (mixed $middleware) => is_string($middleware) && str_starts_with($middleware, 'can:'));
    }

    /**
     * @return list<ReflectionClass<Component>>
     */
    private function components(): array
    {
        $components = [];

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $components[] = new ReflectionClass($class);
            }
        }

        return $components;
    }

    /**
     * Public methods written in the application (the component or its traits), lifecycle hooks excluded.
     *
     * @param  ReflectionClass<Component>  $component
     * @return list<ReflectionMethod>
     */
    private function actions(ReflectionClass $component): array
    {
        return array_values(array_filter(
            $component->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method) => ! $method->isStatic()
                && str_starts_with((string) $method->getFileName(), app_path())
                && preg_match(self::LIFECYCLE, $method->getName()) !== 1,
        ));
    }

    /**
     * @param  ReflectionClass<Component>  $component
     */
    private function writes(ReflectionClass $component, ReflectionMethod $method): bool
    {
        return preg_match(self::WRITES, $this->reachableSource($component, $method)) === 1;
    }

    /**
     * Authorized in the method, a component helper it calls, or a method of an application service it receives.
     *
     * @param  ReflectionClass<Component>  $component
     */
    private function authorizes(ReflectionClass $component, ReflectionMethod $method): bool
    {
        if (preg_match(self::AUTHORIZATION, $this->reachableSource($component, $method)) === 1) {
            return true;
        }

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || ! str_starts_with($type->getName(), 'App\\') || ! class_exists($type->getName())) {
                continue;
            }

            $service = new ReflectionClass($type->getName());
            preg_match_all('/\$'.$parameter->getName().'->(\w+)\(/', $this->source($method), $calls);

            foreach (array_unique($calls[1]) as $called) {
                if ($service->hasMethod($called) && preg_match(self::AUTHORIZATION, $this->reachableSource($service, $service->getMethod($called))) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The method's source and that of every method of the same class it calls through `$this`, recursively.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, true>  $seen
     */
    private function reachableSource(ReflectionClass $class, ReflectionMethod $method, array &$seen = []): string
    {
        $seen[$method->getName()] = true;
        $source = $this->source($method);
        preg_match_all('/\$this->(\w+)\(/', $source, $calls);

        foreach (array_unique($calls[1]) as $called) {
            if (! isset($seen[$called]) && $class->hasMethod($called) && str_starts_with((string) $class->getMethod($called)->getFileName(), app_path())) {
                $source .= "\n".$this->reachableSource($class, $class->getMethod($called), $seen);
            }
        }

        return $source;
    }

    private function source(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName()) ?: [];

        return implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    }

    /**
     * Actions behind the password confirmation (AUT-07) are replayed by it once the password is confirmed.
     */
    private function replayedAfterPasswordConfirmation(ReflectionMethod $method): bool
    {
        return str_contains($this->source($method), 'passwordConfirmationNeeded(__FUNCTION__');
    }

    private function fullKey(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getName().'::'.$method->getName();
    }

    /**
     * Blade views and scripts, plus the method names components hand to the browser in PHP (confirmation dialogs).
     */
    private function frontEndSources(): string
    {
        $sources = '';

        foreach ([resource_path('views'), resource_path('js')] as $folder) {
            foreach (File::allFiles($folder) as $file) {
                $sources .= $file->getContents()."\n";
            }
        }

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            preg_match_all('/method: \'(\w+)\'|passwordConfirmationNeeded\(\'(\w+)\'/', $file->getContents(), $matches);
            $sources .= implode("\n", array_filter([...$matches[1], ...$matches[2]]))."\n";
        }

        return $sources;
    }
}
