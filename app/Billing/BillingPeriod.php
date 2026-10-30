<?php

namespace App\Billing;

use App\Enums\BillingFormType;
use App\Models\BillingEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Entries of a month (« YYYY-MM ») or a day (« YYYY-MM-DD »), and the entries they are compared with for shares
 * and inconsistencies: the same days, and every day of the flat-priced tickets.
 */
class BillingPeriod
{
    private const array RELATIONS = ['billingClient.interventionTypes', 'interventionType', 'operator', 'partners:id,name', 'lines.article', 'photos', 'user:id,name'];

    /**
     * @return Collection<int, BillingEntry>
     */
    public function entries(string $period): Collection
    {
        return BillingEntry::query()
            ->with(self::RELATIONS)
            ->when(strlen($period) === 10, fn ($query) => $query->whereDate('work_date', $period), fn ($query) => $query->where('work_date', 'like', $period.'-%'))
            ->get();
    }

    /**
     * @param  Collection<int, BillingEntry>  $entries
     * @return Collection<int, BillingEntry>
     */
    public function context(Collection $entries): Collection
    {
        $flatTickets = $entries->filter(fn (BillingEntry $entry) => $entry->billingClient->form_type === BillingFormType::TicketFlat)->pluck('ticket')->unique()->all();
        $days = $entries->map(fn (BillingEntry $entry) => $entry->work_date->toDateString())->unique()->all();

        return BillingEntry::query()
            ->with(self::RELATIONS)
            ->where(fn ($query) => $query->whereIn('work_date', $days)->orWhereIn('ticket', $flatTickets))
            ->get();
    }
}
