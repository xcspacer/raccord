<?php

namespace App\Models;

use App\Enums\TaskCompletion;
use App\Models\Concerns\RecordsChanges;
use App\Planning\TaskDetail;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A technician's assignment for one day (tâche).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property string $state_code
 * @property string|null $label
 * @property string|null $detail
 * @property string|null $note
 * @property int|null $work_site_id
 * @property TaskCompletion|null $completion_status
 * @property Carbon|null $completed_at
 * @property string|null $not_done_reason
 * @property int $version
 */
#[Fillable(['user_id', 'date', 'state_code', 'label', 'detail', 'note', 'work_site_id', 'completion_status', 'completed_at', 'not_done_reason', 'legacy_id'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use RecordsChanges;

    /**
     * Database defaults, so the journal always records complete records.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['state_code' => 'HO', 'version' => 1];

    /**
     * Every change raises the version, so a window opened on an older version cannot save over it.
     */
    protected static function booted(): void
    {
        static::updating(function (Task $task): void {
            if (array_key_exists('version', $task->getAttributes()) && ! $task->isDirty('version')) {
                $task->version = $task->version + 1;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'completion_status' => TaskCompletion::class,
            'completed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WorkSite, $this>
     */
    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class);
    }

    /**
     * Text of the task: what was typed, or, for a task generated from a work site, built from it.
     */
    public function displayDetail(TaskDetail $builder): string
    {
        if ($this->detail !== null && $this->detail !== '') {
            return $this->detail;
        }

        return $this->workSite !== null ? $builder->build($this->workSite, $this->user_id) : '';
    }

    public static function activityLogName(): string
    {
        return 'tasks';
    }
}
