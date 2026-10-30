<?php

namespace App\Models;

use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A generated monthly expense report, kept with its PDF. `file_name` is the name its PDF was already known by
 * before the platform (imported reports); otherwise the download name is built from the report.
 *
 * @property int $id
 * @property int $user_id
 * @property string $month
 * @property string $name
 * @property int $total_cents
 * @property int $lines_count
 * @property int $stored_file_id
 * @property string|null $file_name
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'month', 'name', 'total_cents', 'lines_count', 'stored_file_id', 'file_name', 'created_at'])]
class ExpenseReport extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total_cents' => 'integer', 'lines_count' => 'integer'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Name of the PDF when it is downloaded.
     */
    public function downloadName(): string
    {
        if ($this->file_name) {
            return $this->file_name;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month)->translatedFormat('F Y');

        return Str::of(__('pdf.expense_report.file', ['company' => app(CompanySettings::class)->displayName(), 'name' => $this->name, 'month' => mb_strtoupper($month)]))
            ->ascii()->replaceMatches('/[^A-Za-z0-9 _.\-]/', '')->squish().'.pdf';
    }
}
