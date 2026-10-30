<?php

namespace App\Livewire\ExpenseReports;

use App\Actions\Files\StoreUploadedFile;
use App\Actions\Files\UploadRules;
use App\Enums\ExpenseLineStatus;
use App\Enums\ExpenseNature;
use App\Expenses\DuplicateFinder;
use App\Expenses\ExpenseEmail;
use App\Expenses\ExpenseReportBuilder;
use App\Expenses\ExpenseTotals;
use App\Expenses\PhotoHash;
use App\Expenses\ReceiptCropper;
use App\Jobs\ReadReceipt;
use App\Models\ExpenseDraft;
use App\Models\ExpenseLine;
use App\Models\ExpenseReport;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly expense report of the signed-in person: lines entered by hand or from ticket photos (read by the AI),
 * checked for duplicates, then generated as a PDF kept in their history. The draft lives on the server.
 */
class Editor extends Component
{
    use WithFileUploads;

    public const int MAX_LINES = 32;

    public string $name = '';

    public string $month = '';

    public bool $autocrop = true;

    /** @var list<TemporaryUploadedFile> */
    public array $photos = [];

    /** @var array<int, array{date: string, nature: string, comment: string, amount: string}> */
    public array $edits = [];

    /** @var list<string> */
    public array $warnings = [];

    #[Locked]
    public ?int $generatedId = null;

    /** What the last « generate » was asked for, kept while its warnings wait for a confirmation. */
    #[Locked]
    public string $intent = 'show';

    public function mount(): void
    {
        $user = $this->user();
        $draft = ExpenseDraft::query()->where('user_id', $user->id)->first();

        $this->name = $draft->name ?? $user->name;
        $this->month = $draft->month ?? $this->currentMonth();
        $this->autocrop = $draft->autocrop ?? true;

        foreach ($this->lines() as $line) {
            $this->edits[$line->id] = $this->editsOf($line);
        }
    }

    public function updatedName(): void
    {
        $this->saveDraft();
    }

    public function updatedMonth(): void
    {
        $this->saveDraft();
    }

    public function updatedAutocrop(): void
    {
        $this->saveDraft();
    }

    public function updatedPhotos(StoreUploadedFile $files, PhotoHash $hash, ReceiptCropper $cropper): void
    {
        $this->authorize('create', ExpenseLine::class);
        $this->resetErrorBag('photos');
        $this->validate(['photos' => ['array'], 'photos.*' => UploadRules::photo(15360)]);

        $user = $this->user();
        $photos = $this->photos;
        $this->photos = [];

        if ($photos === []) {
            return;
        }

        if ($user->expenseLines()->count() + count($photos) > self::MAX_LINES) {
            $this->addError('photos', __('expense_reports.errors.too_many', ['max' => self::MAX_LINES]));

            return;
        }

        $ignored = false;

        foreach ($photos as $photo) {
            try {
                $pieces = $this->autocrop ? $cropper->pieces($photo) : [null];
            } catch (InvalidArgumentException) {
                $this->addError('photos', __('expense_reports.errors.invalid_image'));

                continue;
            }

            foreach ($pieces as $piece) {
                if ($user->expenseLines()->count() >= self::MAX_LINES) {
                    $ignored = true;

                    break 2;
                }

                try {
                    $stored = $piece === null ? $files->handleImage($photo) : $files->handleImageBytes($piece);
                } catch (InvalidArgumentException) {
                    $this->addError('photos', __('expense_reports.errors.invalid_image'));

                    continue;
                }

                $line = $user->expenseLines()->create([
                    'nature' => ExpenseNature::Other, 'stored_file_id' => $stored->id,
                    'photo_hash' => $hash->of($stored), 'status' => ExpenseLineStatus::Reading,
                ]);
                $this->edits[$line->id] = $this->editsOf($line);
                $this->generatedId = null;

                ReadReceipt::dispatchAfterResponse($user->id, $line->id);
            }
        }

        if ($ignored) {
            $this->addError('photos', __('expense_reports.errors.pieces_ignored', ['max' => self::MAX_LINES]));
        }
    }

    public function addLine(): void
    {
        $this->authorize('create', ExpenseLine::class);
        $user = $this->user();

        if ($user->expenseLines()->count() >= self::MAX_LINES) {
            $this->addError('photos', __('expense_reports.errors.too_many', ['max' => self::MAX_LINES]));

            return;
        }

        $line = $user->expenseLines()->create(['nature' => ExpenseNature::Other, 'status' => ExpenseLineStatus::Manual]);
        $this->edits[$line->id] = $this->editsOf($line);
        $this->generatedId = null;
    }

    public function updated(string $property): void
    {
        if (! preg_match('/^edits\.(\d+)\.(date|nature|comment|amount)$/', $property, $match)) {
            return;
        }

        $line = $this->user()->expenseLines()->findOrFail((int) $match[1]);
        $values = $this->edits[$line->id] ?? [];

        validator($values, [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'nature' => ['required', Rule::enum(ExpenseNature::class)],
            'comment' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'regex:/^\s*\d{1,6}([.,]\d{1,2})?\s*$/'],
        ])->validate();

