<?php

namespace App\Expenses;

use App\Actions\Files\StoreUploadedFile;
use App\Models\ExpenseLine;
use App\Models\ExpenseReport;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\DocumentBrand;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates the monthly expense report PDF from the lines of a draft, keeps it in the person's history and
 * empties the draft.
 */
class ExpenseReportBuilder
{
    public function __construct(private ExpenseTotals $totals, private ExpenseReportLayout $layout, private StoreUploadedFile $files, private CompanySettings $company, private DocumentBrand $brand) {}

    /**
     * @param  Collection<int, ExpenseLine>  $lines  Non-empty lines of the person, in the order they are printed.
     */
    public function build(User $user, string $name, string $month, Collection $lines): ExpenseReport
    {
        $lines->loadMissing('file');
        $totals = $this->totals->for($lines);
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month);

        $photos = $lines->map(fn (ExpenseLine $line) => PdfImage::dataUri($line->file))->filter()->values();
        $rows = $this->layout->rows($lines);
        $footer = $this->company->document_footer;

        $contents = Pdf::loadView('pdf.expense-report', [
            ...$this->brand->for($user),
            'footer' => $footer,
            'name' => $name,
            'monthLabel' => mb_strtoupper($monthStart->translatedFormat('F')),
            'year' => $monthStart->format('Y'),
            'rows' => $rows,
            'fit' => $this->layout->fit($rows, $footer !== null),
            'total' => $totals['total'],
            'recap' => $totals['recap'],
            'photos' => $photos,
            'grid' => $this->layout->proofGrid($photos->count()),
        ])->setPaper('a4')->output();

        $stored = $this->files->handleContents($contents, 'application/pdf', 'pdf');

        return DB::transaction(function () use ($user, $name, $month, $lines, $totals, $stored): ExpenseReport {
            $report = ExpenseReport::query()->create([
                'user_id' => $user->id, 'month' => $month, 'name' => $name, 'total_cents' => $totals['total'],
                'lines_count' => $lines->count(), 'stored_file_id' => $stored->id, 'created_at' => now(),
            ]);

            foreach ($lines as $line) {
                $line->delete();

                if ($line->file !== null) {
                    $this->files->release($line->file);
                }
            }

            return $report;
        });
    }
}
