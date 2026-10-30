<?php

namespace App\Actions\Planning;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\AuditMode;
use App\Enums\ProjectType;
use App\Enums\WorkSiteAttachmentType;
use App\Enums\WorkSiteMemberRole;
use App\Enums\WorkSiteStatus;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Models\WorkSiteAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a one-day work site (SWAP / NRO) of a daily-planning agency together with its crew, boxes and
 * the generated tasks: one task per technician for that day.
 *
 * @phpstan-type BoxInput array{reference: string, technician_id: int|null, partners: list<int>}
 * @phpstan-type Input array{date: CarbonImmutable, client: string, project: ProjectType, audit_mode?: AuditMode|null, lead: int, partners: list<int>, city: string, cabinet_ref: string|null, notes: string|null, boxes: list<BoxInput>}
 */
class SaveDailyWorkSite
{
    public function __construct(private ResolveClient $clients, private StoreUploadedFile $files) {}

    /**
     * Distinct technicians of the work site: lead, partners and the technicians of every box.
     *
     * @param  Input  $input
     * @return list<int>
     */
    public function technicianIds(array $input): array
    {
        $ids = [$input['lead'], ...$input['partners']];

        foreach ($this->cleanBoxes($input['boxes']) as $box) {
            $ids = [...$ids, $box['technician_id'], ...$box['partners']];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * People who are absent that day and so cannot be assigned.
     *
     * @param  list<int>  $technicianIds
     * @return Collection<int, string>
     */
    public function absentTechnicians(array $technicianIds, CarbonImmutable $date): Collection
    {
        return Absence::query()
            ->whereIn('user_id', $technicianIds)
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->with('user')->get()->map(fn (Absence $absence) => (string) $absence->user?->name)->unique()->values();
    }

    /**
     * Technicians who already have another task that day (they would be reassigned).
     *
     * @param  list<int>  $technicianIds
     * @return Collection<int, string>
     */
    public function reassignedTechnicians(?WorkSite $site, array $technicianIds, CarbonImmutable $date): Collection
    {
        return $this->otherTasks($site, $technicianIds, $date)->with('user')->get()
            ->map(fn (Task $task) => (string) $task->user?->name)->unique()->values();
    }

    /**
     * @param  Input  $input
     * @param  array{kmz?: UploadedFile|null, synoptic?: UploadedFile|null}  $uploads
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?WorkSite $site, Agency $agency, array $input, array $uploads = []): WorkSite
    {
        $boxes = $this->cleanBoxes($input['boxes']);
        $technicianIds = $this->technicianIds($input);
        $absent = $this->absentTechnicians($technicianIds, $input['date']);

        if ($absent->isNotEmpty()) {
            throw ValidationException::withMessages(['lead' => __('work_sites.absent_technicians', ['names' => $absent->implode(', ')])]);
        }

        return DB::transaction(function () use ($actor, $site, $agency, $input, $boxes, $technicianIds, $uploads): WorkSite {
            $attributes = [
                'agency_id' => $agency->id,
                'client_id' => $this->clients->handle($input['client'])->id,
                'city' => $input['city'],
                'starts_on' => $input['date']->toDateString(),
                'ends_on' => $input['date']->toDateString(),
                'crew_count' => count($technicianIds),
                'status' => WorkSiteStatus::Confirmed,
                'project_type' => $input['project'],
                'audit_mode' => $input['audit_mode'] ?? null,
                'cabinet_ref' => $input['cabinet_ref'],
                'lead_technician_id' => $input['lead'],
                'site_manager' => null,
                'notes' => $input['notes'],
            ];

            if ($site === null) {
                $site = WorkSite::query()->create($attributes + ['created_by' => $actor->id]);
            } else {
                $site->update($attributes + ['version' => $site->version + 1]);
            }

            $this->syncCrew($site, $input);
            $this->syncBoxes($site, $boxes);
            $this->syncTasks($site, $technicianIds, $input['date'], $input['project'], $input['audit_mode'] ?? null);
            $this->syncAttachments($actor, $site, $uploads);

            return $site->refresh();
        });
    }

    /**
     * Boxes without a reference or a technician are dropped, repeated references keep the first one.
     *
     * @param  list<BoxInput>  $boxes
     * @return list<BoxInput>
     */
    private function cleanBoxes(array $boxes): array
    {
        return collect($boxes)
            ->map(fn (array $box) => [
                'reference' => trim($box['reference']),
                'technician_id' => $box['technician_id'] ?: null,
                'partners' => array_values(array_unique(array_filter($box['partners']))),
            ])
            ->filter(fn (array $box) => $box['reference'] !== '' && $box['technician_id'] !== null)
            ->unique('reference')
            ->values()
            ->all();
    }

    /**
     * @param  Input  $input
     */
    private function syncCrew(WorkSite $site, array $input): void
    {
        $members = [$input['lead'] => ['role' => WorkSiteMemberRole::Lead->value]];

        foreach ($input['partners'] as $partner) {
            $members[$partner] ??= ['role' => WorkSiteMemberRole::Partner->value];
        }

        $site->members()->sync($members);
    }

    /**
     * @param  list<BoxInput>  $boxes
     */
    private function syncBoxes(WorkSite $site, array $boxes): void
    {
        $site->boxes()->each(fn ($box) => $box->delete());

        foreach ($boxes as $position => $box) {
            $created = $site->boxes()->create(['reference' => $box['reference'], 'technician_id' => $box['technician_id'], 'position' => $position]);
            $created->partners()->sync($box['partners']);
        }
    }

    /**
     * One task per technician for the day; whatever else they had that day is replaced.
     *
     * @param  list<int>  $technicianIds
     */
    private function syncTasks(WorkSite $site, array $technicianIds, CarbonImmutable $date, ProjectType $project, ?AuditMode $mode): void
    {
        Task::query()->where('work_site_id', $site->id)
            ->where(fn ($query) => $query->whereNotIn('user_id', $technicianIds)->orWhereDate('date', '!=', $date))
            ->get()->each->delete();

        $this->otherTasks($site, $technicianIds, $date)->get()->each->delete();

        foreach ($technicianIds as $userId) {
            $task = Task::query()->firstOrNew(['user_id' => $userId, 'date' => $date->toDateString()]);
            $task->fill(['state_code' => 'HO', 'label' => $this->taskLabel($project, $mode), 'detail' => null, 'work_site_id' => $site->id])->save();
        }
    }

    private function taskLabel(ProjectType $project, ?AuditMode $mode): string
    {
        return match ($mode) {
            AuditMode::Optical => __('tasks.default_labels.optical_audit'),
            AuditMode::Full => __('tasks.default_labels.full_audit'),
            default => strtoupper($project->value),
        };
    }

    /**
     * @param  array{kmz?: UploadedFile|null, synoptic?: UploadedFile|null}  $uploads
     */
    private function syncAttachments(User $actor, WorkSite $site, array $uploads): void
    {
        foreach ([WorkSiteAttachmentType::Kmz->value => $uploads['kmz'] ?? null, WorkSiteAttachmentType::Synoptic->value => $uploads['synoptic'] ?? null] as $type => $file) {
            if ($file === null) {
                continue;
            }

            $previous = $site->attachments()->where('type', $type)->with('file')->get();
            $stored = $this->files->handle($file);

            $site->attachments()->create([
                'type' => $type,
                'stored_file_id' => $stored->id,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'uploaded_by' => $actor->id,
            ]);

            $previous->each(function (WorkSiteAttachment $attachment): void {
                $file = $attachment->file;
                $attachment->delete();
                $this->files->release($file);
            });
        }
    }

    /**
     * Tasks of those technicians that day which do not belong to this work site.
     *
     * @param  list<int>  $technicianIds
     * @return Builder<Task>
     */
    private function otherTasks(?WorkSite $site, array $technicianIds, CarbonImmutable $date): Builder
    {
        return Task::query()->whereIn('user_id', $technicianIds)->whereDate('date', $date)
            ->when($site, fn ($query, WorkSite $site) => $query->where(fn ($query) => $query->whereNull('work_site_id')->orWhere('work_site_id', '!=', $site->id)));
    }
}
