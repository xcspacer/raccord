<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingExpenseStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Billing\BillingPage;
use App\Livewire\Billing\ExpenseReviews;
use App\Livewire\Billing\MyExpenses;
use App\Models\BillingExpense;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $admin;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        RoleFeatureOverride::query()->create(['feature' => Feature::Billing, 'role_id' => RoleModel::findByName(Role::Technician->value)->id, 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->technician = User::factory()->withRole(Role::Technician)->create();
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create();
    }

    private function pending(User $owner): BillingExpense
    {
        return BillingExpense::query()->create(['user_id' => $owner->id, 'spent_on' => '2026-10-05', 'category' => 'toll', 'amount' => 12.4, 'status' => BillingExpenseStatus::Pending]);
    }

    public function test_an_expense_is_checked_in_order_and_needs_a_receipt(): void
    {
        $component = Livewire::actingAs($this->technician)->test(MyExpenses::class)
            ->set('category', 'other')->call('save')->assertSet('error', __('billing.expenses.errors.other'))
            ->set('categoryOther', 'Supplies')->set('amount', '0')->call('save')->assertSet('error', __('billing.expenses.errors.amount'))
            ->set('category', 'fuel')->set('amount', '62,40')->call('save')->assertSet('error', __('billing.expenses.errors.mileage'))
            ->set('mileage', '340')->call('save')->assertSet('error', __('billing.expenses.errors.photo'))
            ->set('incoming.receipts', [UploadedFile::fake()->image('r.jpg', 600, 800)])
            ->call('save')->assertSet('error', '');

        $expense = BillingExpense::query()->with('photos')->sole();
        $this->assertSame(62.4, (float) $expense->amount);
        $this->assertSame(340.0, (float) $expense->mileage_km);
        $this->assertNull($expense->category_other);
        $this->assertSame(BillingExpenseStatus::Pending, $expense->status);
        $this->assertCount(1, $expense->photos);
        $component->call('edit', $expense->id)->assertSet('amount', '62.4');

        $this->actingAs($this->technician)->get(route('billing.expense-photos', [$expense->photos->first()->id, 'thumb']))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('billing.expense-photos', [$expense->photos->first()->id, 'thumb']))->assertNotFound();
    }

    public function test_a_reviewed_expense_can_no_longer_be_changed_or_deleted_by_its_owner(): void
    {
        $expense = $this->pending($this->technician);
        $expense->update(['status' => BillingExpenseStatus::Approved]);

        Livewire::actingAs($this->technician)->test(MyExpenses::class)->call('edit', $expense->id)->assertForbidden();
        Livewire::actingAs($this->technician)->test(MyExpenses::class)->call('delete', $expense->id)->assertForbidden();
        Livewire::actingAs($this->technician)->test(MyExpenses::class)->call('delete', $this->pending(User::factory()->create())->id)->assertNotFound();
        $this->assertNotSoftDeleted($expense);
    }

    public function test_only_administrators_approve_or_reject_with_a_reason(): void
    {
        $first = $this->pending($this->technician);
        $second = $this->pending($this->technician);

        Livewire::actingAs($this->supervisor)->test(ExpenseReviews::class)->assertOk()->call('approve', $first->id)->assertForbidden();
        Livewire::actingAs($this->technician)->test(ExpenseReviews::class)->assertForbidden();

        $component = Livewire::actingAs($this->admin)->test(ExpenseReviews::class)
            ->call('approve', $first->id)
            ->call('startReject', $second->id)->call('confirmReject')->assertHasErrors('reason')
            ->set('reason', 'Receipt unreadable')->call('confirmReject')->assertHasNoErrors();

        $this->assertSame(BillingExpenseStatus::Approved, $first->refresh()->status);
        $this->assertSame($this->admin->id, $first->reviewed_by);
        $this->assertSame(BillingExpenseStatus::Rejected, $second->refresh()->status);
        $this->assertSame('Receipt unreadable', $second->rejection_reason);
        $this->assertNotNull($second->reviewed_at);

        $component->call('approve', $second->id)->assertStatus(409);
    }

    public function test_the_pending_view_shows_every_waiting_expense_whatever_the_filters(): void
    {
        $old = BillingExpense::query()->create(['user_id' => $this->technician->id, 'spent_on' => '2025-01-10', 'category' => 'meal', 'amount' => 15, 'status' => BillingExpenseStatus::Pending]);

        Livewire::actingAs($this->admin)->test(ExpenseReviews::class)
            ->set('month', '2026-10')->assertDontSee('2025-01-10')
            ->call('showPending', true)->assertSee('2025-01-10')
            ->set('status', 'approved')->assertSet('pendingOnly', false);

        $this->assertModelExists($old);
    }

    public function test_tabs_follow_the_role(): void
    {
        Livewire::actingAs($this->supervisor)->test(BillingPage::class)->assertSet('tab', 'dashboard')->call('showTab', 'my-expenses')->assertSet('tab', 'dashboard')->call('showTab', 'reviews')->assertSet('tab', 'reviews');
        Livewire::actingAs($this->admin)->test(BillingPage::class)->call('showTab', 'my-expenses')->assertSet('tab', 'my-expenses');
    }
}
