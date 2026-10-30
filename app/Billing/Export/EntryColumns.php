<?php

namespace App\Billing\Export;

use App\Billing\BillingCalculator;
use App\Enums\BillingClosureStatus;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingInterventionType;
use Illuminate\Support\Collection;

/**
 * Columns shared by the global and the per-technician exports. In the technician's file empty measures stay blank
 * and the intervention types are counted in halves, as in the reference file.
 */
class EntryColumns
{
    /**
     * @param  Collection<int, BillingEntry>  $context  entries the shares and crews are compared with
     */
    public function __construct(private BillingCalculator $calculator, private Collection $context, private bool $perTechnician) {}

    /**
     * One column per intervention type of the client: billable types marked « 1 » (« 0,5 » for a technician, added
     * up in the footer), the others « OUI ».
     *
     * @return list<SheetColumn>
     */
    public function types(BillingClient $client): array
    {
        return $client->interventionTypes->map(fn (BillingInterventionType $type) => new SheetColumn(
            $type->label,
            max(8, min(30, mb_strlen($type->label) + 4)),
            fn (BillingEntry $entry) => $entry->billing_intervention_type_id !== $type->id ? null : match (true) {
                ! $type->is_billable => __('billing.export.values.not_billed'),
                $this->perTechnician => 0.5,
                default => '1',
            },
            $type->is_billable,
        ))->values()->all();
    }

    public function level(): SheetColumn
    {
        return new SheetColumn(__('billing.export.columns.level'), 10, fn (BillingEntry $entry) => $entry->level === null ? null : __('billing.form.level_n', ['level' => $entry->level]));
    }

    public function schedule(): SheetColumn
    {
        return new SheetColumn(__('billing.export.columns.schedule'), 8, fn (BillingEntry $entry) => $entry->schedule?->shortLabel());
    }

    public function operator(): SheetColumn
    {
        return new SheetColumn(__('billing.export.columns.operator'), 16, fn (BillingEntry $entry) => $entry->operator?->name);
    }

    /**
     * Start time, end time and end day.
     *
     * @return list<SheetColumn>
     */
    public function times(): array
    {
        return [
            new SheetColumn(__('billing.export.columns.start_time'), 12, fn (BillingEntry $entry) => $entry->started_at?->format('H:i')),
            new SheetColumn(__('billing.export.columns.end_time'), 12, fn (BillingEntry $entry) => $entry->ended_at?->format('H:i')),
            new SheetColumn(__('billing.export.columns.end_date'), 12, fn (BillingEntry $entry) => ($entry->ended_at ?? $entry->work_date)->format('d/m/Y')),
        ];
    }

    public function duration(): SheetColumn
    {
        return new SheetColumn(__('billing.export.columns.duration'), 10, function (BillingEntry $entry): ?float {
            $minutes = $entry->started_at !== null && $entry->ended_at !== null && $entry->ended_at->gt($entry->started_at) ? (int) $entry->started_at->diffInMinutes($entry->ended_at) : 0;

            return $this->measure($minutes / 60);
        }, true);
    }

    /**
     * Hours in business hours and in off hours.
     *
     * @return list<SheetColumn>
     */
    public function hours(): array
    {
        return [
            new SheetColumn(__('billing.export.columns.business_hours'), 10, fn (BillingEntry $entry) => $this->measure($this->calculator->minutes($entry)['business'] / 60), true),
            new SheetColumn(__('billing.export.columns.off_hours'), 10, fn (BillingEntry $entry) => $this->measure($this->calculator->minutes($entry)['off'] / 60), true),
        ];
    }

    public function status(): SheetColumn
    {
        return new SheetColumn(__('billing.export.columns.ticket_status'), 14, fn (BillingEntry $entry) => $entry->closure_status === BillingClosureStatus::Closed ? __('billing.badges.closed') : __('billing.badges.frozen'));
    }

    /**
     * The intervention's amount (never rounded) and the person's part (to the cent).
     *
     * @return list<SheetColumn>
     */
    public function amounts(bool $perTicket = false): array
    {
        $width = $this->perTechnician ? 20 : 16;

        return [
            new SheetColumn(__($perTicket ? 'billing.export.columns.ticket_amount' : 'billing.export.columns.amount'), $width, fn (BillingEntry $entry) => $this->calculator->amount($entry)),
            new SheetColumn(__('billing.export.columns.share'), $width, fn (BillingEntry $entry) => round($this->calculator->share($entry, $this->context), 2), true),
        ];
    }

    private function measure(float $hours): ?float
    {
        $rounded = round($hours, 2);

        return $this->perTechnician && $rounded === 0.0 ? null : $rounded;
    }
}
