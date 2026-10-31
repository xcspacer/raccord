<?php

namespace App\Livewire\Planning;

use App\Audit\BoxName;
use App\Enums\AbsenceType;
use App\Enums\MediaPhase;
use App\Enums\Module;
use App\Enums\ProjectType;
use App\Enums\WorkSiteAttachmentType;
use App\Livewire\DayPunch\DeclareEndModal;
use App\MediaLibrary\MediaLibrary;
use App\MediaLibrary\TaskMediaTargets;
use App\Models\Absence;
use App\Models\Audit;
use App\Models\DayPunch;
use App\Models\MediaFile;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use App\Models\WorkSiteBox;
use App\Planning\NavigationPoints;
use App\Planning\TaskDetail;
use App\Settings\ModuleSettings;
use App\Support\DayPunchRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Detail of one of the signed-in person's own tasks: the day, what to do, who works with them,
 * the work site's files. The person is always the signed-in user, never a client-sent id.
 */
class TaskDetailModal extends Component
{
    public bool $open = false;

    #[Locked]
    public string $date = '';

    /**
     * Place chosen to drive to, with its position resolved on the server.
     *
     * @var array{name: string, latitude: float, longitude: float}|null
     */
    #[Locked]
    public ?array $navigation = null;

    #[On('task-detail-open')]
    public function openFor(string $date): void
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;
        abort_if($day === null || $day->format('Y-m-d') !== $date, 404);

