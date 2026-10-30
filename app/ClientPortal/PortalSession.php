<?php

namespace App\ClientPortal;

use App\Models\ClientAccount;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * The client portal session: signed in through the "client" guard for a fixed 12 hours, tied to the
 * client company of the portal address and to the account's password at sign-in time, so a new
 * password, a deactivated or deleted account ends every open session.
 */
class PortalSession
{
    public const int LIFETIME_HOURS = 12;

    private const string KEY = 'client_portal';

    public function __construct(private Session $session) {}

    public function start(ClientAccount $account): void
    {
        Auth::guard('client')->login($account);
        $this->session->regenerate();
        $this->session->put(self::KEY, [
            'client_id' => $account->client_id,
            'password' => $this->fingerprint($account),
            'expires_at' => now()->addHours(self::LIFETIME_HOURS)->getTimestamp(),
        ]);
    }

    /**
     * The signed-in account when its session is still valid for that client company, else null.
     */
    public function account(int $clientId): ?ClientAccount
    {
        $account = Auth::guard('client')->user();
        $state = $this->session->get(self::KEY);

        if (! $account instanceof ClientAccount || ! is_array($state)) {
            return null;
        }

        $valid = $account->is_active
            && $account->client_id === $clientId
            && ($state['client_id'] ?? null) === $clientId
            && hash_equals((string) ($state['password'] ?? ''), $this->fingerprint($account))
            && (int) ($state['expires_at'] ?? 0) > now()->getTimestamp();

        return $valid ? $account : null;
    }

    public function hasStarted(): bool
    {
        return Auth::guard('client')->check() || $this->session->has(self::KEY);
    }

    /**
     * Ends the client session only: a staff session open in the same browser stays signed in.
     */
    public function end(): void
    {
        Auth::guard('client')->logout();
        $this->session->forget(self::KEY);
        $this->session->regenerateToken();
    }

    private function fingerprint(ClientAccount $account): string
    {
        return hash('sha256', $account->password);
    }
}
