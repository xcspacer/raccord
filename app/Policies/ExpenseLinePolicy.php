<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\ExpenseLine;
use App\Models\User;
use App\Policies\Concerns\HidesOtherPeoplesRecords;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * An expense report line and its receipt photo: for its owner and the managers; anyone else gets a 404.
 */
class ExpenseLinePolicy
{
    use HidesOtherPeoplesRecords;

    /**
     * Prepare one's own expense report (lines, receipts, draft).
     */
    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('use-feature', Feature::ExpenseReports);
    }

    public function view(User $user, ExpenseLine $expenseLine): Response
    {
        return $this->ownerOrManager($user, $expenseLine->user_id);
    }
}
