<?php

namespace App\Billing;

use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Models\BillingClient;
use App\Models\BillingInterventionType;

/**
 * Which prices a client's form needs: per billable type, a level × schedule grid with the travel fee, the hourly
 * rates, the flat ticket price or the day rate.
 */
class ClientRateLayout
{
    /**
     * @return list<string>
     */
    public function keys(BillingClient $client): array
    {
        $types = $client->interventionTypes->where('is_billable', true);

        return match ($client->form_type) {
            BillingFormType::TypeRate, BillingFormType::DayRatePair => $types->map(fn (BillingInterventionType $type) => BillingRates::typeKey($type))->values()->all(),
            BillingFormType::LevelSchedule => [
                ...collect(BillingSchedule::cases())->flatMap(fn (BillingSchedule $schedule) => collect([1, 2, 3])->flatMap(fn (int $level) => $types->map(fn (BillingInterventionType $type) => BillingRates::gridKey($schedule, $level, $type))))->values()->all(),
                ...($client->has_travel_fee ? array_map(fn (BillingSchedule $schedule) => BillingRates::travelKey($schedule), BillingSchedule::cases()) : []),
            ],
            BillingFormType::Hourly => array_map(fn (BillingSchedule $schedule) => BillingRates::hourKey($schedule), BillingSchedule::cases()),
            BillingFormType::TicketFlat => [BillingRates::TICKET],
            BillingFormType::Quote => [],
        };
    }
}
