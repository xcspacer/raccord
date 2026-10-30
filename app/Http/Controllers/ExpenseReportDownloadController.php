<?php

namespace App\Http\Controllers;

use App\Models\ExpenseReport;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF of a generated expense report, for the person who made it and for managers only.
 */
class ExpenseReportDownloadController extends Controller
{
    public function __invoke(ExpenseReport $report): Response
    {
        Gate::authorize('view', $report);

        $file = $report->file;

        return FileResponse::make($file->disk, $file->path, $report->downloadName(), ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }
}
