<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\SignInWithRolePolicy;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::authenticateUsing(new AuthenticateUser);
        Fortify::authenticateThrough(fn (): array => [
            CanonicalizeUsername::class,
            SignInWithRolePolicy::class,
            PrepareAuthenticatedSession::class,
        ]);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', [
            'token' => (string) $request->route('token'),
            'email' => (string) $request->query('email'),
        ]));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perHour(5)->by('email|'.Str::lower(trim((string) $request->input(Fortify::email())))),
            Limit::perMinute(5)->by('ip|'.$request->ip()),
        ]);

        $this->throttlePasswordResetRequests();
    }

    /**
     * Fortify registers its « forgot password » route without a rate limiter: requests are limited per e-mail and
     * per address. A cached route list already carries the middleware.
     */
    private function throttlePasswordResetRequests(): void
    {
        $this->app->booted(function (): void {
            $routes = Route::getRoutes();

            if (! $routes instanceof RouteCollection) {
                return;
            }

            $routes->refreshNameLookups();
            $routes->getByName('password.email')?->middleware('throttle:password-reset');
        });
    }
}
