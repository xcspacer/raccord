<?php

namespace Tests\Feature\ExpenseReports;

use App\Enums\ExpenseLineStatus;
use App\Enums\ExpenseNature;
use App\Enums\Feature;
use App\Enums\Role;
use App\Expenses\ExpenseTotals;
use App\Expenses\PhotoHash;
use App\Jobs\ReadReceipt;
use App\Livewire\ExpenseReports\Editor;
use App\Livewire\TechnicianSpace\Space;
use App\Models\ExpenseLine;
use App\Models\ExpenseReport;
use App\Models\RoleFeatureOverride;
use App\Models\StoredFile;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use App\Support\Money;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class ExpenseReportTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.url' => 'https://anthropic.test/v1/messages']);
        $this->seed([RoleSeeder::class, PublicHolidaySeeder::class]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Jane Doe']);
    }

    private function line(User $user, array $attributes = []): ExpenseLine
    {
        return ExpenseLine::query()->create($attributes + ['user_id' => $user->id, 'nature' => ExpenseNature::Other, 'status' => ExpenseLineStatus::Manual]);
    }

    private function storedImage(int $seed, int $width = 400): StoredFile
    {
        $path = "files/ticket-{$seed}.jpg";
        $image = imagecreatetruecolor($width, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 40 * $seed, 20 * $seed, 200 - 20 * $seed));
        ob_start();
        imagejpeg($image);
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return StoredFile::query()->create(['sha256' => hash('sha256', $path), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 10]);
    }

    public function test_amounts_are_parsed_and_summed_in_cents_with_the_recap_groups(): void
    {
        $this->assertSame(1250, Money::parse('12,5'));
        $this->assertSame(8079, Money::parse(' 80.79 '));
        $this->assertNull(Money::parse('abc'));
        $this->assertNull(Money::parse('-3'));
        $this->assertNull(Money::parse('1.234'));

        $lines = collect([
            $this->line($this->technician, ['nature' => ExpenseNature::Toll, 'amount_cents' => 1010]),
            $this->line($this->technician, ['nature' => ExpenseNature::Parking, 'amount_cents' => 290]),
            $this->line($this->technician, ['nature' => ExpenseNature::Fuel, 'amount_cents' => 5000]),
        ]);

        $totals = app(ExpenseTotals::class)->for($lines);

        $this->assertSame(6300, $totals['total']);
        $this->assertSame(1300, $totals['recap']['transport']);
        $this->assertSame(5000, $totals['recap']['fuel']);
        $this->assertSame(0, $totals['recap']['restaurant']);
    }

    public function test_photos_create_lines_being_read_and_the_limit_is_enforced(): void
    {
        Bus::fake();

        $component = Livewire::actingAs($this->technician)->test(Editor::class)
            ->set('photos', [UploadedFile::fake()->image('a.jpg', 600, 800), UploadedFile::fake()->image('b.jpg', 640, 800)])->assertHasNoErrors();

        $this->assertSame(2, ExpenseLine::query()->where('status', ExpenseLineStatus::Reading)->count());
        Bus::assertDispatchedAfterResponse(ReadReceipt::class, 2);

        foreach (range(1, 30) as $i) {
            $this->line($this->technician);
        }

        $component->set('photos', [UploadedFile::fake()->image('c.jpg')])->assertHasErrors('photos');
        $this->assertSame(32, ExpenseLine::query()->count());

        Livewire::actingAs($this->technician)->test(Editor::class)->call('addLine')->assertHasErrors('photos');
    }

    public function test_the_ai_fills_the_line_and_normalises_the_answer(): void
    {
        Http::fake(['anthropic.test/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => '{"date":"2026-10-12","amount_incl_tax":80.79,"nature":"fuel","merchant":"TotalEnergies","confidence":"high"}']]])
            ->push(['content' => [['type' => 'text', 'text' => '{"date":"2026-02-31","amount_incl_tax":"abc","nature":"spaceship","merchant":"","confidence":"high"}']]])]);
        $first = $this->line($this->technician, ['status' => ExpenseLineStatus::Reading, 'stored_file_id' => $this->storedImage(1)->id]);
        $second = $this->line($this->technician, ['status' => ExpenseLineStatus::Reading, 'stored_file_id' => $this->storedImage(2)->id]);

        Bus::dispatchSync(new ReadReceipt($this->technician->id, $first->id));
        Bus::dispatchSync(new ReadReceipt($this->technician->id, $second->id));

        $first->refresh();
        $this->assertSame('2026-10-12', $first->spent_on->toDateString());
        $this->assertSame(8079, $first->amount_cents);
        $this->assertSame(ExpenseNature::Fuel, $first->nature);
        $this->assertSame('TotalEnergies', $first->merchant);
        $this->assertSame(ExpenseLineStatus::Ok, $first->status);

        $second->refresh();
        $this->assertNull($second->spent_on);
        $this->assertSame(0, $second->amount_cents);
        $this->assertSame(ExpenseNature::Other, $second->nature);
        $this->assertSame(ExpenseLineStatus::Check, $second->status);
    }

    public function test_without_ai_the_line_is_left_for_manual_entry_with_the_reason(): void
    {
        config(['services.anthropic.key' => '']);
        Http::fake();
        $line = $this->line($this->technician, ['status' => ExpenseLineStatus::Reading, 'stored_file_id' => $this->storedImage(1)->id]);

        Bus::dispatchSync(new ReadReceipt($this->technician->id, $line->id));

        Http::assertNothingSent();
        $this->assertSame(ExpenseLineStatus::Manual, $line->refresh()->status);
        $this->assertSame('not_configured', $line->message);
    }

    public function test_a_line_is_edited_validated_and_only_by_its_owner(): void
    {
        $line = $this->line($this->technician);
        $other = $this->line(User::factory()->withRole(Role::Technician)->create());

        $component = Livewire::actingAs($this->technician)->test(Editor::class)
            ->set("edits.{$line->id}.amount", '12,50')->set("edits.{$line->id}.nature", 'toll')->set("edits.{$line->id}.date", '2026-10-05')->set("edits.{$line->id}.comment", 'A6');

        $line->refresh();
        $this->assertSame(1250, $line->amount_cents);
        $this->assertSame(ExpenseNature::Toll, $line->nature);
        $this->assertSame('A6', $line->comment);

        $component->set("edits.{$line->id}.amount", 'abc')->assertHasErrors();
        $component->set("edits.{$line->id}.nature", 'rocket')->assertHasErrors();
        $this->assertSame(1250, $line->refresh()->amount_cents);

        Livewire::actingAs($this->technician)->test(Editor::class)->set("edits.{$other->id}.comment", 'Hacked')->assertNotFound();
        Livewire::actingAs($this->technician)->test(Editor::class)->call('removeLine', $other->id);
        $this->assertNull($other->refresh()->comment);
        $this->assertDatabaseHas('expense_lines', ['id' => $other->id]);
    }

    public function test_duplicates_are_detected_by_photo_and_by_content_and_can_be_dismissed(): void
    {
        $file = $this->storedImage(3);
        $hash = app(PhotoHash::class)->of($file);
        $this->assertSame(64, strlen((string) $hash));
        $this->assertSame(0, app(PhotoHash::class)->distance((string) $hash, (string) $hash));

        $a = $this->line($this->technician, ['stored_file_id' => $file->id, 'photo_hash' => $hash]);
        $b = $this->line($this->technician, ['stored_file_id' => $file->id, 'photo_hash' => $hash]);
        $c = $this->line($this->technician, ['spent_on' => '2026-10-02', 'amount_cents' => 900, 'nature' => ExpenseNature::Restaurant]);
        $d = $this->line($this->technician, ['spent_on' => '2026-10-02', 'amount_cents' => 900, 'nature' => ExpenseNature::Restaurant]);

        $component = Livewire::actingAs($this->technician)->test(Editor::class)->assertSee(__('expense_reports.duplicate.same_photo'))->assertSee(__('expense_reports.duplicate.same_expense'));

        $component->call('confirmNotDuplicate', $a->id);
        $this->assertTrue($a->refresh()->duplicate_confirmed);
        $this->assertTrue($b->refresh()->duplicate_confirmed);
        $this->assertFalse($c->refresh()->duplicate_confirmed);
        $this->assertFalse($d->refresh()->duplicate_confirmed);
    }

    public function test_the_desktop_download_button_sends_the_pdf_and_the_phone_uses_the_person_and_the_current_month(): void
    {
        $this->travelTo('2026-11-05 10:00');
        $this->line($this->technician, ['spent_on' => '2026-10-02', 'amount_cents' => 1250, 'nature' => ExpenseNature::Fuel]);

        Livewire::actingAs($this->technician)->test(Editor::class)->set('name', 'Jane D.')->set('month', '2026-10')
            ->call('generate', false, 'download')->assertHasNoErrors()->assertFileDownloaded();
        $this->assertSame('2026-10', ExpenseReport::query()->sole()->month);

        $this->line($this->technician, ['spent_on' => '2026-11-02', 'amount_cents' => 900, 'nature' => ExpenseNature::Fuel]);
        Livewire::actingAs($this->technician)->test(Editor::class)->set('name', 'Someone else')->set('month', '2026-09')
            ->call('generate', false, 'phone')->assertHasNoErrors()->assertNoFileDownloaded()
            ->assertSee(__('expense_reports.ready_hint'));

        $report = ExpenseReport::query()->latest('id')->firstOrFail();
        $this->assertSame(['Jane Doe', '2026-11'], [$report->name, $report->month]);
    }

    public function test_generating_checks_the_draft_asks_for_confirmation_then_archives_the_pdf(): void
    {
        $component = Livewire::actingAs($this->technician)->test(Editor::class)->call('generate', false)->assertHasErrors('generate');

        $file = $this->storedImage(2);
        $complete = $this->line($this->technician, ['spent_on' => '2026-10-02', 'amount_cents' => 1250, 'nature' => ExpenseNature::Fuel, 'comment' => 'Diesel', 'stored_file_id' => $file->id]);
        $this->line($this->technician, ['status' => ExpenseLineStatus::Reading, 'amount_cents' => 100]);

        $component->call('generate', false)->assertHasErrors('generate');
        ExpenseLine::query()->where('status', ExpenseLineStatus::Reading)->delete();

        $this->line($this->technician, ['comment' => 'No date nor amount']);
        $component = Livewire::actingAs($this->technician)->test(Editor::class)->set('name', 'Jane D.')->set('month', '2026-10')->call('generate', false);

        $this->assertNotEmpty($component->get('warnings'));
        $this->assertDatabaseCount('expense_reports', 0);

        $component->call('generate', true)->assertHasNoErrors();

        $report = ExpenseReport::query()->sole();
        $this->assertSame('2026-10', $report->month);
        $this->assertSame('Jane D.', $report->name);
        $this->assertSame(1250, $report->total_cents);
        $this->assertSame(2, $report->lines_count);
        $this->assertSame(0, ExpenseLine::query()->count());
        Storage::disk('local')->assertExists($report->file->path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($report->file->path));
        $this->assertNotNull($complete->id);

        $component->assertSee(__('expense_reports.done_title'));
    }

    public function test_the_pdf_download_is_for_the_owner_and_managers_only(): void
    {
        $report = $this->reportFor($this->technician);
        $url = route('expense-reports.download', $report);

        $this->actingAs($this->technician)->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_the_pdf_file_name_starts_with_the_platform_company_name(): void
    {
        $settings = app(CompanySettings::class);
        $settings->name = 'Acme Fibre';
        $settings->save();

        $disposition = (string) $this->actingAs($this->technician)->get(route('expense-reports.download', $this->reportFor($this->technician, 'Jane D.')))
            ->assertOk()->headers->get('Content-Disposition');

        $this->assertStringContainsString('Acme Fibre - Expense report - Jane D. - OCTOBER 2026.pdf', $disposition);
    }

    public function test_a_report_with_a_stored_file_name_downloads_under_that_name(): void
    {
        $report = $this->reportFor($this->technician, 'Jane D.');
        $report->update(['file_name' => 'Acme Fibre - Note de Frais - Hélène D. - OCTOBRE 2026.pdf']);

        $disposition = (string) $this->actingAs($this->technician)->get(route('expense-reports.download', $report))
            ->assertOk()->headers->get('Content-Disposition');

        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('Acme Fibre - Note de Frais - Hélène D. - OCTOBRE 2026.pdf'), $disposition);
    }

    public function test_ticket_photos_are_visible_to_their_owner_and_managers_only(): void
    {
        $line = $this->line($this->technician, ['stored_file_id' => $this->storedImage(1)->id]);
        $url = route('vehicle-images.show', ['type' => 'expense', 'id' => $line->id, 'variant' => 'full']);

        $this->actingAs($this->technician)->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($url)->assertNotFound();
    }

    public function test_ticket_photos_follow_the_expense_reports_access_and_module(): void
    {
        $line = $this->line($this->technician, ['stored_file_id' => $this->storedImage(1)->id]);
        $url = route('vehicle-images.show', ['type' => 'expense', 'id' => $line->id, 'variant' => 'thumb']);

        RoleFeatureOverride::query()->create(['feature' => Feature::ExpenseReports, 'role_id' => RoleModel::findByName(Role::Technician->value)->id, 'is_enabled' => false]);
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->technician)->get($url)->assertForbidden();

        RoleFeatureOverride::query()->delete();
        app(FeatureAccess::class)->forgetOverrides();
        $this->actingAs($this->technician)->get($url)->assertOk();

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['expense_reports']));
        $modules->save();
        $this->actingAs($this->technician)->get($url)->assertNotFound();
    }

    public function test_the_history_lists_only_the_own_reports_in_mon_espace(): void
    {
        $this->reportFor($this->technician, 'Jane Doe', 12345);
        $this->reportFor(User::factory()->withRole(Role::Technician)->create(), 'Somebody Else', 99999);

        Livewire::actingAs($this->technician)->withQueryParams(['tab' => 'expense-reports'])->test(Space::class)
            ->assertSee(Money::format(12345))->assertDontSee(Money::format(99999));
    }

    public function test_the_module_switch_and_the_feature_protect_the_page_and_the_download(): void
    {
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();
        $this->actingAs($supervisor)->get(route('expense-reports'))->assertOk();

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['expense_reports']));
        $modules->save();

        $this->actingAs($supervisor)->get(route('expense-reports'))->assertNotFound();
        $this->actingAs($this->technician)->get(route('expense-reports.download', $this->reportFor($this->technician)))->assertNotFound();
    }

    public function test_the_recipient_comes_from_the_platform_settings(): void
    {
        $company = app(CompanySettings::class);
        $company->expense_report_email = 'accounting@example.test';
        $company->save();

        $this->line($this->technician, ['spent_on' => '2026-10-02', 'amount_cents' => 500]);

        Livewire::actingAs($this->technician)->test(Editor::class)->call('generate', true)->assertSee('accounting@example.test');
    }

    private function reportFor(User $user, string $name = 'Jane Doe', int $total = 1000): ExpenseReport
    {
        $path = 'files/report-'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $file = StoredFile::query()->create(['sha256' => hash('sha256', $path), 'path' => $path, 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 13]);

        return ExpenseReport::query()->create(['user_id' => $user->id, 'month' => '2026-10', 'name' => $name, 'total_cents' => $total, 'lines_count' => 3, 'stored_file_id' => $file->id, 'created_at' => now()]);
    }
}
