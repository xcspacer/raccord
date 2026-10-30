<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingRates;
use App\Billing\EntryBadges;
use App\Enums\Role;
use App\Livewire\Billing\Dashboard;
use App\Livewire\Billing\MyEntries;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $alice;

    private User $bruno;

    private BillingClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create();
        $this->alice = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno']);
        $this->client = BillingClient::factory()->create(['ticket_pattern' => '^A\d{3}$', 'ticket_hint' => 'A + 3', 'ticket_example' => 'A123']);
        $type = $this->client->interventionTypes()->create(['label' => 'Repair']);
        app(BillingRates::class)->set($this->client, BillingRates::typeKey($type), 100, null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(User $user, array $attributes = [], ?User $partner = null): BillingEntry
    {
        $entry = BillingEntry::factory()->create($attributes + [
            'billing_client_id' => $this->client->id, 'user_id' => $user->id, 'ticket' => 'A100', 'work_date' => now()->startOfMonth()->toDateString(),
            'billing_intervention_type_id' => $this->client->interventionTypes()->value('id'), 'is_alone' => $partner === null,
        ]);

        if ($partner !== null) {
            $entry->partners()->attach($partner->id);
        }

        return $entry;
    }

    public function test_only_supervisors_and_administrators_see_it_and_only_administrators_see_amounts(): void
    {
        $this->entry($this->alice, [], $this->bruno);
        $this->entry($this->bruno, [], $this->alice);

        Livewire::actingAs($this->alice)->test(Dashboard::class)->assertForbidden();
        Livewire::actingAs($this->supervisor)->test(Dashboard::class)->assertOk()->assertDontSee(EntryBadges::money(100))->assertDontSee(EntryBadges::money(50));
        Livewire::actingAs($this->admin)->test(Dashboard::class)->assertSee(EntryBadges::money(50))->assertSee(__('billing.dashboard.total', ['period' => now()->format('Y-m')]));
    }

    public function test_counters_and_only_filters_and_missing_partner_reminders(): void
    {
        $this->entry($this->alice, [], $this->bruno);
        $this->entry($this->alice, ['work_date' => now()->startOfMonth()->addDay()->toDateString()]);

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSee(__('billing.dashboard.duplicates', ['count' => 2]))
            ->assertSee(__('billing.dashboard.reminders_title', ['count' => 1]))
            ->call('toggle', 'duplicates')->assertSet('duplicatesOnly', true)
            ->set('type', 'works')->assertSet('duplicatesOnly', false)->assertSee(__('billing.dashboard.empty'));
    }

    public function test_managers_correct_a_ticket_number_with_the_client_format(): void
    {
        $entry = $this->entry($this->alice);

        Livewire::actingAs($this->supervisor)->test(Dashboard::class)
            ->call('startTicketEdit', $entry->id)->set('ticketValue', 'X')->call('saveTicket')
            ->assertSet('ticketError', __('billing.errors.ticket_format', ['client' => $this->client->name, 'hint' => 'A + 3', 'example' => 'A123']))
            ->set('ticketValue', ' A999 ')->call('saveTicket')->assertSet('ticketEditId', null);

        $this->assertSame('A999', $entry->refresh()->ticket);
        Livewire::actingAs($this->supervisor)->test(Dashboard::class)->call('edit', $entry->id)->assertForbidden();
    }

    public function test_an_administrator_edits_another_persons_entry_which_stays_theirs(): void
    {
        $entry = $this->entry($this->alice);

        Livewire::actingAs($this->admin)->test(MyEntries::class, ['editEntryId' => $entry->id])
            ->assertSet('embedded', true)
            ->set('comment', 'Changed by the manager')->call('save')->assertSet('error', '')->assertDispatched('billing-entry-saved');

        $this->assertSame('Changed by the manager', $entry->refresh()->comment);
        $this->assertSame($this->alice->id, $entry->user_id);

        Livewire::actingAs($this->supervisor)->test(MyEntries::class, ['editEntryId' => $entry->id])->assertForbidden();
    }
}
