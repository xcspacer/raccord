<?php

namespace App\Enums;

/**
 * Internal message on a box: chat, early observation of the design office on a photo, or request for an additional control. Never shown to the client.
 */
enum AuditBoxMessageKind: string
{
    case Chat = 'chat';
    case Observation = 'observation';
    case ControlRequest = 'control_request';

    public function label(): string
    {
        return __('enums.audit_box_message_kinds.'.$this->value);
    }
}
