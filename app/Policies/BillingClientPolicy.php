<?php

namespace App\Policies;

use App\Billing\BillingAccess;
use App\Models\User;

/**
 * Billing clients, their intervention types and articles: set up by administrators who use « Pointage / Facturation ».
 */
class BillingClientPolicy
{
    public function __construct(private BillingAccess $access) {}

    public function manage(User $user): bool
    {
        return $this->access->canUse($user) && $this->access->isAdministrator($user);
    }
}
