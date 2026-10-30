<?php

namespace App\Http\Controllers\ClientPortal;

use App\ClientPortal\PortalSession;
use App\Http\Controllers\Controller;
use App\Logs\AdministratorAlerts;
use App\Logs\SecurityLog;
use App\Models\Client;
use App\Models\ClientAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Sign-in and sign-out of the client portal of one client company.
 */
class PortalSessionController extends Controller
{
    public const int MAX_FAILURES = 8;

    public const int FAILURE_WINDOW_SECONDS = 900;

    public function create(Client $portalClient, PortalSession $portal): View|RedirectResponse
    {
        if ($portal->account($portalClient->id) !== null) {
            return redirect()->route('portal.home', $portalClient->portalSlug());
        }

        return view('portal.login', ['client' => $portalClient]);
    }

    public function store(Request $request, Client $portalClient, PortalSession $portal, SecurityLog $security, AdministratorAlerts $alerts): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $throttleKey = 'portal-login|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILURES)) {
            $security->warning(SecurityLog::PORTAL_LOGIN_THROTTLED, ['client_id' => $portalClient->id, 'username' => Str::limit(Str::lower(trim($credentials['username'])), 60, '')]);
            $alerts->lockout(SecurityLog::PORTAL_LOGIN_THROTTLED, ['company' => $portalClient->name, 'username' => Str::limit(Str::lower(trim($credentials['username'])), 60, '')]);

            return $this->failed('throttled');
        }

        $account = ClientAccount::query()
            ->where('client_id', $portalClient->id)
            ->where('username', Str::lower(trim($credentials['username'])))
            ->where('is_active', true)
            ->first();

        if ($account === null || ! Hash::check($credentials['password'], $account->password)) {
            RateLimiter::hit($throttleKey, self::FAILURE_WINDOW_SECONDS);
            $security->info(SecurityLog::PORTAL_LOGIN_FAILED, ['client_id' => $portalClient->id, 'username' => Str::limit(Str::lower(trim($credentials['username'])), 60, '')]);
            Sleep::for(400)->milliseconds();

            return $this->failed('failed');
        }

        $portal->start($account);
        $account->forceFill(['last_login_at' => now()])->save();

        return redirect()->route($account->must_change_password ? 'portal.password' : 'portal.home', $portalClient->portalSlug());
    }

    public function destroy(Client $portalClient, PortalSession $portal): RedirectResponse
    {
        $portal->end();

        return redirect()->route('portal.login', $portalClient->portalSlug());
    }

    private function failed(string $reason): RedirectResponse
    {
        return back()->withInput(['username' => request()->input('username')])->withErrors(['username' => __('client_portal.login.'.$reason)]);
    }
}
