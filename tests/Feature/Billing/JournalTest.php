<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingJournal;
use App\Billing\ReviewBillingExpense;
use App\Billing\SaveBillingEntry;
use App\Enums\BillingExpenseStatus;
use App\Enums\Role;
use App\Livewire\Billing\MyEntries;
use App\Livewire\Billing\RateSettings;
use App\Logs\ChangeJournal;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingExpense;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $event): array
    {
        return (array) Activity::query()->where('log_name', BillingJournal::LOG)->where('event', $event)->latest('id')->firstOrFail()->properties['record'];
    }

    public function test_entries_are_recorded_with_their_partners_and_lines_when_saved_and_deleted(): void
    {
        $client = BillingClient::factory()->create(['has_works' => true]);
        $article = BillingArticle::query()->create(['code' => 'A-1', 'label' => 'Cable', 'unit' => 'ML']);
        $partner = User::factory()->withRole(Role::Technician)->create();

        $entry = app(SaveBillingEntry::class)->handle($this->admin, $client, [
            'type' => 'works', 'date' => '2026-10-05', 'ticket' => 'W1', 'typeId' => null, 'level' => null, 'schedule' => null, 'startTime' => '', 'endTime' => '', 'endDate' => '',
            'operatorId' => null, 'alone' => false, 'partners' => [$partner->id], 'comment' => 'Done', 'lines' => [['article' => $article->id, 'quantity' => 2.5]],
        ], null);

        $saved = $this->record(BillingJournal::ENTRY_SAVED);
        $this->assertSame([$partner->id], $saved['partners']);
        $this->assertSame([['article' => $article->id, 'quantity' => 2.5]], $saved['lines']);
        $this->assertSame($this->admin->id, Activity::query()->where('event', BillingJournal::ENTRY_SAVED)->value('causer_id'));

        Livewire::actingAs($this->admin)->test(MyEntries::class)->call('delete', $entry->id);
        $this->assertSame('W1', $this->record(BillingJournal::ENTRY_DELETED)['ticket']);
        $this->assertSoftDeleted($entry);
    }

    public function test_reviews_and_settings_are_recorded_and_stay_out_of_the_planning_logs(): void
    {
        $expense = BillingExpense::query()->create(['user_id' => User::factory()->create()->id, 'spent_on' => '2026-10-05', 'category' => 'toll', 'amount' => 9, 'status' => BillingExpenseStatus::Pending]);
        app(ReviewBillingExpense::class)->reject($this->admin, $expense, 'No receipt');
        $this->assertSame('No receipt', $this->record(BillingJournal::EXPENSE_REJECTED)['rejection_reason']);

        Livewire::actingAs($this->admin)->test(RateSettings::class)->set('operatorName', 'North Carrier')->call('addOperator');
        $this->assertSame('North Carrier', $this->record(BillingJournal::OPERATOR_ADDED)['name']);

        $this->assertSame(0, app(ChangeJournal::class)->query()->where('log_name', BillingJournal::LOG)->count());
    }
}
