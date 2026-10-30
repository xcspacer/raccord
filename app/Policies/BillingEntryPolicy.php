<?php

namespace App\Policies;

use App\Billing\BillingAccess;
use App\Models\BillingEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * « Pointage / Facturation » entries: each person declares their own; supervisors and administrators follow the
 * team; administrators also change anyone's entry and export the month. An entry the person neither declared,
 * was named on as a partner, nor follows as a manager does not exist for them (404); without the billing access
 * every action answers 403.
 */
class BillingEntryPolicy
{
    public function __construct(private BillingAccess $access) {}

    /**
     * Declare one's own entries.
     */
    public function create(User $user): bool
    {
        return $this->access->canUse($user);
    }

    /**
     * The team dashboard: every entry of the month.
     */
    public function viewAll(User $user): bool
    {
        return $this->access->canUse($user) && $this->isManager($user);
    }

    public function view(User $user, BillingEntry $entry): Response
    {
        if (! $this->access->canUse($user)) {
            return Response::deny();
        }

        return $this->isManager($user) || $this->isOwnerOrPartner($user, $entry) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * The technician changes their own entries; administrators change anyone's from the dashboard. The entry form
     * only knows the person's own entries, so any other entry is not found there (404), partners' included.
     */
    public function update(User $user, BillingEntry $entry): Response
    {
        if (! $this->access->canUse($user)) {
            return Response::deny();
        }

        return $entry->user_id === $user->id || $this->updateAny($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Administrators change anyone's entry from the dashboard.
     */
    public function updateAny(User $user): bool
    {
        return $this->access->canUse($user) && $this->access->isAdministrator($user);
    }

    public function delete(User $user, BillingEntry $entry): Response
    {
        return $this->update($user, $entry);
    }

    /**
     * Supervisors and administrators correct the ticket number of any entry.
     */
    public function correctTicket(User $user, BillingEntry $entry): bool
    {
        return $this->viewAll($user);
    }

    /**
     * The month's workbooks (global, one per technician).
     */
    public function export(User $user): bool
    {
        return $this->updateAny($user);
    }

    private function isManager(User $user): bool
    {
        return $this->access->isAdministrator($user) || $this->access->isSupervisor($user);
    }

    private function isOwnerOrPartner(User $user, BillingEntry $entry): bool
    {
        return $entry->user_id === $user->id || $entry->partners()->whereKey($user->id)->exists();
    }
}
