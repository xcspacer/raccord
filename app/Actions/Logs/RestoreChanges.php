<?php

namespace App\Actions\Logs;

use App\Logs\ChangeJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class RestoreChanges
{
    public function __construct(private ChangeJournal $journal) {}

    /**
     * Puts the selected records back as they were before each change, oldest change first (so the most
     * recent selected change of a record wins). Deleted records come back. A record changed after the
     * entry (by a change that is not selected too) is left alone and counted as a conflict, never
     * overwritten. Credentials and a person's agency are never put back. Restoring is itself recorded
     * in the journal.
     *
     * @param  list<int>  $activityIds
     * @return array{restored: int, failed: int, conflicts: int}
     */
    public function handle(array $activityIds): array
    {
        $result = ['restored' => 0, 'failed' => 0, 'conflicts' => 0];

        $activities = $this->journal->query()->whereIn('id', $activityIds)->reorder('id')->get()
            ->filter(fn (Activity $activity) => $this->journal->isRestorable($activity));

        $selectedIds = $activities->pluck('id')->map(fn (mixed $id) => (int) $id)->all();
        $conflicting = $activities->filter(fn (Activity $activity) => $this->journal->changedSince($activity, $selectedIds))->pluck('id')->all();

        $activities
            ->each(function (Activity $activity) use (&$result, $conflicting): void {
                if (in_array($activity->id, $conflicting, true)) {
                    $result['conflicts']++;

                    return;
                }

                try {
                    DB::transaction(fn () => $this->restore($activity));
                    $result['restored']++;
                } catch (QueryException) {
                    $result['failed']++;
                }
            });

        return $result;
    }

    private function restore(Activity $activity): void
    {
        /** @var class-string<Model> $class */
        $class = $this->journal->subjectClass($activity);

        $record = $class::query()->withoutGlobalScope(SoftDeletingScope::class)->find($activity->subject_id);

        if ($record === null) {
            $record = new $class;
            $record->setAttribute($record->getKeyName(), $activity->subject_id);
        }

        $record->forceFill($this->journal->restorableSnapshot($activity));

        if (method_exists($record, 'getDeletedAtColumn')) {
            $record->setAttribute($record->getDeletedAtColumn(), null);
        }

        $record->save();
    }
}
