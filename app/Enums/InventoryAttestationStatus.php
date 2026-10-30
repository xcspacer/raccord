<?php

namespace App\Enums;

/**
 * State of a handover certificate: signed when both signatures were drawn (checked on the server), not signed
 * otherwise; certificates brought over from a previous platform keep their original PDF but their signatures were
 * never verified, so they never count as signed.
 */
enum InventoryAttestationStatus: string
{
    case Signed = 'signed';
    case Unsigned = 'unsigned';
    case Unverified = 'unverified';

    public function label(): string
    {
        return __('enums.inventory_attestation_statuses.'.$this->value);
    }
}
