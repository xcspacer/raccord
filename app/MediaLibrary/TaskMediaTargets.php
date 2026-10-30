<?php

namespace App\MediaLibrary;

use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\WorkSiteMemberRole;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Models\WorkSiteBox;

/**
 * Where a technician sends before / after photos from a task: on one-day work sites (daily planning agencies) with
 * a cabinet, other than audits and NRO, the cabinet folder « PM » when they are the lead or a partner, and one
 * folder per box they (or as a partner) are given.
 *
 * @phpstan-type Target array{key: string, cabinet: string, folder: string, label: string}
 */
class TaskMediaTargets
{
    /**
     * @return list<Target>
     */
    public function for(Task $task, User $user): array
    {
        $site = $task->workSite;

        if (! $site instanceof WorkSite || ! $this->takesPhotos($site)) {
            return [];
        }

        $cabinet = trim((string) $site->cabinet_ref);
        $targets = [];

        if ($this->worksOnCabinet($site, $user)) {
            $targets[] = ['key' => MediaFolder::CABINET, 'cabinet' => $cabinet, 'folder' => MediaFolder::CABINET, 'label' => __('media_library.cabinet_folder')];
        }

        foreach ($site->boxes as $box) {
            /** @var WorkSiteBox $box */
            if (trim($box->reference) !== '' && ($box->technician_id === $user->id || $box->partners->contains('id', $user->id))) {
                $targets[] = ['key' => trim($box->reference), 'cabinet' => $cabinet, 'folder' => trim($box->reference), 'label' => trim($box->reference)];
            }
        }

        return $targets;
    }

    /**
     * The target of the person's task of that day, or null when it is not one of theirs.
     *
     * @return Target|null
     */
    public function find(User $user, string $date, string $key): ?array
    {
        $task = $this->task($user, $date);

        return $task === null ? null : collect($this->for($task, $user))->firstWhere('key', $key);
    }

    /**
     * The media library folder of a target, created on the first photo.
     *
     * @param  Target  $target
     */
    public function folder(array $target, bool $create): ?MediaFolder
    {
        $cabinet = MediaFolder::query()->whereNull('parent_id')->where('name', $target['cabinet'])->first()
            ?? ($create ? MediaFolder::query()->create(['name' => $target['cabinet']]) : null);

        if ($cabinet === null) {
            return null;
        }

        return $cabinet->children()->where('name', $target['folder'])->first()
            ?? ($create ? $cabinet->children()->create(['name' => $target['folder']]) : null);
    }

    /**
     * A technician sees the photos of the folders their tasks send to.
     */
    public function mayView(User $user, MediaFile $file): bool
    {
        $folder = $file->folder;
        $cabinet = $folder->parent;

        if ($cabinet === null) {
            return false;
        }

        return Task::query()
            ->with(['workSite.agency', 'workSite.members', 'workSite.boxes.partners'])
            ->where('user_id', $user->id)
            ->whereHas('workSite', fn ($query) => $query->where('cabinet_ref', $cabinet->name))
            ->get()
            ->contains(fn (Task $task) => collect($this->for($task, $user))->contains(fn (array $target) => $target['cabinet'] === $cabinet->name && $target['folder'] === $folder->name));
    }

    /**
     * The lead technician of the work site, or one of its partners.
     */
    private function worksOnCabinet(WorkSite $site, User $user): bool
    {
        if ($site->lead_technician_id === $user->id) {
            return true;
        }

        $member = $site->members->firstWhere('id', $user->id);
        $role = $member?->getRelationValue('pivot')?->getAttribute('role');

        return in_array($role, [WorkSiteMemberRole::Lead->value, WorkSiteMemberRole::Partner->value], true);
    }

    private function takesPhotos(WorkSite $site): bool
    {
        return trim((string) $site->cabinet_ref) !== ''
            && $site->agency?->planning_mode === PlanningMode::Daily
            && ! in_array($site->project_type, [ProjectType::Audit, ProjectType::Nro], true);
    }

    private function task(User $user, string $date): ?Task
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return Task::query()
            ->with(['workSite.agency', 'workSite.members', 'workSite.boxes.partners'])
            ->where('user_id', $user->id)
            ->whereDate('date', $date)
            ->first();
    }
}
