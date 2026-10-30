<?php

namespace App\Policies;

use App\Billing\BillingAccess;
use App\Models\User;

/**
 * Billing rates (tariffs) and operators: set up by administrators who use « Pointage / Facturation ».
 */
class BillingRatePolicy
{
    public function __construct(private BillingAccess $access) {}

    public function manage(User $user): bool
    {
        return $this->access->canUse($user) && $this->access->isAdministrator($user);
    }
}
