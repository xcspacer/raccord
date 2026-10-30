<?php

namespace App\Expenses;

use App\Enums\ExpenseNature;
use App\Models\ExpenseLine;
use Illuminate\Support\Collection;

/**
 * Total of an expense report and its recap by group of natures.
 */
class ExpenseTotals
{
    /**
     * @param  Collection<int, ExpenseLine>  $lines
     * @return array{total: int, recap: array<string, int>}
     */
    public function for(Collection $lines): array
    {
        $recap = array_fill_keys(ExpenseNature::recapGroups(), 0);

        foreach ($lines as $line) {
            $recap[$line->nature->recapGroup()] += $line->amount_cents;
        }

        return ['total' => array_sum($recap), 'recap' => $recap];
    }
}
