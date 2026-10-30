<?php

namespace App\Models;

use App\Enums\AbsenceType;
use App\Models\Concerns\RecordsChanges;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property AbsenceType $type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $note
 * @property int|null $created_by
 */
#[Fillable(['user_id', 'type', 'starts_on', 'ends_on', 'note', 'created_by'])]
class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory;

    use RecordsChanges;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AbsenceType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function activityLogName(): string
    {
        return 'tasks';
    }
}
