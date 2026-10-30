<?php

namespace App\Logs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The security log: its own daily file (channel « security », kept 12 months) with sign-in failures and lockouts,
 * client portal accounts, new users and role or agency changes, revoked sessions. Each line says who acted, from
 * which address and browser; never a password, a token or a position. Events that call for an administrator's
 * attention are written as warnings.
 */
class SecurityLog
{
    public const string CHANNEL = 'security';

    public const string LOGIN_FAILED = 'login_failed';

    public const string LOGIN_THROTTLED = 'login_throttled';

    public const string PORTAL_LOGIN_FAILED = 'portal_login_failed';

    public const string PORTAL_LOGIN_THROTTLED = 'portal_login_throttled';

    public const string CLIENT_ACCOUNT_CREATED = 'client_account_created';

    public const string CLIENT_ACCOUNT_ALL_AUDITS = 'client_account_all_audits';

    public const string CLIENT_ACCOUNT_AUDITS_CHANGED = 'client_account_audits_changed';

    public const string CLIENT_ACCOUNT_REACTIVATED = 'client_account_reactivated';

    public const string CLIENT_ACCOUNT_DEACTIVATED = 'client_account_deactivated';

    public const string CLIENT_ACCOUNT_PASSWORD_RESET = 'client_account_password_reset';

    public const string CLIENT_ACCOUNT_DELETED = 'client_account_deleted';

    public const string USER_CREATED = 'user_created';

    public const string ROLE_CHANGED = 'role_changed';

    public const string AGENCY_CHANGED = 'agency_changed';

    public const string SESSIONS_REVOKED = 'sessions_revoked';

    public const string PERSONAL_DATA_EXPORTED = 'personal_data_exported';

    public const string USER_ANONYMISED = 'user_anonymised';

    /**
     * @param  array<string, scalar|list<scalar>|null>  $context
     */
    public function info(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($event, $this->context($context));
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($event, $this->context($context));
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $context
     * @return array<string, scalar|list<scalar>|null>
     */
    private function context(array $context): array
    {
        $request = request();

        return $context + [
            'by_user_id' => Auth::guard('web')->id(),
            'by_client_account_id' => Auth::guard('client')->id(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ];
    }
}
