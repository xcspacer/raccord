<?php

namespace App\Livewire\Audit;

use App\Audit\Spreadsheet\AuditTimesheet;
use App\Models\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Choice of the period of the audit time sheet (this week, last week, this month, last month, or any dates) and the
 * download of the Excel file.
 */
class AuditTimesheetPage extends Component
{
    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->authorize('manage', Audit::class);
        $this->preset('week');
    }

    public function preset(string $name): void
    {
        $today = CarbonImmutable::today(config('platform.timezone'));
        $monday = $today->startOfWeek();

        [$from, $to] = match ($name) {
            'lastweek' => [$monday->subWeek(), $monday->subDays(2)],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            'lastmonth' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            default => [$monday, $monday->addDays(5)],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function render(AuditTimesheet $timesheet): View
    {
        $this->authorize('manage', Audit::class);
        $user = auth()->user();
        $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) === 1;

        return view('livewire.audit.audit-timesheet-page', [
            'canDownload' => $valid && $user instanceof User,
            'downloadUrl' => $valid ? route('audits.weekly-timesheet.download', ['from' => $this->from, 'to' => $this->to]) : '#',
        ])->layout('components.layouts.app', ['title' => __('audit.back'), 'page' => 'audits']);
    }
}
