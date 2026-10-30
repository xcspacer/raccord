<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingCalculator;
use App\Billing\BillingChecks;
use App\Billing\BillingRates;
use App\Billing\WorkingHours;
use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class BillingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private BillingRates $rates;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rates = app(BillingRates::class);
        $this->alice = User::factory()->create();
        $this->bruno = User::factory()->create();
    }

    private function calculator(): BillingCalculator
    {
        return new BillingCalculator(app(BillingRates::class));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<User>  $partners
     */
    private function entry(BillingClient $client, User $user, array $attributes = [], array $partners = []): BillingEntry
    {
        $entry = BillingEntry::factory()->create($attributes + ['billing_client_id' => $client->id, 'user_id' => $user->id, 'ticket' => 'T1', 'is_alone' => $partners === []]);
        $entry->partners()->attach(array_map(fn (User $partner) => $partner->id, $partners));

        return $entry->load(['billingClient', 'interventionType', 'partners', 'lines']);
    }

    public function test_type_rate_and_level_grid_with_travel_fee(): void
    {
        $client = BillingClient::factory()->create();
        $repair = $client->interventionTypes()->create(['label' => 'Repair']);
        $free = $client->interventionTypes()->create(['label' => 'Declaration', 'is_billable' => false]);
        $this->rates->set($client, BillingRates::typeKey($repair), 110, null);
        $this->rates->set($client, BillingRates::typeKey($free), 99, null);

        $this->assertSame(110.0, $this->calculator()->amount($this->entry($client, $this->alice, ['billing_intervention_type_id' => $repair->id])));
        $this->assertSame(0.0, $this->calculator()->amount($this->entry($client, $this->alice, ['billing_intervention_type_id' => $free->id, 'ticket' => 'T2'])));

        $grid = BillingClient::factory()->form(BillingFormType::LevelSchedule)->create(['has_travel_fee' => true]);
        $urgent = $grid->interventionTypes()->create(['label' => 'Urgent']);
        $this->rates->set($grid, BillingRates::gridKey(BillingSchedule::OffHours, 3, $urgent), 660, null);
        $this->rates->set($grid, BillingRates::gridKey(BillingSchedule::BusinessHours, 1, $urgent), 230, null);
        $this->rates->set($grid, BillingRates::travelKey(BillingSchedule::OffHours), 120, null);

        $this->assertSame(780.0, $this->calculator()->amount($this->entry($grid, $this->alice, ['billing_intervention_type_id' => $urgent->id, 'level' => 3, 'schedule' => BillingSchedule::OffHours])));
        $this->assertSame(230.0, $this->calculator()->amount($this->entry($grid, $this->alice, ['billing_intervention_type_id' => $urgent->id, 'ticket' => 'T3'])));
    }

    public function test_hours_are_split_between_business_and_off_hours_across_days(): void
    {
        $this->assertSame(['business' => 120, 'off' => 150], WorkingHours::split(CarbonImmutable::parse('2026-10-05 19:00'), CarbonImmutable::parse('2026-10-05 23:30')));
        $this->assertSame(['business' => 120, 'off' => 480], WorkingHours::split(CarbonImmutable::parse('2026-10-05 22:00'), CarbonImmutable::parse('2026-10-06 08:00')));
        $this->assertSame(['business' => 0, 'off' => 0], WorkingHours::split(CarbonImmutable::parse('2026-10-05 10:00'), CarbonImmutable::parse('2026-10-05 09:00')));

        $client = BillingClient::factory()->form(BillingFormType::Hourly)->create();
        $this->rates->set($client, BillingRates::hourKey(BillingSchedule::BusinessHours), 45, null);
        $this->rates->set($client, BillingRates::hourKey(BillingSchedule::OffHours), 56, null);

        $this->assertSame(230.0, $this->calculator()->amount($this->entry($client, $this->alice, ['started_at' => '2026-10-05 19:00', 'ended_at' => '2026-10-05 23:30'])));
    }

    public function test_a_flat_ticket_is_paid_once_shared_by_the_people_once_closed_any_day(): void
    {
        $client = BillingClient::factory()->form(BillingFormType::TicketFlat)->create();
        $this->rates->set($client, BillingRates::TICKET, 450, null);

        $first = $this->entry($client, $this->alice, ['work_date' => '2026-10-05', 'closure_status' => BillingClosureStatus::Frozen], [$this->bruno]);
        $this->assertSame(0.0, $this->calculator()->amount($first));

        $again = $this->entry($client, $this->alice, ['work_date' => '2026-10-06', 'closure_status' => BillingClosureStatus::Frozen], [$this->bruno]);
        $partner = $this->entry($client, $this->bruno, ['work_date' => '2026-10-07', 'closure_status' => BillingClosureStatus::Closed], [$this->alice]);
        $context = BillingEntry::query()->with(['billingClient', 'partners', 'lines', 'interventionType'])->get();
        $calculator = $this->calculator();

        $shares = $context->map(fn (BillingEntry $entry) => $calculator->share($entry, $context))->all();
        $this->assertSame([225.0, 0.0, 225.0], $shares);
        $this->assertSame(450.0, array_sum($shares));
        $this->assertSame(2, $calculator->participants($again, $context));
        $this->assertSame(450.0, $calculator->amount($partner));
    }

    public function test_works_lines_with_a_night_coefficient_and_the_day_rate_shared_by_two(): void
    {
        $client = BillingClient::factory()->create(['has_works' => true]);
        $article = BillingArticle::query()->create(['code' => 'W-1', 'label' => 'Cable', 'unit' => 'ML']);
        $this->rates->set(null, BillingRates::articleKey($article), 1.2, null);
        $works = $this->entry($client, $this->alice, ['type' => BillingEntryType::Works]);
        $works->lines()->create(['billing_article_id' => $article->id, 'quantity' => 150]);

        $this->assertEqualsWithDelta(180.0, $this->calculator()->amount($works->load('lines')), 0.001);

        $night = BillingArticle::query()->create(['code' => 'W-9', 'label' => 'Night work', 'unit' => 'COEFF', 'is_coefficient' => true]);
        $this->rates->set(null, BillingRates::articleKey($night), 0.5, null);
        $works->lines()->create(['billing_article_id' => $night->id, 'quantity' => 1]);

        $this->assertEqualsWithDelta(270.0, $this->calculator()->amount($works->load('lines')), 0.001);

        $pair = BillingClient::factory()->form(BillingFormType::DayRatePair)->create();
        $audit = $pair->interventionTypes()->create(['label' => 'Audit']);
        $this->rates->set($pair, BillingRates::typeKey($audit), 400, null);
        $day = $this->entry($pair, $this->alice, ['type' => BillingEntryType::Works, 'billing_intervention_type_id' => $audit->id], [$this->bruno]);

        $this->assertSame(200.0, $this->calculator()->share($day, collect()));
    }

    public function test_breakdown_participants_gather_every_name_of_the_ticket_that_day(): void
    {
        $client = BillingClient::factory()->create();
        $type = $client->interventionTypes()->create(['label' => 'Repair']);
        $this->rates->set($client, BillingRates::typeKey($type), 120, null);
        $carla = User::factory()->create();
        $one = $this->entry($client, $this->alice, ['billing_intervention_type_id' => $type->id], [$this->bruno]);
        $this->entry($client, $this->bruno, ['billing_intervention_type_id' => $type->id], [$carla]);
        $context = BillingEntry::query()->with(['billingClient', 'partners', 'lines', 'interventionType'])->get();

        $this->assertSame(3, $this->calculator()->participants($one, $context));
        $this->assertSame(40.0, $this->calculator()->share($one, $context));
    }

    public function test_checks_find_crew_and_description_mismatches_duplicates_and_missing_partners(): void
    {
        $client = BillingClient::factory()->create();
        $repair = $client->interventionTypes()->create(['label' => 'Repair']);
        $visit = $client->interventionTypes()->create(['label' => 'Visit']);
        $carla = User::factory()->create();
        $mine = $this->entry($client, $this->alice, ['billing_intervention_type_id' => $repair->id], [$this->bruno]);
        $theirs = $this->entry($client, $this->bruno, ['billing_intervention_type_id' => $visit->id], [$this->alice, $carla]);
        $twice = $this->entry($client, $this->alice, ['billing_intervention_type_id' => $repair->id, 'work_date' => '2026-10-09'], [$this->bruno]);
        $context = BillingEntry::query()->with(['billingClient', 'partners', 'lines', 'interventionType'])->get();
        $checks = app(BillingChecks::class);

        $this->assertSame($theirs->id, $checks->partnerMismatch($mine, $context)?->id);
        $this->assertSame($theirs->id, $checks->interventionMismatch($mine, $context)?->id);
        $this->assertSame([$mine->id => 2, $twice->id => 2], $checks->duplicates($context));

        $missing = $checks->missingPartners($context, $context);
        $this->assertEqualsCanonicalizing([[$this->bruno->id, '2026-10-09'], [$carla->id, '2026-10-05']], array_map(fn (array $reminder) => [$reminder['partner']->id, $reminder['entry']->work_date->toDateString()], $missing));
    }

    public function test_the_design_office_never_gets_billing_even_with_a_stored_switch(): void
    {
        $this->seed(RoleSeeder::class);
        $office = User::factory()->withRole(Role::DesignOffice)->create();
        RoleFeatureOverride::query()->create(['feature' => Feature::Billing, 'role_id' => RoleModel::findByName(Role::DesignOffice->value)->id, 'is_enabled' => true]);

        $this->assertFalse(Gate::forUser($office)->allows('use-feature', Feature::Billing));
    }
}
