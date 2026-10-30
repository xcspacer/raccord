<?php

namespace Tests\Feature\ExpenseReports;

use App\Enums\ExpenseLineStatus;
use App\Enums\ExpenseNature;
use App\Enums\Role;
use App\Expenses\ExpenseReportBuilder;
use App\Models\ExpenseLine;
use App\Models\ExpenseReport;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * The expense report PDF against example reports produced by the legacy platform. The examples are not part of the
 * repository: they are read from LEGACY_SAMPLES_DIR/expense-reports/*.pdf and the test is skipped without them. Each
 * example is read back (person, month, lines), the same report is generated, and every piece of text of the legacy
 * page must be in the new one, at about the same place (the two PDF engines differ by a few points).
 */
class LegacyExpenseReportExampleTest extends TestCase
{
    use RefreshDatabase;

    /** Largest distance accepted between the legacy and the new position of a piece of text, in points. */
    private const float TOLERANCE = 6.0;

    public function test_the_report_matches_the_legacy_examples(): void
    {
        $directory = rtrim((string) getenv('LEGACY_SAMPLES_DIR'), '/').'/expense-reports';
        $examples = getenv('LEGACY_SAMPLES_DIR') ? (glob($directory.'/*.pdf') ?: []) : [];

        if ($examples === []) {
            $this->markTestSkipped('No legacy expense report examples (LEGACY_SAMPLES_DIR).');
        }

        Storage::fake('local');
        app()->setLocale('fr');
        $this->seed(RoleSeeder::class);

        foreach ($examples as $example) {
            $legacy = $this->texts((string) file_get_contents($example));
            $report = $this->generate($legacy);
            $current = $this->texts((string) Storage::disk('local')->get($report->file->path));

            foreach ($legacy as $item) {
                $closest = collect($current)->filter(fn (array $other) => $other['text'] === $item['text'])
                    ->map(fn (array $other) => hypot($other['x'] - $item['x'], $other['y'] - $item['y']))->min();

                $this->assertNotNull($closest, "« {$item['text']} » of the legacy report is missing.");
                $this->assertLessThanOrEqual(self::TOLERANCE, $closest, "« {$item['text']} » is {$closest} pt away from where the legacy report prints it.");
            }
        }
    }

    /**
     * The same report as the example: person, month and lines read from the legacy page.
     *
     * @param  list<array{x: float, y: float, text: string}>  $legacy
     */
    private function generate(array $legacy): ExpenseReport
    {
        $name = collect($legacy)->first(fn (array $item) => $item['x'] > 100 && abs($item['y'] - collect($legacy)->firstWhere('text', 'Nom :')['y']) < 2)['text'];
        $user = User::factory()->withRole(Role::Technician)->create(['name' => $name]);
        $natures = collect(ExpenseNature::cases())->mapWithKeys(fn (ExpenseNature $nature) => [$nature->label() => $nature]);
        $month = null;

        foreach (collect($legacy)->filter(fn (array $item) => preg_match('#^\d{2}/\d{2}/\d{4}$#', $item['text']) === 1) as $date) {
            $row = collect($legacy)->filter(fn (array $item) => abs($item['y'] - $date['y']) < 1 && $item !== $date)->sortBy('x')->values();
            $amount = $row->last()['text'];
            [$day, $monthNumber, $year] = explode('/', $date['text']);
            $month ??= "{$year}-{$monthNumber}";
            ExpenseLine::query()->create([
                'user_id' => $user->id, 'status' => ExpenseLineStatus::Manual, 'spent_on' => "{$year}-{$monthNumber}-{$day}",
                'nature' => $natures[$row->first()['text']], 'comment' => $row->count() > 2 ? $row[1]['text'] : null,
                'amount_cents' => (int) round((float) str_replace([',', ' €', '€', ' '], ['.', '', '', ''], $amount) * 100),
            ]);
        }

        $this->assertNotNull($month, 'The example has no line.');

        return app(ExpenseReportBuilder::class)->build($user, $name, (string) $month, ExpenseLine::query()->where('user_id', $user->id)->orderBy('id')->get());
    }

    /**
     * @return list<array{x: float, y: float, text: string}>
     */
    private function texts(string $pdf): array
    {
        $items = (new Parser)->parseContent($pdf)->getPages()[0]->getDataTm();

        return array_values(array_filter(array_map(fn (array $item) => [
            'x' => (float) $item[0][4], 'y' => (float) $item[0][5], 'text' => trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $item[1])),
        ], $items), fn (array $item) => $item['text'] !== ''));
    }
}
