<?php

namespace App\Enums;

/**
 * Why a signed-in device lost its session, sent with the 401 answer of JSON requests so the phone
 * can tell a remote sign-out apart from an ordinary expiry.
 */
enum SessionEndReason: string
{
    case SessionRevoked = 'session_revoked';
    case UserDisabled = 'user_disabled';

    public function label(): string
    {
        return __('sessions.end_reasons.'.$this->value);
    }
}
