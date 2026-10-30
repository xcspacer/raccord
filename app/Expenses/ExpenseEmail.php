<?php

namespace App\Expenses;

use App\Models\ExpenseReport;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Subject and body of the e-mail a person sends with their monthly expense report.
 */
class ExpenseEmail
{
    /**
     * @return array{subject: string, body: string}
     */
    public function for(ExpenseReport $report): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $report->month)->translatedFormat('F Y');

        return [
            'subject' => __('expense_reports.email.subject', ['month' => $month, 'name' => $report->name]),
            'body' => __('expense_reports.email.body', ['month' => $month, 'total' => Money::format($report->total_cents)]),
        ];
    }
}
