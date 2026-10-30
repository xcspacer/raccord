<?php

namespace App\Http\Controllers;

use App\Audit\Spreadsheet\AuditTimesheet;
use App\Models\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit time sheet of a period as an Excel file, for the managers.
 */
class AuditTimesheetController extends Controller
{
    public function __invoke(Request $request, AuditTimesheet $timesheet): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('manage', Audit::class), 403);

        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], config('platform.timezone'));
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], config('platform.timezone'));
        $book = $timesheet->build($user, $from, $to);
        abort_if($book === null, 404, __('audit.timesheet.none'));

        [$first, $last] = $from->lte($to) ? [$from, $to] : [$to, $from];
        $name = $first->startOfWeek()->equalTo($last->startOfWeek())
            ? __('audit.timesheet.file_week', ['week' => $first->isoWeek, 'monday' => $first->startOfWeek()->toDateString()])
            : __('audit.timesheet.file_period', ['from' => $first->toDateString(), 'to' => $last->toDateString()]);

        return response()->streamDownload(function () use ($book): void {
            IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }
}
