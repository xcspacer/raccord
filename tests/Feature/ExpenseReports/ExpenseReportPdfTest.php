<?php

namespace Tests\Feature\ExpenseReports;

use App\Enums\ExpenseLineStatus;
use App\Enums\ExpenseNature;
use App\Enums\Role;
use App\Expenses\ExpenseReportBuilder;
use App\Models\ExpenseLine;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class ExpenseReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app()->setLocale('fr');
        $this->seed(RoleSeeder::class);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Jane Doe']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(array $attributes): ExpenseLine
    {
        return ExpenseLine::query()->create($attributes + ['user_id' => $this->technician->id, 'status' => ExpenseLineStatus::Manual]);
    }

    private function receipt(int $seed): StoredFile
    {
        $path = "files/receipt-{$seed}.jpg";
        $image = imagecreatetruecolor(300, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 30 * $seed % 255, 90, 120));
        ob_start();
        imagejpeg($image);
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return StoredFile::query()->create(['sha256' => hash('sha256', $path), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 10]);
    }

    private function generate(): Document
    {
        $lines = ExpenseLine::query()->where('user_id', $this->technician->id)->orderBy('id')->get();
        $report = app(ExpenseReportBuilder::class)->build($this->technician, 'Jane Doe', '2026-10', $lines);

        return (new Parser)->parseContent((string) Storage::disk('local')->get($report->file->path));
    }

    /**
     * Each piece of text of the page with its position (in points, from the bottom left corner).
     *
     * @return list<array{x: float, y: float, text: string}>
     */
    private function texts(Document $pdf): array
    {
        return array_map(fn (array $item) => ['x' => (float) $item[0][4], 'y' => (float) $item[0][5], 'text' => $this->normalise($item[1])], $pdf->getPages()[0]->getDataTm());
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $texts
     * @return array{x: float, y: float, text: string}
     */
    private function find(array $texts, string $text): array
    {
        $found = array_values(array_filter($texts, fn (array $item) => $item['text'] === $this->normalise($text)));
        $this->assertNotEmpty($found, "« {$text} » is not in the PDF");

        return $found[0];
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
    }

    private function width(string $text, string $style, float $size): float
    {
        $metrics = app('dompdf')->getFontMetrics();

        return $metrics->getTextWidth($text, (string) $metrics->getFont('DejaVu Sans', $style), $size);
    }

    public function test_the_report_follows_the_paper_form_on_a_single_page(): void
    {
        $this->line(['spent_on' => '2026-10-02', 'nature' => ExpenseNature::Fuel, 'comment' => 'Plein du fourgon', 'amount_cents' => 7240]);
        $this->line(['spent_on' => '2026-10-05', 'nature' => ExpenseNature::Restaurant, 'comment' => 'Déjeuner', 'amount_cents' => 1490]);
        $this->line(['spent_on' => '2026-10-09', 'nature' => ExpenseNature::Toll, 'comment' => 'Autoroute Ville A - Ville B', 'amount_cents' => 830]);

        $pdf = $this->generate();
        $texts = $this->texts($pdf);

        $this->assertCount(1, $pdf->getPages());

        foreach (['OCTOBRE', '2026', 'Nom :', 'Jane Doe', 'Date dépense', 'Nature dépense', 'Commentaire', 'Montant TTC', 'Total TTC', 'Total général'] as $text) {
            $this->find($texts, $text);
        }

        $dates = array_map(fn (string $date) => $this->find($texts, $date), ['02/10/2026', '05/10/2026', '09/10/2026']);
        $natures = array_map(fn (string $nature) => $this->find($texts, $nature), ['Carburant', 'Restaurant', 'Péage']);
        $comments = array_map(fn (string $comment) => $this->find($texts, $comment), ['Plein du fourgon', 'Déjeuner', 'Autoroute Ville A - Ville B']);
        $amounts = array_values(array_filter($texts, fn (array $item) => in_array($item['text'], ['72,40 €', '14,90 €', '8,30 €'], true) && abs($item['y'] - $dates[0]['y']) < 60));

        $this->assertCount(3, $amounts);
        $this->assertEqualsWithDelta($dates[0]['y'], $natures[0]['y'], 0.5);
        $this->assertEqualsWithDelta($dates[0]['y'], $comments[0]['y'], 0.5);

        $centre = fn (array $item) => $item['x'] + $this->width($item['text'], 'normal', 8.5) / 2;
        $this->assertEqualsWithDelta($centre($dates[0]), $centre($dates[2]), 0.5);
        $this->assertEqualsWithDelta($centre($natures[0]), $centre($natures[1]), 0.5);
        $this->assertEqualsWithDelta($centre($natures[0]), $centre($natures[2]), 0.5);
        $this->assertEqualsWithDelta($comments[0]['x'], $comments[1]['x'], 0.5);
        $this->assertEqualsWithDelta($comments[0]['x'], $comments[2]['x'], 0.5);

        $right = fn (array $item, string $style, float $size) => $item['x'] + $this->width($item['text'], $style, $size);
        $this->assertEqualsWithDelta($right($amounts[0], 'normal', 8.5), $right($amounts[1], 'normal', 8.5), 0.5);
        $this->assertEqualsWithDelta($right($amounts[0], 'normal', 8.5), $right($amounts[2], 'normal', 8.5), 0.5);
        $this->assertGreaterThan($amounts[0]['x'], $amounts[2]['x']);

        $recapRows = array_values(array_filter($texts, fn (array $item) => $item['y'] < $dates[2]['y'] - 100));
        $zeros = array_values(array_filter($recapRows, fn (array $item) => $item['text'] === '- €'));
        $this->assertCount(9, $zeros);
        $this->assertSame([$zeros[0]['x']], array_values(array_unique(array_column($zeros, 'x'))));

        $restaurant = array_values(array_filter($recapRows, fn (array $item) => $item['text'] === 'Restaurant'))[0];
        $subscription = $this->find($recapRows, 'Abonnement');
        $this->assertEqualsWithDelta($restaurant['x'], $subscription['x'], 0.5);
        $this->assertEqualsWithDelta(
            $right(['text' => Money::format(1490)] + $this->find($recapRows, '14,90 €'), 'italic', 8),
            $right(['text' => __('pdf.expense_report.no_amount')] + $zeros[0], 'italic', 8),
            0.5,
        );

        $transport = [$this->find($recapRows, 'Indemnités kilométriques / Parking / Péage /'), $this->find($recapRows, 'Métro / Taxi / Train / Avion / Hôtel')];
        $this->assertEqualsWithDelta($transport[0]['x'], $transport[1]['x'], 0.5);
        $this->assertGreaterThan($transport[1]['y'], $transport[0]['y']);

        $grandTotal = $this->find($recapRows, 'Total général');
        $this->assertGreaterThan($restaurant['x'] + 50, $grandTotal['x']);

        $fonts = array_map(fn ($font) => $font->getName(), $pdf->getFonts());
        $this->assertContains('DejaVuSans-BoldOblique', $fonts);
        $this->assertContains('DejaVuSans-Oblique', $fonts);
    }

    public function test_a_report_without_receipt_photos_is_a_single_page(): void
    {
        $this->line(['spent_on' => '2026-10-02', 'nature' => ExpenseNature::Fuel, 'amount_cents' => 5000]);

        $this->assertCount(1, $this->generate()->getPages());
    }

    public function test_every_receipt_photo_goes_on_one_second_page(): void
    {
        foreach (range(1, 10) as $seed) {
            $this->line(['spent_on' => '2026-10-02', 'nature' => ExpenseNature::Fuel, 'amount_cents' => 1000 + $seed, 'stored_file_id' => $this->receipt($seed)->id]);
        }

        $pdf = $this->generate();

        $this->assertCount(2, $pdf->getPages());
        $this->assertStringContainsString('Justificatifs — Jane Doe — OCTOBRE 2026', $this->normalise($pdf->getPages()[1]->getText()));
    }

    public function test_the_longest_report_still_fits_its_lines_on_the_first_page(): void
    {
        foreach (range(1, 32) as $day) {
            $this->line(['spent_on' => '2026-10-'.str_pad((string) min($day, 31), 2, '0', STR_PAD_LEFT), 'nature' => ExpenseNature::MileageAllowance, 'comment' => str_repeat('Trajet entre deux chantiers avec détour ', 4), 'amount_cents' => 1234]);
        }

        $pdf = $this->generate();

        $this->assertCount(1, $pdf->getPages());
        $this->assertStringContainsString('Total général', $pdf->getPages()[0]->getText());
    }
}