        $this->date = $date;
        abort_unless($this->task($this->user()) !== null, 404);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->navigation = null;
    }

    /**
     * Opens the Waze / Google Maps choice for the cabinet or one of the person's boxes, when its position is known.
     */
    public function navigate(string $target, int $index, NavigationPoints $points): void
    {
        $user = $this->user();
        $site = $this->task($user)?->workSite;
        abort_if($site === null, 404);

        $boxes = array_column($this->boxTeams($site, $user), 'reference');
        $known = $points->for($site, $boxes);
        $name = $target === 'cabinet' ? (string) $site->cabinet_ref : ($boxes[$index] ?? '');
        $point = $target === 'cabinet' ? $known['cabinet'] : ($known['boxes'][$name] ?? null);
        abort_if($point === null || $name === '', 404);

        $this->navigation = ['name' => $name, 'latitude' => $point['latitude'], 'longitude' => $point['longitude']];
    }

    public function closeNavigation(): void
    {
        $this->navigation = null;
    }

    /**
     * « Ouvrir l'audit » of an audit task; a technician whose previous day is still open declares its end first.
     */
    public function openAudit(DayPunchRules $rules): mixed
    {
        $user = $this->user();
        $this->authorize('viewAny', Audit::class);
        $cabinet = $this->auditCabinet($this->task($user)?->workSite);
        abort_if($cabinet === null, 404);

        $previous = $rules->previousOpen($user);

        if ($previous !== null) {
            $this->open = false;
            $this->dispatch('declare-end-open', date: $previous->date->toDateString(), continueTo: route('audits.show', ['cabinet' => $cabinet], false))->to(DeclareEndModal::class);

            return null;
        }

        return $this->redirectRoute('audits.show', ['cabinet' => $cabinet], navigate: true);
    }

    /**
     * A technician removes a photo they sent by mistake, within 15 minutes.
     */
    public function deletePhoto(int $id, MediaLibrary $library): void
    {
        $user = $this->user();
        $file = MediaFile::query()->with(['file', 'folder.parent'])->findOrFail($id);
        $this->authorize('viewFromTask', $file);

        if (! $library->mayDeleteOwn($user, $file)) {
            $this->dispatch('toast', message: __('tasks.photos.delete_expired'));

            return;
        }

        $library->deleteFile($file);
    }

    public function render(TaskDetail $detail, TaskMediaTargets $targets, ModuleSettings $modules, NavigationPoints $points): View
    {
        $user = $this->user();
        $task = $this->open ? $this->task($user) : null;

        if ($task === null) {
            return view('livewire.planning.task-detail-modal', ['task' => null]);
        }

        $day = CarbonImmutable::parse($this->date);
        $site = $task->workSite;
        $boxes = $site === null ? [] : $this->boxTeams($site, $user);

        return view('livewire.planning.task-detail-modal', [
            'task' => $task,
            'day' => $day,
            'detail' => $task->displayDetail($detail),
            'absence' => $this->absenceType($user, $day),
            'punch' => DayPunch::query()->where('user_id', $user->id)->whereDate('date', $day)->first(),
            'team' => $site === null ? [] : $this->teamNames($site),
            'cabinet' => $site?->cabinet_ref,
            'boxes' => $boxes,
            'points' => $site === null ? ['cabinet' => null, 'boxes' => []] : $points->for($site, array_column($boxes, 'reference')),
            'auditCabinet' => $user->can('viewAny', Audit::class) ? $this->auditCabinet($site) : null,
            'navigationLinks' => $this->navigation === null ? [] : $this->navigationLinks($this->navigation),
            'files' => $site === null ? [] : $site->attachments->whereIn('type', [WorkSiteAttachmentType::Kmz, WorkSiteAttachmentType::Synoptic])->all(),
            'photoTargets' => $modules->isEnabled(Module::MediaLibrary) ? $this->photoTargets($task, $user, $targets) : [],
            'deleteWindow' => MediaLibrary::OWN_DELETE_MINUTES,
        ]);
    }

    /**
     * First names of the cabinet team (lead and partners), the signed-in person included.
     *
     * @return list<string>
     */
    private function teamNames(WorkSite $site): array
    {
        return $site->members->map(fn (User $member) => $this->firstName($member))->unique()->values()->all();
    }

    /**
     * The boxes of the work site with the other people working on each; a box nobody else works on is left out.
     *
     * @return list<array{number: int, reference: string, people: list<string>}>
     */
    private function boxTeams(WorkSite $site, User $user): array
    {
        return $site->boxes->values()
            ->map(fn (WorkSiteBox $box, int $index) => [
                'number' => $index + 1,
                'reference' => $box->reference,
                'people' => collect([$box->technician, ...$box->partners])->filter()->reject(fn (User $person) => $person->id === $user->id)->map(fn (User $person) => $this->firstName($person))->unique()->values()->all(),
            ])
            ->reject(fn (array $box) => $box['people'] === [])
            ->values()
            ->all();
    }

    /**
     * Photo folders of the task with their before / after photos, newest first.
     *
     * @return list<array{key: string, label: string, phases: list<array{phase: MediaPhase, files: Collection<int, MediaFile>}>}>
     */
    private function photoTargets(Task $task, User $user, TaskMediaTargets $targets): array
    {
        return array_map(function (array $target) use ($targets): array {
            $folder = $targets->folder($target, create: false);

            return [
                'key' => $target['key'],
                'label' => $target['label'],
                'phases' => array_map(fn (MediaPhase $phase) => [
                    'phase' => $phase,
                    'files' => $folder === null ? new Collection : $folder->files()->with('file')->where('phase', $phase->value)->orderByDesc('uploaded_at')->orderByDesc('id')->get(),
                ], MediaPhase::cases()),
            ];
        }, $targets->for($task, $user));
    }

    /**
     * Cabinet key of an audit work site.
     */
    private function auditCabinet(?WorkSite $site): ?string
    {
        return $site?->project_type === ProjectType::Audit && $site->cabinet_ref ? (BoxName::cabinetKey($site->cabinet_ref) ?: null) : null;
    }

    /**
     * @param  array{name: string, latitude: float, longitude: float}  $navigation
     * @return array<string, string>
     */
    private function navigationLinks(array $navigation): array
    {
        return collect((array) config('audit.navigation'))
            ->map(fn (string $template) => strtr($template, [':lat' => (string) $navigation['latitude'], ':lng' => (string) $navigation['longitude']]))
            ->all();
    }

    private function firstName(User $person): string
    {
        return explode(' ', trim($person->name))[0];
    }

    private function absenceType(User $user, CarbonImmutable $day): ?AbsenceType
    {
        return Absence::query()->where('user_id', $user->id)->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->latest('id')->first()?->type;
    }

    private function task(User $user): ?Task
    {
        return Task::query()
            ->with(['workSite.client', 'workSite.agency', 'workSite.members', 'workSite.boxes.technician', 'workSite.boxes.partners', 'workSite.attachments'])
            ->where('user_id', $user->id)
            ->whereDate('date', $this->date)
            ->first();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
