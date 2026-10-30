<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Billing\BillingPage;
use App\Livewire\Billing\MyEntries;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingOperator;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class MyEntriesTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $partner;

    private BillingClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        RoleFeatureOverride::query()->create(['feature' => Feature::Billing, 'role_id' => RoleModel::findByName(Role::Technician->value)->id, 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice Martin']);
        $this->partner = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno Petit']);
        $this->client = BillingClient::factory()->create(['name' => 'Alpha', 'ticket_pattern' => '^A\d{6}$', 'ticket_hint' => 'A + 6 digits', 'ticket_example' => 'A123456', 'has_works' => true]);
        $this->client->interventionTypes()->create(['label' => 'Repair']);
    }

    private function form(): Testable
    {
        return Livewire::actingAs($this->technician)->test(MyEntries::class)->call('chooseClient', $this->client->id);
    }

    public function test_access_follows_the_billing_feature(): void
    {
        $this->actingAs($this->technician)->get(route('billing'))->assertOk()->assertSee(__('billing.home.entries'));
        $this->actingAs(User::factory()->withRole(Role::DesignOffice)->create())->get(route('billing'))->assertForbidden();
        Livewire::actingAs(User::factory()->withRole(Role::DesignOffice)->create())->test(BillingPage::class)->assertForbidden();
    }

    public function test_the_form_checks_in_the_legacy_order_then_saves_with_partners(): void
    {
        $type = $this->client->interventionTypes()->first();

        $this->form()
            ->call('save')->assertSet('error', __('billing.errors.no_ticket'))
            ->set('ticket', 'X1')->call('save')->assertSet('error', __('billing.errors.ticket_format', ['client' => 'Alpha', 'hint' => 'A + 6 digits', 'example' => 'A123456']))
            ->set('ticket', 'a123456')->call('save')->assertSet('error', __('billing.errors.select_intervention_type'))
            ->set('typeId', (string) $type->id)->call('save')->assertSet('error', __('billing.errors.partners_required'))
            ->set('partnerToAdd', (string) $this->partner->id)->call('addPartner')
            ->call('save')->assertSet('error', __('billing.errors.comment_required'))
            ->set('comment', 'Fixed')->call('save')->assertSet('error', '');

        $entry = BillingEntry::query()->with('partners')->sole();
        $this->assertSame($this->technician->id, $entry->user_id);
        $this->assertSame('a123456', $entry->ticket);
        $this->assertSame([$this->partner->id], $entry->partners->modelKeys());
        $this->assertSame($type->id, $entry->billing_intervention_type_id);
    }

    public function test_works_lines_add_up_and_photos_are_stored_when_saved(): void
    {
        $article = BillingArticle::query()->create(['code' => 'W-1', 'label' => 'Cable', 'unit' => 'ML']);

        $this->form()->call('switchType', 'works')
            ->set('ticket', 'A000001')->set('alone', true)->set('comment', 'Done')
            ->call('save')->assertSet('error', __('billing.errors.add_article'))
            ->set('articleToAdd', (string) $article->id)->set('articleQuantity', '10')->call('addLine')
            ->set('articleToAdd', (string) $article->id)->set('articleQuantity', '2,5')->call('addLine')
            ->set('incoming.before', [UploadedFile::fake()->image('a.jpg', 800, 600)])
            ->call('save')->assertSet('error', '');

        $entry = BillingEntry::query()->with(['lines', 'photos'])->sole();
        $this->assertSame(BillingEntryType::Works, $entry->type);
        $this->assertSame(12.5, (float) $entry->lines->sole()->quantity);
        $this->assertSame('before', $entry->photos->sole()->category?->value);
    }

    public function test_timed_and_grid_forms_validate_their_own_fields(): void
    {
        $hourly = BillingClient::factory()->form(BillingFormType::Hourly)->create();
        $operator = BillingOperator::query()->create(['name' => 'Op']);

        Livewire::actingAs($this->technician)->test(MyEntries::class)->call('chooseClient', $hourly->id)
            ->set('ticket', 'H-1')->set('alone', true)->set('comment', 'x')
            ->call('save')->assertSet('error', __('billing.errors.select_operator'))
            ->set('operatorId', (string) $operator->id)->call('save')->assertSet('error', __('billing.errors.times_required'))
            ->set('startTime', '22:00')->set('endTime', '06:00')->call('save')->assertSet('error', __('billing.errors.times_order'))
            ->set('endDate', now(config('platform.timezone'))->addDay()->toDateString())->call('save')->assertSet('error', '');

        $entry = BillingEntry::query()->sole();
        $this->assertSame('22:00', $entry->started_at?->format('H:i'));
        $this->assertTrue($entry->ended_at?->gt($entry->started_at));

        $grid = BillingClient::factory()->form(BillingFormType::LevelSchedule)->create();
        $urgent = $grid->interventionTypes()->create(['label' => 'Urgent']);
        Livewire::actingAs($this->technician)->test(MyEntries::class)->call('chooseClient', $grid->id)
            ->set('ticket', 'G-1')->set('alone', true)->set('comment', 'x')->set('typeId', (string) $urgent->id)
            ->call('save')->assertSet('error', __('billing.errors.select_level'))
            ->set('level', '2')->call('save')->assertSet('error', __('billing.errors.select_schedule'))
            ->set('schedule', BillingSchedule::OffHours->value)->call('save')->assertSet('error', '');
    }

    public function test_a_technician_only_edits_and_deletes_their_own_entries_and_sees_reminders(): void
    {
        $theirs = BillingEntry::factory()->create(['billing_client_id' => $this->client->id, 'user_id' => $this->partner->id, 'ticket' => 'A999999', 'is_alone' => false, 'work_date' => now(config('platform.timezone'))->toDateString()]);
        $theirs->partners()->attach($this->technician->id);

        $this->form()
            ->assertSee(__('billing.reminders.title', ['count' => 1]))
            ->call('edit', $theirs->id)->assertNotFound();

        Livewire::actingAs($this->technician)->test(MyEntries::class)->call('delete', $theirs->id)->assertNotFound();
        $this->assertNotSoftDeleted($theirs);

        $mine = BillingEntry::factory()->create(['billing_client_id' => $this->client->id, 'user_id' => $this->technician->id]);
        Livewire::actingAs($this->technician)->test(MyEntries::class)->call('delete', $mine->id);
        $this->assertSoftDeleted($mine);
        $this->assertSame($this->technician->id, $mine->refresh()->deleted_by);
    }

    public function test_photos_are_shown_to_the_crew_and_managers_only(): void
    {
        $this->form()->set('ticket', 'A000002')->set('typeId', (string) $this->client->interventionTypes()->value('id'))
            ->set('partnerToAdd', (string) $this->partner->id)->call('addPartner')->set('comment', 'x')
            ->set('incoming.none', [UploadedFile::fake()->image('p.jpg', 400, 300)])->call('save')->assertSet('error', '');
        $photo = BillingEntry::query()->sole()->photos()->sole();

        $this->actingAs($this->partner)->get(route('billing.photos', [$photo->id, 'thumb']))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('billing.photos', [$photo->id, 'full']))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('billing.photos', [$photo->id, 'full']))->assertNotFound();
    }
}
