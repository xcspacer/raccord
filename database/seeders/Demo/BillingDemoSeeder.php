<?php

namespace Database\Seeders\Demo;

use App\Billing\BillingRates;
use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingExpenseCategory;
use App\Enums\BillingExpenseStatus;
use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingExpense;
use App\Models\BillingOperator;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invented billing data: one client per form type with its own types and rates, a small works price list,
 * operators, a few entries of the current month and expenses.
 */
class BillingDemoSeeder extends Seeder
{
    public function run(BillingRates $rates): void
    {
        if (BillingClient::query()->exists()) {
            return;
        }

        $types = BillingClient::query()->create(['name' => 'Alpha Fibre', 'sheet_label' => 'ALPHA FIBRE', 'form_type' => BillingFormType::TypeRate, 'has_works' => true, 'ticket_pattern' => '^A\d{6}(-\d{2})?$', 'ticket_hint' => 'A followed by 6 digits, optionally -XX', 'ticket_example' => 'A123456-01', 'sort_order' => 1]);
        foreach ([['Inspection done', 'Done', 80], ['Inspection missed', 'Missed', 60], ['Repair', null, 110], ['Night repair', null, 130], ['No charge', null, 0, false]] as $index => $type) {
            $created = $types->interventionTypes()->create(['label' => $type[0], 'short_label' => $type[1], 'is_billable' => $type[3] ?? true, 'sort_order' => $index]);
            $rates->set($types, BillingRates::typeKey($created), $type[2], null);
        }

        $grid = BillingClient::query()->create(['name' => 'Beta Networks', 'sheet_label' => 'BETA NETWORKS', 'form_type' => BillingFormType::LevelSchedule, 'has_travel_fee' => true, 'ticket_pattern' => '^BN\d{8}$', 'ticket_hint' => 'BN followed by 8 digits', 'ticket_example' => 'BN12345678', 'sort_order' => 2]);
        foreach ([['Urgent', 200], ['Standard', 150], ['Not billed', 0]] as $index => $type) {
            $created = $grid->interventionTypes()->create(['label' => $type[0], 'is_billable' => $type[1] > 0, 'sort_order' => $index]);

            foreach (BillingSchedule::cases() as $schedule) {
                foreach ([1, 2, 3] as $level) {
                    $rates->set($grid, BillingRates::gridKey($schedule, $level, $created), $type[1] * $level * ($schedule === BillingSchedule::OffHours ? 1.5 : 1), null);
                }
            }
        }
        $rates->set($grid, BillingRates::travelKey(BillingSchedule::BusinessHours), 70, null);
        $rates->set($grid, BillingRates::travelKey(BillingSchedule::OffHours), 120, null);

        $hourly = BillingClient::query()->create(['name' => 'Gamma Backbone', 'sheet_label' => 'GAMMA BACKBONE', 'form_type' => BillingFormType::Hourly, 'sort_order' => 3]);
        $rates->set($hourly, BillingRates::hourKey(BillingSchedule::BusinessHours), 45, null);
        $rates->set($hourly, BillingRates::hourKey(BillingSchedule::OffHours), 56, null);

        $flat = BillingClient::query()->create(['name' => 'Delta Metro', 'sheet_label' => 'DELTA METRO', 'form_type' => BillingFormType::TicketFlat, 'ticket_pattern' => '^\d{6}$', 'ticket_hint' => '6 digits', 'ticket_example' => '123456', 'sort_order' => 4]);
        $rates->set($flat, BillingRates::TICKET, 450, null);

        BillingClient::query()->create(['name' => 'Works on quote', 'sheet_label' => 'WORKS ON QUOTE', 'form_type' => BillingFormType::Quote, 'sort_order' => 5]);

        $pair = BillingClient::query()->create(['name' => 'Epsilon Access', 'sheet_label' => 'EPSILON ACCESS', 'form_type' => BillingFormType::DayRatePair, 'has_breakdown' => false, 'has_works' => true, 'sort_order' => 6]);
        $audit = $pair->interventionTypes()->create(['label' => 'Box audit (BH)', 'is_billable' => true, 'sort_order' => 0]);
        $pair->interventionTypes()->create(['label' => 'Box rework (OH)', 'is_billable' => false, 'sort_order' => 1]);
        $rates->set($pair, BillingRates::typeKey($audit), 400, null);

        foreach ([['W-01', 'Cable laid in duct', 'ML', 1.2], ['W-02', 'Splice box installed', 'UNI', 85], ['W-03', 'Fibre splice', 'FO', 4.5], ['W-04', 'Night work coefficient', 'COEFF', 0.5]] as $index => $article) {
            $created = BillingArticle::query()->create(['code' => $article[0], 'label' => $article[1], 'unit' => $article[2], 'is_coefficient' => $article[2] === 'COEFF', 'sort_order' => $index]);
            $rates->set(null, BillingRates::articleKey($created), $article[3], null);
        }

        foreach (['Operator North', 'Operator South', 'Metro Carrier'] as $operator) {
            BillingOperator::query()->create(['name' => $operator]);
        }

        $this->entries($types, $grid, $hourly, $flat);
    }

