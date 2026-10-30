<?php

use App\DesignOfficeWorkflow\WorkflowException;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnforceVehicleCycle;
use App\Http\Middleware\EnsureClientSession;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Http\Middleware\EnsureFeatureIsAllowed;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsurePlatformIsActive;
use App\Http\Middleware\EnsureUploadQuota;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrustProxies;
use App\Logs\AdministratorAlerts;
use App\Logs\SecurityLog;
use App\Support\UserSessions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies and host names of this installation (TRUSTED_PROXIES, TRUSTED_HOSTS), read from the configuration on
        // each request so a cached configuration keeps working.
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);
        $middleware->trustHosts(at: fn (): array => array_map(
            fn (string $host): string => '^'.preg_quote($host).'$',
            (array) config('platform.trusted_hosts'),
        ));

        // The language also applies where no route (and so no session) runs, e.g. the « not found » page of an
        // unknown URL; its cookie holds only the language code, so it is not encrypted.
        $middleware->append(SetLocale::class);
        $middleware->encryptCookies(except: [SetLocale::COOKIE]);

        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            EnsurePlatformIsActive::class,
            EnsureUserIsActive::class,
            EnforceSessionLifetime::class,
        ]);

        // Language and platform switch must run before authentication, so a suspended platform never
        // redirects to the login screen.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SetLocale::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsurePlatformIsActive::class);

        // Departed users and expired sessions are signed out before authentication decides.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceSessionLifetime::class);

        $middleware->alias([
            'client.session' => EnsureClientSession::class,
            'feature' => EnsureFeatureIsAllowed::class,
            'vehicle.cycle' => EnforceVehicleCycle::class,
            'module' => EnsureModuleIsEnabled::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'sync.protocol' => EnsureCurrentSyncProtocol::class,
            'upload.quota' => EnsureUploadQuota::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Too many sign-in attempts: show the message on the login form (as the legacy app did), not an error page.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->routeIs('login.store') && ! $request->expectsJson()) {
                $username = mb_substr(mb_strtolower(trim((string) $request->input('username'))), 0, 60);
                app(SecurityLog::class)->warning(SecurityLog::LOGIN_THROTTLED, ['username' => $username]);
                app(AdministratorAlerts::class)->lockout(SecurityLog::LOGIN_THROTTLED, ['username' => $username]);

                return back()->withInput($request->only('username'))->withErrors([
                    'username' => __('auth.throttle', ['seconds' => $exception->getHeaders()['Retry-After'] ?? 60]),
                ]);
            }

            if ($request->routeIs('password.email') && ! $request->expectsJson()) {
                return back()->withInput($request->only('email'))->withErrors([
                    'email' => __('auth.throttle', ['seconds' => $exception->getHeaders()['Retry-After'] ?? 60]),
                ]);
            }
        });

        // JSON requests without a valid staff session get 401 with the reason (session_revoked / user_disabled).
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return app(UserSessions::class)->unauthenticatedResponse($request);
            }
        });

        // A device whose session was ended remotely is told so (401), not that its page expired (419).
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && app(UserSessions::class)->endReason($request) !== null) {
                return app(UserSessions::class)->unauthenticatedResponse($request);
            }
        });

        // A refused action of the design office workflow answers its status with a stable reason and a translated message.
        $exceptions->render(function (WorkflowException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($exception->toArray(), $exception->status);
            }

            abort($exception->status, $exception->translated());
        });

        // JSON error answers carry a message in the user's language (the framework's own ones are English).
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $key = Lang::has('errors.'.$exception->getStatusCode().'.message') ? (string) $exception->getStatusCode() : 'generic';

                return response()->json(['message' => __('errors.'.$key.'.message')], $exception->getStatusCode(), $exception->getHeaders());
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
