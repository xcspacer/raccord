<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Planning\WeeklyTimesheetExport;
use App\Support\EffectiveAgency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel « Pointage hebdo » of the planning week of the agency the manager is working on.
 */
class WeeklyTimesheetController extends Controller
{
    public function __invoke(Request $request, EffectiveAgency $agencies, WeeklyTimesheetExport $export): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('export-planning-timesheet');

        $agency = $agencies->for($user);
        abort_if($agency === null || ! $agencies->isDailyMode($user), 404);

        $week = (string) $request->query('week', '');
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $week) : null;
        $monday = ($parsed ?: CarbonImmutable::today(config('platform.timezone')))->startOfWeek();

        $spreadsheet = $export->build($user, $agency, $monday);
        $name = __('weekly_timesheet.file', ['week' => $monday->isoWeek, 'monday' => $monday->toDateString()]).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }
}
