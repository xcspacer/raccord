<?php

namespace App\Logs;

use App\Jobs\AlertAdministrators;
use Illuminate\Support\Facades\Cache;

/**
 * E-mails the administrators about the security events that call for their attention (LOG-06, PORT-13): a new
 * client portal account, a client account given every audit, a new administrator and sign-in lockouts. The e-mails
 * leave after the person has been answered; lockouts are announced at most once per LOCKOUT_ALERT_SECONDS for the
 * staff sign-in and once for the client portal, whatever the number of blocked attempts. The details stay in the
 * security log.
 */
class AdministratorAlerts
{
    public const int LOCKOUT_ALERT_SECONDS = 3600;

    /**
     * @param  array<string, string>  $replace  Values of the placeholders of the e-mail text.
     */
    public function send(string $event, array $replace = []): void
    {
        AlertAdministrators::dispatchAfterResponse($event, $replace);
    }

    /**
     * @param  array<string, string>  $replace
     */
    public function lockout(string $event, array $replace = []): void
    {
        if (Cache::add('administrator-alert:'.$event, true, self::LOCKOUT_ALERT_SECONDS)) {
            $this->send($event, $replace);
        }
    }
}