        $line->update([
            'spent_on' => ($values['date'] ?? '') ?: null,
            'nature' => $values['nature'],
            'comment' => trim((string) $values['comment']) ?: null,
            'amount_cents' => Money::parse((string) $values['amount']) ?? 0,
        ]);
        $this->generatedId = null;
    }

    public function removeLine(int $id, StoreUploadedFile $files): void
    {
        $line = $this->user()->expenseLines()->with('file')->find($id);

        if ($line === null) {
            return;
        }

        $line->delete();

        if ($line->file !== null) {
            $files->release($line->file);
        }

        unset($this->edits[$id]);
        $this->generatedId = null;
    }

    public function reread(int $id): void
    {
        $this->authorize('create', ExpenseLine::class);
        $user = $this->user();
        $line = $user->expenseLines()->whereNotNull('stored_file_id')->findOrFail($id);

        $line->update(['status' => ExpenseLineStatus::Reading, 'message' => null]);
        ReadReceipt::dispatchAfterResponse($user->id, $line->id);
    }

    public function confirmNotDuplicate(int $id, DuplicateFinder $finder): void
    {
        $lines = $this->lines();
        $line = $lines->firstWhere('id', $id);

        if ($line === null || ! isset($finder->find($lines)[$id])) {
            return;
        }

        foreach ($lines as $other) {
            if ($other->id !== $id && ! $other->duplicate_confirmed && $finder->matches($line, $other)) {
                $other->update(['duplicate_confirmed' => true]);
            }
        }

        $line->update(['duplicate_confirmed' => true]);
    }

    public function clear(StoreUploadedFile $files): void
    {
        foreach ($this->lines() as $line) {
            $line->delete();

            if ($line->file !== null) {
                $files->release($line->file);
            }
        }

        $this->edits = [];
        $this->warnings = [];
        $this->generatedId = null;
    }

    /**
     * Builds the report from the draft. « download » (desktop button) sends the PDF straight away, « mail » shows how to
     * send it, « phone » is the phone layout: the report of the signed-in person for the current month, as there.
     */
    public function generate(bool $confirmed, ExpenseReportBuilder $builder, DuplicateFinder $finder, string $intent = 'show'): ?StreamedResponse
    {
        $this->resetErrorBag();
        $this->warnings = [];
        $this->intent = in_array($intent, ['show', 'download', 'mail', 'phone'], true) ? $intent : 'show';

        if ($this->intent === 'phone') {
            $this->name = $this->user()->name;
            $this->month = $this->currentMonth();
        }

        $this->saveDraft();

        $lines = $this->lines()->filter(fn (ExpenseLine $line) => $line->file !== null || $line->spent_on !== null || $line->amount_cents > 0 || $line->comment !== null)->values();

        if ($lines->isEmpty()) {
            $this->addError('generate', __('expense_reports.errors.no_lines'));

            return null;
        }

        if ($lines->contains(fn (ExpenseLine $line) => $line->status === ExpenseLineStatus::Reading)) {
            $this->addError('generate', __('expense_reports.errors.still_reading'));

            return null;
        }

        if (! $confirmed) {
            if ($finder->find($lines) !== []) {
                $this->warnings[] = __('expense_reports.warnings.duplicates');
            }

            if ($lines->contains(fn (ExpenseLine $line) => $line->spent_on === null || $line->amount_cents <= 0)) {
                $this->warnings[] = __('expense_reports.warnings.incomplete');
            }

            if ($this->warnings !== []) {
                return null;
            }
        }

        $report = $builder->build($this->user(), trim($this->name), $this->month, $lines);

        $this->edits = [];
        $this->generatedId = $report->id;

        if ($this->intent !== 'download') {
            return null;
        }

        $file = $report->file;

        return Storage::disk($file->disk)->download($file->path, $report->downloadName(), ['Content-Type' => 'application/pdf']);
    }

    public function render(CompanySettings $company, ExpenseTotals $totals, DuplicateFinder $finder, ExpenseEmail $email): View
    {
        $lines = $this->lines();

        foreach ($lines as $line) {
            if ($line->status === ExpenseLineStatus::Reading || ! isset($this->edits[$line->id])) {
                $this->edits[$line->id] = $this->editsOf($line);
            }
        }

        $report = $this->generatedId === null ? null : ExpenseReport::query()->where('user_id', $this->user()->id)->find($this->generatedId);

        return view('livewire.expense-reports.editor', [
            'lines' => $lines,
            'duplicates' => $finder->find($lines),
            'totals' => $totals->for($lines),
            'natures' => ExpenseNature::cases(),
            'reading' => $lines->contains(fn (ExpenseLine $line) => $line->status === ExpenseLineStatus::Reading),
            'report' => $report,
            'email' => $report === null ? null : $email->for($report) + ['recipient' => $company->expense_report_email],
            'phoneMonth' => $this->phoneMonth(),
        ])->layout('components.layouts.app', ['title' => __('expense_reports.title'), 'page' => 'expense-reports']);
    }

    /**
     * @return Collection<int, ExpenseLine>
     */
    private function lines(): Collection
    {
        return $this->user()->expenseLines()->with('file')->oldest('id')->get();
    }

    /**
     * @return array{date: string, nature: string, comment: string, amount: string}
     */
    private function editsOf(ExpenseLine $line): array
    {
        return [
            'date' => $line->spent_on?->toDateString() ?? '',
            'nature' => $line->nature->value,
            'comment' => (string) $line->comment,
            'amount' => Money::input($line->amount_cents),
        ];
    }

    private function saveDraft(): void
    {
        $this->authorize('create', ExpenseLine::class);
        $values = validator(['name' => trim($this->name), 'month' => $this->month, 'autocrop' => $this->autocrop], [
            'name' => ['required', 'string', 'max:120'],
            'month' => ['required', 'date_format:Y-m'],
            'autocrop' => ['boolean'],
        ])->validate();

        ExpenseDraft::query()->updateOrCreate(['user_id' => $this->user()->id], $values);
    }

    /**
     * « Octobre 2026 »: the month of the phone layout, always the current one.
     */
    private function phoneMonth(): string
    {
        $label = CarbonImmutable::now(config('platform.timezone'))->translatedFormat('F Y');

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }

    private function currentMonth(): string
    {
        return CarbonImmutable::now(config('platform.timezone'))->format('Y-m');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
