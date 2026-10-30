<?php

namespace App\Billing\Export;

use App\Enums\BillingExpenseCategory;
use App\Enums\BillingExpenseStatus;
use App\Models\BillingExpense;
use App\Models\BillingExpensePhoto;
use App\Models\User;
use App\Support\ZipDownload;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

/**
 * Expense exports: one request with its receipts (from its detail), and the « Remboursements » of a month, one
 * workbook per person with the receipts beside it.
 */
class ExpenseWorkbooks
{
    public const array SCOPES = ['all', 'pending', 'approved'];

    public function __construct(private ZipDownload $zips) {}

    /**
     * One request: the workbook alone, or a ZIP with the receipts when it has some.
     */
    public function single(BillingExpense $expense): Response
    {
        $photos = $expense->photos()->with('file')->orderBy('id')->get()->values();
        $names = $photos->map(fn (BillingExpensePhoto $photo, int $index) => __('billing.export.expenses.attachment', ['number' => $index + 1]).'.'.$photo->file->extension)->all();
        $base = SheetWriter::fileName(__('billing.export.files.expense', ['name' => $expense->user->name, 'date' => $expense->spent_on->toDateString()]));

        $book = $this->book([
            ['technician', 'date', 'category', 'mileage', 'amount', 'status', 'reviewed_on', 'attachment', 'comment', 'reason'],
            [$expense->user->name, ...$this->values($expense, $names)],
        ], [20, 12, 16, 16, 12, 12, 16, 30, 40, 30]);

        if ($photos->isEmpty()) {
            return $this->xlsx($book, $base.'.xlsx');
        }

        return $this->zips->stream('billing_expense', $base.'.zip', function (ZipStream $zip) use ($book, $base, $photos, $names): void {
            $this->addBook($zip, $base.'.xlsx', $book);

            foreach ($photos as $index => $photo) {
                ZipDownload::addStoredFile($zip, $names[$index], $photo->file);
            }
        }, $photos->count() + 1);
    }

    /**
     * Expenses of a month (one person or everyone) in the chosen scope; null when there is nothing to export. One
     * workbook alone is downloaded as is, anything more as a ZIP with the receipts.
     */
    public function month(string $month, ?User $technician, string $scope): ?Response
    {
        $label = ExportSections::monthLabel($month);
        $suffix = $technician === null ? '' : ' - '.__('billing.export.expenses.scopes.'.$scope);
        $files = [];

        $expenses = BillingExpense::query()->with(['photos.file', 'user:id,name'])
            ->where('spent_on', 'like', $month.'-%')
            ->when($technician !== null, fn ($query) => $query->where('user_id', $technician?->id))
            ->when($scope !== 'all', fn ($query) => $query->where('status', $scope === 'pending' ? BillingExpenseStatus::Pending->value : BillingExpenseStatus::Approved->value))
            ->orderBy('spent_on')->orderBy('id')->get();

        foreach ($expenses->groupBy('user_id')->sortBy(fn (Collection $group) => mb_strtolower((string) $group->first()?->user->name)) as $group) {
            $name = (string) $group->first()?->user->name;
            $folder = SheetWriter::fileName(__('billing.export.expenses.folder', ['name' => $name]));
            $number = 0;
            $rows = [['date', 'category', 'mileage', 'amount', 'status', 'reviewed_on', 'attachment', 'comment', 'reason']];

            foreach ($group as $expense) {
                $names = [];

                foreach ($expense->photos as $photo) {
                    $fileName = $expense->spent_on->toDateString().'_'.(++$number).'.'.$photo->file->extension;
                    $names[] = $fileName;
                    $files[] = ['path' => $folder.'/'.$fileName, 'photo' => $photo];
                }

                $rows[] = $this->values($expense, $names);
            }

            $approved = (float) $group->where('status', BillingExpenseStatus::Approved)->sum(fn (BillingExpense $expense) => (float) $expense->amount);
            $rows[] = [];
            $rows[] = ['', __('billing.export.expenses.approved_total'), '', round($approved, 2), '', '', '', '', ''];

            $files[] = [
                'path' => SheetWriter::fileName(__('billing.export.files.expenses', ['month' => $label, 'name' => $name])).$suffix.'.xlsx',
                'book' => $this->book($rows, [12, 16, 16, 12, 12, 16, 30, 40, 30]),
            ];
        }

        if ($files === []) {
            return null;
        }

        if (count($files) === 1 && isset($files[0]['book'])) {
            return $this->xlsx($files[0]['book'], $files[0]['path']);
        }

        $zipName = $technician === null
            ? __('billing.export.files.expenses_zip', ['month' => $label])
            : __('billing.export.files.expenses_person_zip', ['name' => $technician->name, 'month' => $label, 'scope' => __('billing.export.expenses.scopes.'.$scope)]);

        return $this->zips->stream('billing_expenses', SheetWriter::fileName($zipName).'.zip', function (ZipStream $zip) use ($files): void {
            foreach ($files as $file) {
                isset($file['book']) ? $this->addBook($zip, $file['path'], $file['book']) : ZipDownload::addStoredFile($zip, $file['path'], $file['photo']->file);
            }
        }, count($files));
    }

    /**
     * Date, category, mileage (fuel), amount, status, approval day, receipts, comment and rejection reason.
     *
     * @param  list<string>  $attachments
     * @return list<string|float|null>
     */
    private function values(BillingExpense $expense, array $attachments): array
    {
        return [
            $expense->spent_on->format('d/m/Y'),
            $expense->category === BillingExpenseCategory::Other && $expense->category_other ? $expense->category->label().' — '.$expense->category_other : $expense->category->label(),
            $expense->category === BillingExpenseCategory::Fuel && $expense->mileage_km !== null ? (float) $expense->mileage_km : null,
            round((float) $expense->amount, 2),
            $expense->status->label(),
            $expense->status === BillingExpenseStatus::Approved ? $expense->reviewed_at?->setTimezone(config('platform.timezone'))->format('d/m/Y') : null,
            $attachments === [] ? __('billing.export.expenses.none') : implode(', ', $attachments),
            $expense->comment,
            $expense->status === BillingExpenseStatus::Rejected ? $expense->rejection_reason : null,
        ];
    }

    /**
     * The « Frais » sheet; the first row holds column keys, translated here.
     *
     * @param  list<list<string|float|null>>  $rows
     * @param  list<int>  $widths
     */
    private function book(array $rows, array $widths): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = SheetWriter::sheet($book, __('billing.export.expenses.sheet'));
        $rows[0] = array_map(fn (?string $key) => __('billing.export.expenses.columns.'.$key), $rows[0]);

        SheetWriter::rows($sheet, $rows);
        SheetWriter::widths($sheet, $widths);

        return $book;
    }

    private function xlsx(Spreadsheet $book, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function addBook(ZipStream $zip, string $path, Spreadsheet $book): void
    {
        $stream = SheetWriter::stream($book);
        $zip->addFileFromStream($path, $stream);
        fclose($stream);
    }
}
