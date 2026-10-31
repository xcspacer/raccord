<?php

namespace App\Providers;

use App\Enums\Feature;
use App\Enums\Role;
use App\Http\Middleware\EnsureFeatureIsAllowed;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Logs\ActivityBatch;
use App\Logs\SecurityLog;
use App\Models\Client;
use App\Models\User;
use App\Support\EffectiveAgency;
use App\Support\FeatureAccess;
use App\Support\ProductionDebugGuard;
use App\Support\PublicHolidays;
use App\View\Composers\AppLayoutComposer;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public const int LIVEWIRE_UPDATES_PER_MINUTE = 300;

    public const int LIVEWIRE_UPLOADS_PER_MINUTE = 60;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(FeatureAccess::class);
        $this->app->scoped(EffectiveAgency::class);
        $this->app->scoped(PublicHolidays::class);
        $this->app->scoped(ActivityBatch::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(ProductionDebugGuard::class)->enforce();

        $this->configureModels();
        $this->configureLivewireRequests();
        $this->recordSecurityEvents();

        Gate::define('manage-platform', fn (User $user): bool => $user->hasRole(Role::Administrator->value));
        Gate::define('use-feature', fn (User $user, Feature $feature): bool => app(FeatureAccess::class)->allows($user, $feature));
        Gate::define('open-feature', fn (User $user, Feature $feature): bool => app(FeatureAccess::class)->opens($user, $feature));
        Gate::define('switch-agency', fn (User $user): bool => app(EffectiveAgency::class)->canSwitch($user));
        Gate::define('export-planning-timesheet', fn (User $user): bool => $user->isManager() && app(FeatureAccess::class)->allows($user, Feature::WeeklyTimesheet));

        // Bound here, not in routes/portal.php: route files are not loaded when the routes are cached (production).
        Route::bind('portalClient', fn (string $slug) => Client::query()->where('code', $slug)->where('is_active', true)->firstOrFail());

        Activity::creating(function (Activity $activity): void {
            $activity->properties = collect($activity->properties)->put('batch', app(ActivityBatch::class)->id());
        });

        View::composer('components.layouts.app', AppLayoutComposer::class);

        // The stylesheet is loaded right away by its own tag; a preload of it only makes browsers warn when pages are swapped by Livewire.
        Vite::usePreloadTagAttributes(fn (string $src, string $url): array|false => str_ends_with($url, '.css') ? false : []);
    }

    /**
     * Staff sign-in failures and sessions signed out on other devices go to the security log (never the password).
     */
    private function recordSecurityEvents(): void
    {
        Event::listen(Failed::class, function (Failed $event): void {
            app(SecurityLog::class)->info(SecurityLog::LOGIN_FAILED, [
                'guard' => $event->guard,
                'username' => mb_substr(mb_strtolower(trim((string) ($event->credentials['username'] ?? ''))), 0, 60),
            ]);
        });

        Event::listen(OtherDeviceLogout::class, function (OtherDeviceLogout $event): void {
            app(SecurityLog::class)->info(SecurityLog::SESSIONS_REVOKED, ['guard' => $event->guard, 'user_id' => $event->user->getAuthIdentifier()]);
        });
    }

    /**
     * Livewire requests (screen updates and temporary uploads) are limited per person, or per address when nobody is
     * signed in, and screen updates go through the module and feature checks of their page again.
     */
    private function configureLivewireRequests(): void
    {
        $requester = fn (Request $request): string => match (true) {
            Auth::guard('web')->id() !== null => 'user:'.Auth::guard('web')->id(),
            Auth::guard('client')->id() !== null => 'client:'.Auth::guard('client')->id(),
            default => 'ip:'.$request->ip(),
        };

        RateLimiter::for('livewire-updates', fn (Request $request) => Limit::perMinute(self::LIVEWIRE_UPDATES_PER_MINUTE)->by($requester($request)));
        RateLimiter::for('livewire-uploads', fn (Request $request) => Limit::perMinute(self::LIVEWIRE_UPLOADS_PER_MINUTE)->by($requester($request)));

        Livewire::setUpdateRoute(fn (array $handle, string $path) => Route::post($path, $handle)->middleware(['web', 'throttle:livewire-updates']));

        // Every screen update re-applies the module and feature checks of the page it was opened on, so switching a
        // module or an access off takes effect at once, also in pages already open.
        Livewire::addPersistentMiddleware([EnsureModuleIsEnabled::class, EnsureFeatureIsAllowed::class]);
    }

    /**
     * Outside production, lazy loading, silently dropped attributes and reading columns that were not selected all
     * throw.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
