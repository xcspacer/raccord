<?php

namespace App\Policies;

use App\Billing\BillingAccess;
use App\Models\User;

/**
 * Technicians' billing defaults (default client): set up by administrators who use « Pointage / Facturation ».
 */
class BillingProfilePolicy
{
    public function __construct(private BillingAccess $access) {}

    public function manage(User $user): bool
    {
        return $this->access->canUse($user) && $this->access->isAdministrator($user);
    }
}
