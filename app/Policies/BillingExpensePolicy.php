<?php

namespace App\Policies;

use App\Billing\BillingAccess;
use App\Models\BillingExpense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Billing expenses: each person declares their own and changes them while they wait for review; supervisors and
 * administrators see everyone's; only administrators approve or reject. Someone else's expense does not exist for
 * a technician, nor in anyone's own expense form (404); a reviewed expense can no longer be changed (403).
 */
class BillingExpensePolicy
{
    public function __construct(private BillingAccess $access) {}

    public function create(User $user): bool
    {
        return $this->access->canUse($user);
    }

    /**
     * The review screen and the month's export: everyone's expenses.
     */
    public function viewAny(User $user): bool
    {
        return $this->access->canUse($user) && ($this->access->isAdministrator($user) || $this->access->isSupervisor($user));
    }

    public function view(User $user, BillingExpense $expense): Response
    {
        if (! $this->access->canUse($user)) {
            return Response::deny();
        }

        return $expense->user_id === $user->id || $this->viewAny($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * The person's own expense, while it has not been reviewed.
     */
    public function update(User $user, BillingExpense $expense): Response
    {
        if (! $this->access->canUse($user)) {
            return Response::deny();
        }

        if ($expense->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $expense->isPending() ? Response::allow() : Response::deny();
    }

    public function delete(User $user, BillingExpense $expense): Response
    {
        return $this->update($user, $expense);
    }

    /**
     * Approve or reject (only once: a reviewed expense answers 409 in the review action).
     */
    public function review(User $user, ?BillingExpense $expense = null): bool
    {
        return $this->access->canUse($user) && $this->access->isAdministrator($user);
    }
}
