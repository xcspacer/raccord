<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingRates;
use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Billing\ClientSettings;
use App\Livewire\Billing\Dashboard;
use App\Livewire\Billing\RateSettings;
use App\Livewire\Billing\TechnicianDefaults;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingOperator;
use App\Models\BillingProfile;
use App\Models\BillingRateChange;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create();
    }

    public function test_panels_are_for_administrators_only(): void
    {
        foreach ([RateSettings::class, ClientSettings::class, TechnicianDefaults::class] as $component) {
            Livewire::actingAs($this->supervisor)->test($component)->assertForbidden();
            Livewire::actingAs($this->admin)->test($component)->assertOk();
        }

        Livewire::actingAs($this->supervisor)->test(Dashboard::class)->call('togglePanel', 'rates')->assertForbidden();
    }

    public function test_a_client_is_configured_with_its_form_types_and_format(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ClientSettings::class)
            ->set('name', 'Grid client')->set('sheetLabel', 'GRID')->set('formType', 'level_schedule')->set('hasTravelFee', true)
            ->set('ticketPattern', '^(G')->call('save')->assertHasErrors('ticketPattern')
            ->set('ticketPattern', '^G\d{3}$')->call('save')->assertHasNoErrors()
            ->set('typeLabel', 'Urgent')->call('addType')
            ->set('typeLabel', 'Not billed')->set('typeBillable', false)->call('addType');

        $client = BillingClient::query()->with('interventionTypes')->sole();
        $this->assertSame(BillingFormType::LevelSchedule, $client->form_type);
        $this->assertTrue($client->has_travel_fee);
        $this->assertTrue($client->acceptsTicket('g123'));
        $this->assertSame([true, false], $client->interventionTypes->pluck('is_billable')->all());

        $used = $client->interventionTypes->first();
        BillingEntry::factory()->create(['billing_client_id' => $client->id, 'billing_intervention_type_id' => $used->id]);
        $component->call('removeType', $used->id)->assertHasErrors('typeLabel');
        $component->call('removeType', $client->interventionTypes->last()->id);
        $this->assertSame(1, $client->interventionTypes()->count());
    }

    public function test_export_sheet_names_can_be_given_for_works_and_day_rate_parts(): void
    {
        Livewire::actingAs($this->admin)->test(ClientSettings::class)
            ->set('name', 'Breakdowns')->set('sheetLabel', 'ALPHA BREAKDOWN')->set('hasWorks', true)
            ->set('worksSheetLabel', 'ALPHA/WORKS')->call('save')->assertHasErrors('worksSheetLabel')
            ->set('worksSheetLabel', 'ALPHA WORKS')->call('save')->assertHasNoErrors()
            ->call('cancel')
            ->set('name', 'Pairs')->set('sheetLabel', 'PAIRS')->set('formType', 'day_rate_pair')->set('worksSheetLabel', 'IGNORED')->call('save')->assertHasNoErrors()
            ->set('typeLabel', 'Survey (day)')->set('typeSheetLabel', 'PAIRS SURVEY')->call('addType')->assertHasNoErrors()
            ->set('typeLabel', 'Rework')->call('addType')
            ->assertSee(__('billing.settings.sheet_badge', ['name' => 'PAIRS SURVEY']));

        $this->assertSame('ALPHA WORKS', BillingClient::query()->where('name', 'Breakdowns')->value('works_sheet_label'));
        $pairs = BillingClient::query()->with('interventionTypes')->where('name', 'Pairs')->sole();
        $this->assertNull($pairs->works_sheet_label);
        $this->assertSame(['PAIRS SURVEY', null], $pairs->interventionTypes->pluck('sheet_label')->all());
    }

    public function test_rates_save_on_change_and_keep_their_history(): void
    {
        $client = BillingClient::factory()->form(BillingFormType::LevelSchedule)->create(['has_travel_fee' => true]);
        $type = $client->interventionTypes()->create(['label' => 'Urgent']);
        $article = BillingArticle::query()->create(['code' => 'W-1', 'label' => 'Cable', 'unit' => 'ML']);
        $grid = RateSettings::field($client->id, BillingRates::gridKey(BillingSchedule::OffHours, 3, $type));

        Livewire::actingAs($this->admin)->test(RateSettings::class)
            ->set('rates.'.$grid, '660')
            ->set('rates.'.RateSettings::field($client->id, BillingRates::travelKey(BillingSchedule::OffHours)), 'abc')
            ->set('rates.'.RateSettings::field(null, BillingRates::articleKey($article)), '1,2')
            ->set('rates.'.$grid, '700');

        $rates = app(BillingRates::class);
        $this->assertSame(700.0, $rates->get($client, BillingRates::gridKey(BillingSchedule::OffHours, 3, $type)));
        $this->assertSame(0.0, $rates->get($client, BillingRates::travelKey(BillingSchedule::OffHours)));
        $this->assertSame(1.2, $rates->get(null, BillingRates::articleKey($article)));
        $this->assertSame(['660.00', '700.00'], BillingRateChange::query()->where('rate_key', BillingRates::gridKey(BillingSchedule::OffHours, 3, $type))->orderBy('id')->pluck('new_amount')->all());
        $this->assertSame($this->admin->id, BillingRateChange::query()->value('changed_by'));
    }

    public function test_operators_are_unique_whatever_the_case(): void
    {
        Livewire::actingAs($this->admin)->test(RateSettings::class)
            ->set('operatorName', 'North Carrier')->call('addOperator')
            ->set('operatorName', 'north carrier')->call('addOperator')->assertSet('operatorError', __('billing.settings.operator_exists'));

        $this->assertSame(1, BillingOperator::query()->count());
    }

    public function test_a_technicians_default_client_is_set_here(): void
    {
        $client = BillingClient::factory()->create();
        $technician = User::factory()->withRole(Role::Technician)->create();
        RoleFeatureOverride::query()->create(['feature' => Feature::Billing, 'role_id' => \Spatie\Permission\Models\Role::findByName(Role::Technician->value)->id, 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();

        Livewire::actingAs($this->admin)->test(TechnicianDefaults::class)->set('defaults.'.$technician->id, (string) $client->id);
        $this->assertSame($client->id, BillingProfile::query()->find($technician->id)?->default_billing_client_id);

        Livewire::actingAs($this->admin)->test(TechnicianDefaults::class)->set('defaults.'.$this->supervisor->id, (string) $client->id)->assertNotFound();
    }
}
