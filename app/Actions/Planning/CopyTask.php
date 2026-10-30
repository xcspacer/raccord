<?php

namespace App\Actions\Planning;

use App\Enums\WorkSiteMemberRole;
use App\Models\Absence;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Models\WorkSiteAttachment;
use App\Models\WorkSiteBox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies (or moves) a task to another person and day, as when dragging a cell of the task grid.
 * A task linked to a work site gets its own new work site for the target person and day.
 */
class CopyTask
{
    /**
     * Why the task cannot be dropped there: 'same', 'departed' or 'absent'; null when it can.
     */
    public function refusal(Task $source, User $target, CarbonImmutable $date): ?string
    {
        if ($source->user_id === $target->id && $source->date->isSameDay($date)) {
            return 'same';
        }

        if ($target->hasDeparted($date)) {
            return 'departed';
        }

        return $this->absenceOn($target, $date) !== null ? 'absent' : null;
    }

    public function absenceOn(User $target, CarbonImmutable $date): ?Absence
    {
        return Absence::query()->where('user_id', $target->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->latest('id')->first();
    }

    /**
     * The task the target already has that day (it will be replaced).
     */
    public function existingTask(User $target, CarbonImmutable $date): ?Task
    {
        return Task::query()->with(['workSite.client', 'workSite.boxes.partners'])->where('user_id', $target->id)->whereDate('date', $date)->first();
    }

    public function handle(User $actor, Task $source, User $target, CarbonImmutable $date, bool $move): Task
    {
        return DB::transaction(function () use ($actor, $source, $target, $date, $move): Task {
            $this->existingTask($target, $date)?->delete();

            $site = $source->workSite !== null ? $this->copySite($actor, $source->workSite, $source->user_id, $target, $date) : null;

            $copy = Task::query()->create([
                'user_id' => $target->id,
                'date' => $date->toDateString(),
                'state_code' => $source->state_code,
                'label' => $source->label,
                'detail' => $source->detail,
                'note' => $source->note,
                'work_site_id' => $site?->id,
            ]);

            if ($move) {
                $original = $source->workSite;
                $source->delete();

                if ($original !== null && ! $original->tasks()->exists()) {
                    $original->delete();
                }
            }

            return $copy;
        });
    }

    /**
     * One-crew copy of the work site on the target day, keeping only the boxes of the source person.
     */
    private function copySite(User $actor, WorkSite $original, int $sourceUserId, User $target, CarbonImmutable $date): WorkSite
    {
        $original->loadMissing(['boxes.partners', 'attachments.file']);

        $site = $original->replicate(['audit_id', 'legacy_id', 'version', 'created_by']);
        $site->fill([
            'starts_on' => $date->toDateString(),
            'ends_on' => $date->toDateString(),
            'crew_count' => 1,
            'lead_technician_id' => $target->id,
            'created_by' => $actor->id,
            'version' => 1,
        ])->save();

        $site->members()->sync([$target->id => ['role' => WorkSiteMemberRole::Lead->value]]);

        $original->boxes
            ->filter(fn (WorkSiteBox $box) => $box->technician_id === $sourceUserId || $box->partners->contains('id', $sourceUserId))
            ->values()
            ->each(fn (WorkSiteBox $box, int $position) => $site->boxes()->create(['reference' => $box->reference, 'technician_id' => $target->id, 'position' => $position]));

        $original->attachments->each(function (WorkSiteAttachment $attachment) use ($site): void {
            $site->attachments()->create($attachment->only(['type', 'stored_file_id', 'original_name', 'uploaded_by']));
            $attachment->file->increment('reference_count');
        });

        return $site;
    }
}
