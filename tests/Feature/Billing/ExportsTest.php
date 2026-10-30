<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingRates;
use App\Billing\Export\ExportSections;
use App\Enums\BillingEntryType;
use App\Enums\BillingExpenseStatus;
use App\Enums\BillingFormType;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Billing\Dashboard;
use App\Livewire\Billing\ExpenseReviews;
use App\Livewire\Billing\MyExpenses;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingExpense;
use App\Models\BillingInterventionType;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\FeatureAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;
use ZipArchive;

class ExportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $alice;

    private User $bruno;

    private BillingClient $client;

    private BillingInterventionType $repair;

    private BillingInterventionType $declared;

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        RoleFeatureOverride::query()->create(['feature' => Feature::Billing, 'role_id' => RoleModel::findByName(Role::Technician->value)->id, 'is_enabled' => true]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create();
        $this->alice = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno']);
        $this->client = BillingClient::factory()->create(['sheet_label' => 'ALPHA', 'has_works' => true, 'sort_order' => 1]);
        $this->repair = $this->client->interventionTypes()->create(['label' => 'Repair', 'sort_order' => 1]);
        $this->declared = $this->client->interventionTypes()->create(['label' => 'Declared', 'is_billable' => false, 'sort_order' => 2]);
        app(BillingRates::class)->set($this->client, BillingRates::typeKey($this->repair), 100, null);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->temporary, is_file(...)));

        parent::tearDown();
    }

    private function entry(User $user, string $ticket, string $day, ?BillingInterventionType $type, ?User $partner = null): BillingEntry
    {
        $entry = BillingEntry::factory()->create([
            'billing_client_id' => $this->client->id, 'user_id' => $user->id, 'ticket' => $ticket, 'work_date' => $day,
            'billing_intervention_type_id' => $type?->id, 'is_alone' => $partner === null, 'comment' => 'Done on '.$day,
            'type' => $type === null ? BillingEntryType::Works : BillingEntryType::Breakdown,
        ]);
        $partner?->id === null || $entry->partners()->attach($partner->id);

        return $entry;
    }

    /**
     * Alice and Bruno share ticket A1, Alice declares it twice; on B2 they name different crews (not billed); both
     * worked on the works ticket W1.
     */
    private function month(): void
    {
        $this->entry($this->alice, 'A1', '2026-10-03', $this->repair, $this->bruno);
        $this->entry($this->bruno, 'A1', '2026-10-03', $this->repair, $this->alice);
        $this->entry($this->alice, 'A1', '2026-10-10', $this->repair);
        $this->entry($this->bruno, 'B2', '2026-10-04', $this->declared);
        $this->entry($this->alice, 'B2', '2026-10-04', $this->declared, $this->bruno);

        $cable = BillingArticle::query()->create(['code' => 'A-1', 'label' => 'Cable', 'unit' => 'ML', 'sort_order' => 1]);
        $box = BillingArticle::query()->create(['code' => 'A-2', 'label' => 'Box', 'unit' => 'UNI', 'sort_order' => 2]);
        app(BillingRates::class)->set(null, BillingRates::articleKey($cable), 10, null);
        app(BillingRates::class)->set(null, BillingRates::articleKey($box), 5.5, null);
        $this->entry($this->alice, 'W1', '2026-10-06', null)->lines()->createMany([['billing_article_id' => $cable->id, 'quantity' => 2], ['billing_article_id' => $box->id, 'quantity' => 1]]);
        $this->entry($this->bruno, 'W1', '2026-10-05', null)->lines()->create(['billing_article_id' => $cable->id, 'quantity' => 1]);
    }

    private function file(string $content, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'billing').'.'.$extension;
        file_put_contents($path, $content);
        $this->temporary[] = $path;

        return $path;
    }

    private function book(string $content): Spreadsheet
    {
        return IOFactory::load($this->file($content, 'xlsx'));
    }

    /**
     * @return array<string, string> file contents by name
     */
    private function unzip(TestResponse $response): array
    {
        $zip = new ZipArchive;
        $zip->open($this->file($response->streamedContent(), 'zip'));
        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $files[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();

        return $files;
    }

    public function test_only_administrators_export_the_entries(): void
    {
        foreach ([$this->supervisor, $this->alice] as $user) {
            $this->actingAs($user)->get(route('billing.exports.global', ['month' => '2026-10']))->assertForbidden();
            $this->actingAs($user)->get(route('billing.exports.technicians', ['month' => '2026-10']))->assertForbidden();
        }

        $this->actingAs($this->admin)->get(route('billing.exports.global', ['month' => 'October']))->assertSessionHasErrors('month');

        Livewire::actingAs($this->admin)->test(Dashboard::class)->assertSee(__('billing.dashboard.export_global'))->assertSee(__('billing.dashboard.export_technicians'));
        Livewire::actingAs($this->supervisor)->test(Dashboard::class)->assertDontSee(__('billing.dashboard.export_global'));
    }

    public function test_the_global_export_has_a_sheet_per_client_form_with_checks_amounts_and_the_price_list(): void
    {
        $this->month();

        $response = $this->actingAs($this->admin)->get(route('billing.exports.global', ['month' => '2026-10']));
        $response->assertOk()->assertDownload(__('billing.export.files.global', ['month' => '2026-10']).'.xlsx');
        $book = $this->book($response->streamedContent());

        $this->assertSame(['ALPHA', __('billing.export.sheets.works', ['label' => 'ALPHA'])], $book->getSheetNames());

        $rows = $book->getSheet(0)->toArray(null, false, false);
        $this->assertSame([
            __('billing.export.columns.date'), __('billing.export.columns.technician'), __('billing.export.columns.partners'), __('billing.export.columns.people'),
            __('billing.export.columns.ticket'), 'Repair', 'Declared', __('billing.export.columns.duplicate'), __('billing.export.columns.crew_mismatch'),
            __('billing.export.columns.amount'), __('billing.export.columns.share'), __('billing.export.columns.comments'),
        ], $rows[0]);
        $this->assertSame(['2026-10-10', 'Alice', null, 1, 'A1', '1', null, __('billing.export.values.occurrences', ['count' => 2]), __('billing.export.values.no'), 100.0, 100.0, 'Done on 2026-10-10'], $rows[1]);
        $this->assertSame(['2026-10-04', 'Bruno', null, 2, 'B2', null, __('billing.export.values.not_billed'), __('billing.export.values.no'), __('billing.export.values.yes'), 0.0, 0.0, 'Done on 2026-10-04'], $rows[2]);
        $this->assertSame(['2026-10-03', 'Bruno', 'Alice', 2, 'A1', '1', null, __('billing.export.values.no'), __('billing.export.values.no'), 100.0, 50.0, 'Done on 2026-10-03'], $rows[5]);
        $this->assertSame('FFE9A8', $book->getSheet(0)->getStyle('A3')->getFill()->getStartColor()->getRGB());
        $this->assertNotSame('FFE9A8', $book->getSheet(0)->getStyle('A2')->getFill()->getStartColor()->getRGB());

        $works = $book->getSheet(1)->toArray(null, false, false);
        $this->assertSame([__('billing.export.works.number'), 'W1'], [$works[0][3], $works[0][4]]);
        $this->assertSame('2026-10-05', $works[1][4]);
        $this->assertSame(['A-1', 'Cable', 'ML', 10.0, 3.0, 30.0], $works[3]);
        $this->assertSame(['A-2', 'Box', 'UNI', 5.5, 1.0, 5.5], $works[4]);
        $this->assertSame([null, __('billing.export.works.grand_total'), null, null, 35.5, null], $works[6]);
        $this->assertArrayHasKey('E1:F1', $book->getSheet(1)->getMergeCells());
    }

    public function test_the_exports_of_several_clients_load_everything_up_front(): void
    {
        $this->month();
        $other = BillingClient::factory()->create(['sheet_label' => 'BETA', 'sort_order' => 2]);
        $install = $other->interventionTypes()->create(['label' => 'Install', 'sort_order' => 1]);
        $other->interventionTypes()->create(['label' => 'Survey', 'sort_order' => 2]);
        BillingEntry::factory()->create(['billing_client_id' => $other->id, 'user_id' => $this->alice->id, 'ticket' => 'C3', 'work_date' => '2026-10-07', 'billing_intervention_type_id' => $install->id, 'type' => BillingEntryType::Breakdown]);
        BillingEntry::factory()->create(['billing_client_id' => $other->id, 'user_id' => $this->bruno->id, 'ticket' => 'C4', 'work_date' => '2026-10-08', 'billing_intervention_type_id' => $install->id, 'type' => BillingEntryType::Breakdown]);

        $global = $this->actingAs($this->admin)->get(route('billing.exports.global', ['month' => '2026-10']))->assertOk();
        $this->assertSame(['ALPHA', 'BETA', __('billing.export.sheets.works', ['label' => 'ALPHA'])], $this->book($global->streamedContent())->getSheetNames());

        $technicians = $this->actingAs($this->admin)->get(route('billing.exports.technicians', ['month' => '2026-10']))->assertOk();
        $this->assertCount(2, $this->unzip($technicians));
    }

    public function test_sheets_take_the_names_given_to_the_client_and_its_parts(): void
    {
        $this->month();
        $pair = BillingClient::factory()->form(BillingFormType::DayRatePair)->create(['sheet_label' => 'PAIR', 'sort_order' => 2]);
        $survey = $pair->interventionTypes()->create(['label' => 'Survey of boxes (day)', 'sheet_label' => 'PAIR SURVEY', 'sort_order' => 1]);
        $rework = $pair->interventionTypes()->create(['label' => 'Rework', 'is_billable' => false, 'sort_order' => 2]);

        foreach ([$survey, $rework] as $type) {
            BillingEntry::factory()->create(['billing_client_id' => $pair->id, 'user_id' => $this->alice->id, 'work_date' => '2026-10-07', 'billing_intervention_type_id' => $type->id, 'type' => BillingEntryType::Works]);
        }

        $sheets = fn () => $this->book($this->actingAs($this->admin)->get(route('billing.exports.global', ['month' => '2026-10']))->streamedContent())->getSheetNames();

        $this->assertSame(['ALPHA', 'PAIR SURVEY', 'PAIR REWORK', __('billing.export.sheets.works', ['label' => 'ALPHA'])], $sheets());

        $this->client->update(['sheet_label' => 'ALPHA BREAKDOWN', 'works_sheet_label' => 'ALPHA WORKS']);

        $this->assertSame(['ALPHA BREAKDOWN', 'PAIR SURVEY', 'PAIR REWORK', 'ALPHA WORKS'], $sheets());
    }

    public function test_an_empty_month_still_gives_a_workbook(): void
    {
        $book = $this->book($this->actingAs($this->admin)->get(route('billing.exports.global', ['month' => '2026-09']))->streamedContent());

        $this->assertSame([__('billing.export.sheets.empty')], $book->getSheetNames());
        $this->assertSame(__('billing.export.empty_period'), $book->getSheet(0)->getCell('A1')->getValue());
    }

    public function test_each_technician_gets_their_own_entries_with_an_aligned_footer(): void
    {
        $this->month();
        $label = ExportSections::monthLabel('2026-10');

        $response = $this->actingAs($this->admin)->get(route('billing.exports.technicians', ['month' => '2026-10']));
        $response->assertOk()->assertDownload(__('billing.export.files.technicians', ['month' => $label]).'.zip');
        $files = $this->unzip($response);

        $this->assertSame([
            __('billing.export.files.technician', ['month' => $label, 'name' => 'Alice']).'.xlsx',
            __('billing.export.files.technician', ['month' => $label, 'name' => 'Bruno']).'.xlsx',
        ], array_keys($files));

        $book = $this->book($files[array_key_first($files)]);
        $rows = $book->getSheet(0)->toArray(null, false, false);
        $this->assertSame(['03/10/2026', 'Alice/Bruno', 'A1', 0.5, null, 'Done on 2026-10-03'], $rows[1]);
        $this->assertSame(['04/10/2026', 'Alice/Bruno', 'B2', null, __('billing.export.values.not_billed'), 'Done on 2026-10-04'], $rows[2]);
        $this->assertSame([__('billing.export.footers.breakdowns'), 'Alice', null, 1.0, null, null], $rows[6]);
        $this->assertSame([__('billing.export.footers.ticket_count'), 3, null, null, null, null], $rows[7]);

        $works = $book->getSheet(1)->toArray(null, false, false);
        $this->assertSame([__('billing.export.works.ticket_number'), 'W1'], $works[0]);
        $this->assertSame([__('billing.export.columns.crew'), 'Alice'], $works[2]);
    }

    private function expense(User $owner, BillingExpenseStatus $status, string $day = '2026-10-05'): BillingExpense
    {
        return BillingExpense::query()->create(['user_id' => $owner->id, 'spent_on' => $day, 'category' => 'fuel', 'amount' => 40.255, 'mileage_km' => 120, 'status' => $status, 'reviewed_at' => $status === BillingExpenseStatus::Approved ? '2026-10-07 10:00:00' : null]);
    }

    public function test_one_expense_is_exported_with_its_receipts_by_whoever_may_see_it(): void
    {
        $plain = $this->expense($this->alice, BillingExpenseStatus::Approved);

        $response = $this->actingAs($this->alice)->get(route('billing.expenses.export', $plain->id));
        $response->assertOk()->assertDownload(__('billing.export.files.expense', ['name' => 'Alice', 'date' => '2026-10-05']).'.xlsx');
        $rows = $this->book($response->streamedContent())->getSheet(0)->toArray(null, false, false);
        $this->assertSame(['Alice', '05/10/2026', __('enums.billing_expense_categories.fuel'), 120.0, 40.26, __('enums.billing_expense_statuses.approved'), '07/10/2026', __('billing.export.expenses.none'), null, null], $rows[1]);

        $this->actingAs($this->bruno)->get(route('billing.expenses.export', $plain->id))->assertNotFound();
        $this->actingAs($this->supervisor)->get(route('billing.expenses.export', $plain->id))->assertOk();

        Livewire::actingAs($this->bruno)->test(MyExpenses::class)
            ->set('category', 'toll')->set('amount', '8')->set('incoming.receipts', [UploadedFile::fake()->image('r.jpg', 600, 800)])->call('save')->assertSet('error', '');
        $withReceipt = BillingExpense::query()->where('user_id', $this->bruno->id)->sole();

        $response = $this->actingAs($this->bruno)->get(route('billing.expenses.export', $withReceipt->id));
        $base = __('billing.export.files.expense', ['name' => 'Bruno', 'date' => $withReceipt->spent_on->toDateString()]);
        $response->assertOk()->assertDownload($base.'.zip');
        $this->assertSame([$base.'.xlsx', __('billing.export.expenses.attachment', ['number' => 1]).'.jpg'], array_keys($this->unzip($response)));
    }

    public function test_the_months_expenses_are_exported_per_person_and_scope_by_managers(): void
    {
        $this->expense($this->alice, BillingExpenseStatus::Approved);
        $this->expense($this->alice, BillingExpenseStatus::Pending, '2026-10-02');
        $this->expense($this->bruno, BillingExpenseStatus::Pending);
        $label = ExportSections::monthLabel('2026-10');

        $this->actingAs($this->alice)->get(route('billing.exports.expenses', ['month' => '2026-10']))->assertForbidden();

        $response = $this->actingAs($this->supervisor)->get(route('billing.exports.expenses', ['month' => '2026-10']));
        $response->assertOk()->assertDownload(__('billing.export.files.expenses_zip', ['month' => $label]).'.zip');
        $files = $this->unzip($response);
        $this->assertCount(2, $files);

        $rows = $this->book($files[__('billing.export.files.expenses', ['month' => $label, 'name' => 'Alice']).'.xlsx'])->getSheet(0)->toArray(null, false, false);
        $this->assertSame(['02/10/2026', '05/10/2026'], [$rows[1][0], $rows[2][0]]);
        $this->assertSame([null, __('billing.export.expenses.approved_total'), null, 40.26], array_slice($rows[4], 0, 4));

        $response = $this->actingAs($this->admin)->get(route('billing.exports.expenses', ['month' => '2026-10', 'technician' => $this->alice->id, 'scope' => 'pending']));
        $response->assertOk()->assertDownload(__('billing.export.files.expenses', ['month' => $label, 'name' => 'Alice']).' - '.__('billing.export.expenses.scopes.pending').'.xlsx');

        $this->actingAs($this->admin)->get(route('billing.exports.expenses', ['month' => '2026-10', 'technician' => $this->bruno->id, 'scope' => 'approved']))->assertNotFound();

        Livewire::actingAs($this->supervisor)->test(ExpenseReviews::class)->set('month', '2026-10')
            ->assertSee(__('billing.expenses.export_all'))
            ->set('technician', (string) $this->bruno->id)
            ->assertSee(__('billing.expenses.export_person', ['name' => 'Bruno']))->assertSee(__('billing.expenses.export_pending'))->assertDontSee(__('billing.expenses.export_approved'))
            ->set('month', '2026-09')->assertDontSee(__('billing.expenses.export_person', ['name' => 'Bruno']));
    }
}
