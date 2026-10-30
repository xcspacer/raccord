<?php

namespace App\Billing;

use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Enums\BillingPhotoCategory;
use App\Models\BillingEntry;
use App\Models\BillingEntryLine;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * The small labels under an entry: what was done, times and duration, partners, amount and photos.
 */
class EntryBadges
{
    /**
     * @return list<string>
     */
    public function for(BillingEntry $entry, ?float $amount = null): array
    {
        $form = $entry->billingClient->form_type;
        $type = $entry->interventionType;
        $badges = [];
        $onQuote = false;

        if ($entry->type === BillingEntryType::Works) {
            if ($form === BillingFormType::DayRatePair) {
                if ($type !== null) {
                    $badges[] = $type->summaryLabel();
                }

                if ($type !== null && ! $type->is_billable) {
                    array_push($badges, ...$this->timeBadges($entry));
                    $badges[] = __('billing.badges.on_quote');
                    $onQuote = true;
                }
            } else {
                foreach ($entry->lines as $line) {
                    /** @var BillingEntryLine $line */
                    $badges[] = $line->article->label.' ×'.self::quantity($line->quantity);
                }
            }
        } else {
            switch ($form) {
                case BillingFormType::TypeRate:
                    if ($type !== null) {
                        $badges[] = $type->summaryLabel();
                    }
                    break;
                case BillingFormType::LevelSchedule:
                    if ($type !== null) {
                        $badges[] = $type->summaryLabel();
                    }
                    if ($entry->level !== null) {
                        $badges[] = __('billing.form.level_n', ['level' => $entry->level]);
                    }
                    if ($entry->schedule !== null) {
                        $badges[] = $entry->schedule->shortLabel();
                    }
                    break;
                case BillingFormType::Hourly:
                    if ($entry->operator !== null) {
                        $badges[] = $entry->operator->name;
                    }
                    if ($entry->started_at !== null && $entry->ended_at !== null) {
                        $badges[] = self::range($entry->started_at, $entry->ended_at);
                        $minutes = WorkingHours::split($entry->started_at, $entry->ended_at);
                        if ($minutes['business'] > 0) {
                            $badges[] = __('enums.billing_schedules_short.business_hours').' '.self::duration($minutes['business']);
                        }
                        if ($minutes['off'] > 0) {
                            $badges[] = __('enums.billing_schedules_short.off_hours').' '.self::duration($minutes['off']);
                        }
                    }
                    break;
                case BillingFormType::TicketFlat:
                    array_push($badges, ...$this->timeBadges($entry));
                    $badges[] = $entry->closure_status === BillingClosureStatus::Closed ? __('billing.badges.closed') : __('billing.badges.frozen');
                    break;
                case BillingFormType::Quote:
                case BillingFormType::DayRatePair:
                    array_push($badges, ...$this->timeBadges($entry));
                    $badges[] = __('billing.badges.on_quote');
                    $onQuote = true;
                    break;
            }
        }

        $partners = $entry->partners->map(fn (User $partner) => $partner->name)->all();

        if (count($partners) === 1) {
            $badges[] = __('billing.badges.partner', ['name' => $partners[0]]);
        } elseif (count($partners) > 1) {
            $badges[] = __('billing.badges.partners', ['count' => count($partners), 'names' => implode(', ', $partners)]);
        }

        if ($amount !== null && ! $onQuote) {
            $badges[] = self::money($amount);
        }

        $photos = $entry->photos;

        if ($photos->isNotEmpty()) {
            $before = $photos->where('category', BillingPhotoCategory::Before)->count();
            $after = $photos->where('category', BillingPhotoCategory::After)->count();
            $badges[] = $entry->type === BillingEntryType::Works && ($before > 0 || $after > 0)
                ? __('billing.badges.photos_split', ['before' => $before, 'after' => $after])
                : __('billing.badges.photos', ['count' => $photos->count()]);
        }

        return $badges;
    }

    /**
     * Euros with cents, written as the user's language does (« 1 234,56 € », « €1,234.56 »).
     */
    public static function money(float $amount): string
    {
        return (string) Number::currency($amount, 'EUR', app()->getLocale());
    }

    /**
     * « 2h30 », « 2h ».
     */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours.'h'.($rest > 0 ? str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * « 08:00 → 12:30 », or with the days when it ends another day.
     */
    public static function range(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->isSameDay($end)
            ? $start->format('H:i').' → '.$end->format('H:i')
            : $start->format('d/m H:i').' → '.$end->format('d/m H:i');
    }

    public static function quantity(string|float $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    }

    /**
     * @return list<string>
     */
    private function timeBadges(BillingEntry $entry): array
    {
        if ($entry->started_at === null || $entry->ended_at === null) {
            return [];
        }

        $minutes = (int) $entry->started_at->diffInMinutes($entry->ended_at);

        return array_values(array_filter([self::range($entry->started_at, $entry->ended_at), $minutes > 0 ? self::duration($minutes) : null]));
    }
}
