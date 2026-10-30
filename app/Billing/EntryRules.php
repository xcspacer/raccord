<?php

namespace App\Billing;

use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Models\BillingClient;
use App\Models\BillingInterventionType;
use Carbon\CarbonImmutable;

/**
 * Checks an entry before it is saved, in the legacy order: the first problem found is the message shown.
 *
 * @phpstan-type EntryInput array{type: string, date: string, ticket: string, typeId: int|string|null, level: int|string|null, schedule: string|null, startTime: string, endTime: string, endDate: string, operatorId: int|string|null, closure?: string|null, alone: bool, partners: list<int|string>, comment: string, lines: list<array{article: int|string, quantity: float|int|string}>}
 */
class EntryRules
{
    /**
     * @param  EntryInput  $input
     */
    public function firstError(BillingClient $client, array $input): ?string
    {
        $ticket = trim($input['ticket']);
        $works = $input['type'] === BillingEntryType::Works->value;
        $type = $input['typeId'] ? BillingInterventionType::query()->where('billing_client_id', $client->id)->find($input['typeId']) : null;
        $form = $client->form_type;

        return match (true) {
            $ticket === '' => __('billing.errors.no_ticket'),
            ! $client->acceptsTicket($ticket) => __('billing.errors.ticket_format', ['client' => $client->name, 'hint' => (string) $client->ticket_hint, 'example' => (string) $client->ticket_example]),
            trim($input['date']) === '' => __('billing.errors.no_date'),
            ! $works && $form === BillingFormType::LevelSchedule && $type === null => __('billing.errors.select_breakdown_type'),
            ! $works && $form === BillingFormType::LevelSchedule && $type?->is_billable && ! in_array((int) $input['level'], [1, 2, 3], true) => __('billing.errors.select_level'),
            ! $works && $form === BillingFormType::LevelSchedule && $type?->is_billable && ($input['schedule'] ?? '') === '' => __('billing.errors.select_schedule'),
            ! $works && $form === BillingFormType::TypeRate && $type === null => __('billing.errors.select_intervention_type'),
            ! $works && $form === BillingFormType::Hourly && ! $input['operatorId'] => __('billing.errors.select_operator'),
            $this->isTimed($client, $works, $type) && $this->interval($input) === null => __('billing.errors.times_required'),
            $this->isTimed($client, $works, $type) && ! $this->interval($input)[1]->gt($this->interval($input)[0]) => __('billing.errors.times_order'),
            $works && $form === BillingFormType::DayRatePair && $type === null => __('billing.errors.select_part'),
            $works && $form === BillingFormType::DayRatePair && $type?->is_billable && ($input['alone'] || count($input['partners']) !== 1) => __('billing.errors.exactly_one_partner'),
            $works && $form !== BillingFormType::DayRatePair && $input['lines'] === [] => __('billing.errors.add_article'),
            $input['partners'] === [] && ! $input['alone'] => __('billing.errors.partners_required'),
            trim($input['comment']) === '' => __('billing.errors.comment_required'),
            default => null,
        };
    }

    /**
     * Whether the form asks for start and end times: hourly, flat-ticket and quote breakdowns, and the part priced
     * on quote of day-rate works.
     */
    public function isTimed(BillingClient $client, bool $works, ?BillingInterventionType $type): bool
    {
        if ($works) {
            return $client->form_type === BillingFormType::DayRatePair && $type !== null && ! $type->is_billable;
        }

        return $client->form_type->isTimed();
    }

    /**
     * Start and end of the intervention, or null when a date or time is missing or invalid.
     *
     * @param  array{date: string, startTime: string, endTime: string, endDate: string}  $input
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function interval(array $input): ?array
    {
        $start = $this->moment($input['date'], $input['startTime']);
        $end = $this->moment($input['endDate'] !== '' ? $input['endDate'] : $input['date'], $input['endTime']);

        return $start === null || $end === null ? null : [$start, $end];
    }

    private function moment(string $date, string $time): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time);
    }
}
