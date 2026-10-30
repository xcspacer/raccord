<?php

namespace App\Http\Controllers\Billing;

use App\Billing\Export\ExpenseWorkbooks;
use App\Billing\Export\GlobalWorkbook;
use App\Billing\Export\TechnicianWorkbooks;
use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\BillingExpense;
use App\Models\User;
use App\Support\ZipDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

/**
 * Billing exports: the month's entries (global workbook, or one workbook per technician) for administrators, the
 * month's expenses for administrators and supervisors, and one expense for whoever may see it.
 */
class BillingExportController extends Controller
{
    public function global(Request $request, GlobalWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('export', BillingEntry::class);

        $month = $this->month($request);
        $book = $workbook->build($month);

        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
        }, $workbook->fileName($month), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function technicians(Request $request, TechnicianWorkbooks $workbooks, ZipDownload $zips): Response
    {
        Gate::authorize('export', BillingEntry::class);

        $month = $this->month($request);

        return $zips->stream('billing_technicians', $workbooks->fileName($month), fn (ZipStream $zip) => $workbooks->write($zip, $month));
    }

    public function expenses(Request $request, ExpenseWorkbooks $workbooks): Response
    {
        Gate::authorize('viewAny', BillingExpense::class);

        $data = $request->validate(['technician' => ['nullable', 'integer'], 'scope' => ['nullable', Rule::in(ExpenseWorkbooks::SCOPES)]]);
        $technician = empty($data['technician']) ? null : User::query()->findOrFail((int) $data['technician']);
        $response = $workbooks->month($this->month($request), $technician, $technician === null ? 'all' : ($data['scope'] ?? 'all'));
        abort_if($response === null, 404, __('billing.export.expenses.nothing'));

        return $response;
    }

    public function expense(BillingExpense $expense, ExpenseWorkbooks $workbooks): Response
    {
        Gate::authorize('view', $expense);

        return $workbooks->single($expense);
    }

    private function month(Request $request): string
    {
        return (string) $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
    }
}
