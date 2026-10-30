<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\Indicators;
use App\DesignOfficeWorkflow\ProductionTime;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditBox;
use App\Models\AuditDurationAdjustment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Indicators of the design office workflow over a period: reviewers (decisions, time to decide, late ones) and
 * technicians (submissions, refusals, accepted at the first submission, production time). Each person sees only their
 * own figures; supervisors see everyone's and correct the production time of a box (the computed value stays beside the
 * correction).
 */
class IndicatorsPage extends Component
{
    /** Longest period shown at once (days). */
    private const int MAX_DAYS = 366;

    public string $from = '';

    public string $to = '';

    #[Locked]
    public ?int $adjusting = null;

    public string $minutes = '';

    public string $justification = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditDurationAdjustment::class);

        $today = CarbonImmutable::now(config('platform.timezone'));
        $this->from = $today->subDays(29)->toDateString();
        $this->to = $today->toDateString();
    }

    public function startAdjustment(int $boxId): void
    {
        $box = AuditBox::query()->findOrFail($boxId);
        $this->authorize('create', [AuditDurationAdjustment::class, $box]);

        $this->adjusting = $this->adjusting === $box->id ? null : $box->id;
        $this->reset('minutes', 'justification');
        $this->resetValidation();
    }

    public function adjust(ProductionTime $production): void
    {
        $box = AuditBox::query()->findOrFail($this->adjusting);
        $this->authorize('create', [AuditDurationAdjustment::class, $box]);

        $this->validate([
            'minutes' => ['required', 'integer', 'min:0', 'max:'.intdiv(ProductionTime::MAX_SECONDS, 60)],
            'justification' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            $production->adjust($this->user(), $box, (int) $this->minutes * 60, $this->justification);
        } catch (WorkflowException $exception) {
            $this->addError('minutes', $exception->translated());

            return;
        }

        $this->reset('adjusting', 'minutes', 'justification');
        $this->dispatch('toast', message: __('design_office_workflow.indicators.adjust_saved'));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'minutes' => __('design_office_workflow.indicators.minutes'),
            'justification' => __('design_office_workflow.indicators.justification'),
        ];
    }

    public function render(Indicators $indicators): View
    {
        $user = $this->user();
        $supervisor = WorkflowRoles::isSupervisor($user);
        [$from, $to] = $this->period();
        $completed = $indicators->boxes($from, $to, $supervisor ? null : $user);

        return view('livewire.design-office-workflow.indicators-page', [
            'isSupervisor' => $supervisor,
            'reviewers' => $supervisor || WorkflowRoles::isReviewer($user) ? $indicators->reviewers($from, $to, $supervisor ? null : $user->id) : [],
            'technicians' => $supervisor || WorkflowRoles::isTechnician($user) ? $indicators->technicians($from, $to, $completed, $supervisor ? null : $user->id) : [],
            'boxes' => $supervisor || WorkflowRoles::isTechnician($user) ? $completed : [],
        ])->layout('components.layouts.app', ['title' => __('navigation.items.design_office_indicators.label'), 'page' => 'design-office']);
    }

    /**
     * The chosen days, whole, in the platform's time zone; a wrong or too long period falls back to the last 30 days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $timezone = (string) config('platform.timezone');

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->from, $timezone);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->to, $timezone);
        } catch (Throwable) {
            $from = $to = false;
        }

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->lt($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            $to = CarbonImmutable::now($timezone)->startOfDay();
            $from = $to->subDays(29);
        }

        return [$from->startOfDay()->utc(), $to->endOfDay()->utc()];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
