<?php

namespace App\Actions\Planning;

use App\Enums\ProjectType;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk deletion of tasks with a short undo window. Audit work sites left without any task are deleted too.
 */
class DeleteTasks
{
    public const int UNDO_SECONDS = 30;

    private const int KEEP_SECONDS = 120;

    /**
     * @param  list<int>  $taskIds
     * @return array{token: string, tasks: int, sites: int, expiresAt: int}|null Null when nothing was deleted.
     */
    public function handle(User $actor, array $taskIds): ?array
    {
        $tasks = Task::query()->whereIn('id', $taskIds)->get();

        if ($tasks->isEmpty()) {
            return null;
        }

        $snapshot = $tasks->map(fn (Task $task) => $task->only(['user_id', 'state_code', 'label', 'detail', 'note', 'work_site_id', 'completion_status', 'completed_at', 'not_done_reason']) + ['date' => $task->date->toDateString()])->all();
        $siteIds = $tasks->pluck('work_site_id')->filter()->unique()->values();

        $deletedSites = DB::transaction(function () use ($tasks, $siteIds): array {
            $tasks->each->delete();

            return WorkSite::query()->whereIn('id', $siteIds)->where('project_type', ProjectType::Audit->value)
                ->whereDoesntHave('tasks')->get()->each->delete()->pluck('id')->all();
        });

        $token = (string) Str::uuid();
        Cache::put('task-undo:'.$token, ['actor' => $actor->id, 'tasks' => $snapshot, 'sites' => $deletedSites], self::KEEP_SECONDS);

        return ['token' => $token, 'tasks' => $tasks->count(), 'sites' => count($deletedSites), 'expiresAt' => now()->addSeconds(self::UNDO_SECONDS)->getTimestamp()];
    }

    /**
     * Puts the deleted tasks back (skipping days that were filled meanwhile). Returns how many came back,
     * or null when the undo is unknown, expired or someone else's.
     */
    public function undo(User $actor, string $token, int $expiresAt): ?int
    {
        $payload = Cache::pull('task-undo:'.$token);

        if (! is_array($payload) || $payload['actor'] !== $actor->id || now()->getTimestamp() > $expiresAt + 5) {
            return null;
        }

        return DB::transaction(function () use ($payload): int {
            WorkSite::onlyTrashed()->whereIn('id', $payload['sites'])->get()->each->restore();

            $restored = 0;

            foreach ($payload['tasks'] as $attributes) {
                if (Task::query()->where('user_id', $attributes['user_id'])->whereDate('date', $attributes['date'])->exists()) {
                    continue;
                }

                $site = $attributes['work_site_id'] ? WorkSite::query()->find($attributes['work_site_id']) : null;
                Task::query()->create(array_merge($attributes, ['work_site_id' => $site?->id]));
                $restored++;
            }

            return $restored;
        });
    }
}