    private function entries(BillingClient $types, BillingClient $grid, BillingClient $hourly, BillingClient $flat): void
    {
        $technicians = User::query()->whereIn('username', ['tech1', 'tech2', 'tech3'])->orderBy('username')->get();

        if ($technicians->count() < 3) {
            return;
        }

        [$first, $second, $third] = $technicians->all();
        $day = Carbon::now(config('platform.timezone'))->startOfMonth()->addDays(2);
        $make = fn (User $user, BillingClient $client, array $attributes) => BillingEntry::query()->create($attributes + [
            'uuid' => (string) Str::uuid(), 'type' => BillingEntryType::Breakdown, 'billing_client_id' => $client->id, 'user_id' => $user->id,
            'work_date' => $day->toDateString(), 'is_alone' => true, 'comment' => 'Intervention done, customer informed.',
        ]);

        $repair = $types->interventionTypes()->where('label', 'Repair')->value('id');
        $shared = $make($first, $types, ['ticket' => 'A100001', 'billing_intervention_type_id' => $repair, 'is_alone' => false]);
        $shared->partners()->attach($second->id);
        $make($second, $types, ['ticket' => 'A100001', 'billing_intervention_type_id' => $repair, 'is_alone' => false])->partners()->attach($first->id);

        $missing = $make($first, $types, ['ticket' => 'A100002', 'billing_intervention_type_id' => $repair, 'is_alone' => false]);
        $missing->partners()->attach($third->id);

        $make($third, $grid, ['ticket' => 'BN10000001', 'billing_intervention_type_id' => $grid->interventionTypes()->value('id'), 'level' => 2, 'schedule' => BillingSchedule::OffHours]);
        $make($second, $hourly, ['ticket' => 'GB-77', 'billing_operator_id' => BillingOperator::query()->value('id'), 'started_at' => $day->copy()->setTime(19, 0), 'ended_at' => $day->copy()->setTime(23, 30)]);
        $make($third, $flat, ['ticket' => '500001', 'started_at' => $day->copy()->setTime(9, 0), 'ended_at' => $day->copy()->setTime(12, 0), 'closure_status' => BillingClosureStatus::Closed]);

        BillingExpense::query()->create(['user_id' => $first->id, 'spent_on' => $day->toDateString(), 'category' => BillingExpenseCategory::Fuel, 'amount' => 62.40, 'mileage_km' => 340, 'status' => BillingExpenseStatus::Pending]);
        BillingExpense::query()->create(['user_id' => $second->id, 'spent_on' => $day->toDateString(), 'category' => BillingExpenseCategory::Toll, 'amount' => 12.80, 'status' => BillingExpenseStatus::Approved, 'reviewed_at' => now()]);
    }
}
