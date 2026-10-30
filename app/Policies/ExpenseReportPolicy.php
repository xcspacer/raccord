<?php

namespace App\Policies;

use App\Models\ExpenseReport;
use App\Models\User;
use App\Policies\Concerns\HidesOtherPeoplesRecords;
use Illuminate\Auth\Access\Response;

/**
 * A generated expense report PDF: for its owner and the managers; anyone else gets a 404.
 */
class ExpenseReportPolicy
{
    use HidesOtherPeoplesRecords;

    public function view(User $user, ExpenseReport $expenseReport): Response
    {
        return $this->ownerOrManager($user, $expenseReport->user_id);
    }
}
