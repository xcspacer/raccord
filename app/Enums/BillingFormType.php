<?php

namespace App\Enums;

/**
 * How a billing client's breakdowns are described and priced; each client picks one.
 */
enum BillingFormType: string
{
    /** One intervention type, each with its own rate. */
    case TypeRate = 'type_rate';

    /** One intervention type priced on a level (1-3) × business / off hours grid, with an optional travel fee. */
    case LevelSchedule = 'level_schedule';

    /** Start and end time with an operator; business hours and off hours are priced per hour. */
    case Hourly = 'hourly';

    /** Start and end time and the ticket status; a flat rate per ticket once it is closed. */
    case TicketFlat = 'ticket_flat';

    /** Start and end time; priced on a separate quote. */
    case Quote = 'quote';

    /** Works only: a day rate shared by two technicians, or a part priced on quote with its times. */
    case DayRatePair = 'day_rate_pair';

    public function label(): string
    {
        return __('enums.billing_form_types.'.$this->value);
    }

    /**
     * Forms whose intervention is described by a start and end time.
     */
    public function isTimed(): bool
    {
        return in_array($this, [self::Hourly, self::TicketFlat, self::Quote], true);
    }
}
