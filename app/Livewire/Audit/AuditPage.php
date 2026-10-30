<?php

namespace App\Livewire\Audit;

use App\Audit\ArchiveAudit;
use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\Audit\AuditTree;
use App\Audit\BoxName;
use App\Audit\BoxProgress;
use App\Audit\Drawer\DrawerRecheck;
use App\ClientPortal\ClientRemarks;
use App\DesignOfficeWorkflow\FieldDataGuard;
use App\Enums\AuditBoxKind;
use App\Enums\AuditMode;
use App\Enums\AuditSupport;
use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Concerns\AcknowledgesClientNotes;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditDrawerRecheck;
use App\Models\AuditRemark;
use App\Models\User;
use App\Support\DayPunchRules;
use App\Support\GeoDistance;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The page of one cabinet audit: progress, who is on site, control points and boxes, and the actions to configure,
 * add, finish and validate. The technician's work on a box happens in the offline screen; every change here goes
 * through the same operations as the phones.
 */
class AuditPage extends Component
{
    use AcknowledgesClientNotes;

    #[Locked]
    public string $cabinet = '';

    #[Url(as: 'kind', except: '')]
    public string $kindFilter = '';

    #[Url(as: 'support', except: '')]
    public string $supportFilter = '';

    /**
     * Where the person is now, sent by their phone to show the distance to each box. Kept only in the page, never saved.
     *
     * @var array{latitude: float, longitude: float}|null
     */
    public ?array $here = null;

    public bool $nearestFirst = false;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $newBox = '';

    public string $newBoxKind = '';

    public string $newPoint = '';

    /** Window open on the page: « point » (add a control point), « box » (add a box) or « navigate » (to the cabinet). */
    #[Locked]
    public string $window = '';

    public bool $configuring = false;

    #[Url(as: 'mode', except: '')]
    public string $defaultMode = '';

    #[Locked]
    public int $seenRevision = 0;

    #[Locked]
    public int $seenPhotos = 0;

    #[Locked]
    public int $seenDone = 0;

    /**
     * What each box looked like the last time the page checked (box id → short signature).
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $boxSignatures = [];

    /**
     * Boxes changed by a technician since the page was opened, marked « new » until the box is opened.
     *
     * @var list<int>
     */
    #[Locked]
    public array $newBoxes = [];

    /**
     * A technician whose previous day is still open goes back to the audit list, where opening the audit asks for
     * the end of that day first.
     */
    public function mount(string $cabinet, DayPunchRules $rules): void
    {
        $this->cabinet = BoxName::cabinetKey($cabinet);
        $audit = $this->audit();

        if ($audit !== null) {
            $this->authorize('work', $audit);

            if ($rules->previousOpen($this->user()) !== null) {
                $this->redirectRoute($audit->effectiveMode() === AuditMode::Optical ? 'audits.optical' : 'audits.full', navigate: true);

                return;
            }

            $this->markSeen($audit);
        } else {
            $this->authorize('awaitSetup', [Audit::class, $this->cabinet]);
        }
    }

    #[On('audit-configured')]
    public function configured(): void
    {
        $this->configuring = false;
    }

    /**
     * Opens one of the page windows: adding a control point or a box (people working on the audit) or the directions to
     * the cabinet.
     */
    public function openWindow(string $window): void
    {
        abort_unless(in_array($window, ['point', 'box', 'navigate'], true), 404);
        $this->authorize('work', $this->audit() ?? abort(404));
        $this->resetErrorBag();
        $this->reset('newBox', 'newBoxKind', 'newPoint');
        $this->window = $window;
    }

    public function closeWindow(): void
    {
        $this->window = '';
        $this->resetErrorBag();
    }

    public function addBox(AuditOperations $operations): void
    {
        $this->validate(['newBox' => ['required', 'string', 'max:'.BoxName::MAX_LENGTH], 'newBoxKind' => ['nullable', 'in:bpe,pbo,jd']]);

        $this->run(function (User $user) use ($operations): void {
            $operations->apply($user, $this->workable(), $this->operationId(), ['op' => 'box_add', 'name' => $this->newBox, 'kind' => $this->newBoxKind]);
            $this->reset('newBox', 'newBoxKind');
            $this->window = '';
        });
    }

    public function addPoint(AuditOperations $operations): void
    {
        $this->validate(['newPoint' => ['required', 'string', 'max:70']]);

        $this->run(function (User $user) use ($operations): void {
            $operations->apply($user, $this->workable(), $this->operationId(), ['op' => 'cp_add', 'name' => $this->newPoint]);
            $this->reset('newPoint');
            $this->window = '';
        });
    }

    public function finish(AuditOperations $operations, bool $validate = false): void
    {
        $this->run(function (User $user) use ($operations, $validate): void {
            $audit = $this->workable();

            if (! $audit->isFinished()) {
                $operations->apply($user, $audit, $this->operationId(), ['op' => 'finish']);
            }

            if ($validate) {
                $operations->apply($user, $audit, $this->operationId(), ['op' => 'validate']);
            }
        });
    }

    public function askFinish(bool $validate = false): void
    {
        $audit = $this->workable();
        $this->authorize('work', $audit);

        $open = $audit->boxes()->whereNull('removed_at')->get()->reject(fn (AuditBox $box) => $box->isFinal())->count();
        $message = $open > 0 ? __('audit.confirm_finish_open', ['count' => $open]) : __($validate ? 'audit.confirm_finish_validate' : 'audit.confirm_finish');

        $this->dispatch('confirm-action', message: $message, method: 'finish', params: [$validate], componentId: $this->getId());
    }

    public function validateAudit(AuditOperations $operations): void
    {
        $this->run(fn (User $user) => $operations->apply($user, $this->workable(), $this->operationId(), ['op' => 'validate']));
    }

    /**
     * The design office is told, from time to time, that the audit moved on while they were looking at it.
     */
    public function checkUpdates(): void
    {
        $audit = $this->audit();

        if ($audit === null || $audit->revision <= $this->seenRevision) {
            return;
        }

        if ($this->user()->hasRole(Role::DesignOffice->value)) {
            $signatures = $this->signatures($audit);

            foreach ($signatures as $id => $signature) {
                if (($this->boxSignatures[$id] ?? null) !== $signature && ! in_array($id, $this->newBoxes, true)) {
                    $this->newBoxes[] = $id;
                }
            }

            $this->dispatch('toast', message: __('audit.be_update', [
                'cabinet' => $audit->cabinet_key, 'photos' => max(0, $audit->photos()->count() - $this->seenPhotos), 'boxes' => max(0, $this->finishedBoxes($audit) - $this->seenDone),
            ]));
        }

        $this->markSeen($audit);
    }

    /**
     * The phone gives its position (only a usable fix is kept).
     */
    public function locate(float $latitude, float $longitude, float $accuracy): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $accuracy < 0 || $accuracy > 500) {
            return;
        }

        $this->here = ['latitude' => $latitude, 'longitude' => $longitude];
    }

    public function toggleNearest(): void
    {
        $this->nearestFirst = ! $this->nearestFirst;
    }

    /**
     * Distance in metres from the person to a box (its planned position, else the one confirmed on site).
     */
    private function distanceTo(AuditBox $box): ?int
    {
        $latitude = $box->latitude ?? $box->confirmed_latitude;
        $longitude = $box->longitude ?? $box->confirmed_longitude;

        if ($this->here === null || $latitude === null || $longitude === null) {
            return null;
        }

        return GeoDistance::metres($this->here['latitude'], $this->here['longitude'], (float) $latitude, (float) $longitude);
    }

    public bool $archiving = false;

    public string $archiveCode = '';

    public function askArchive(): void
    {
        $this->authorize('manage', Audit::class);
        $this->archiving = true;
        $this->archiveCode = '';
        $this->resetErrorBag();
    }

    public function cancelArchive(): void
    {
        $this->archiving = false;
        $this->archiveCode = '';
    }

    /**
     * Archives the audit once the person has typed the reference of the cabinet.
     */
    public function archive(ArchiveAudit $archive): mixed
    {
        $this->authorize('manage', Audit::class);
        $audit = $this->audit();
        abort_if($audit === null, 404);

        if (mb_strtoupper(trim($this->archiveCode)) !== mb_strtoupper($audit->cabinet_key)) {
            $this->addError('archiveCode', __('audit.archive.mismatch'));

            return null;
        }

        $user = $this->user();

        try {
            $archive->handle($user, $audit);
        } catch (AuditOperationException $exception) {
            $this->addError('archiveCode', __('audit.errors.'.$exception->reason));

            return null;
        }

        return $this->redirectRoute('audits.'.($audit->effectiveMode() === AuditMode::Optical ? 'optical' : 'full'), navigate: true);
    }

    public function render(BoxProgress $progress, ClientRemarks $remarks, AuditTree $tree, DrawerRecheck $drawer): View
    {
        $audit = $this->audit();
        $user = $this->user();

        if ($audit === null) {
            return view('livewire.audit.audit-page', ['audit' => null, 'isManager' => $user->can('manage', Audit::class)])
                ->layout('components.layouts.app', ['title' => $this->cabinet, 'page' => 'audits']);
        }

        $audit->load(['boxes.photos:id,audit_box_id,folder,subfolder', 'boxes.cables', 'boxes.remarks', 'controlPoints.photos:id,audit_control_point_id', 'plans.file', 'presences.user:id,name']);
        $mode = $audit->effectiveMode();
        $boxes = $audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved())->sort(fn (AuditBox $a, AuditBox $b) => strnatcasecmp($a->name, $b->name))->values();
        $words = array_filter(explode(' ', mb_strtolower(trim($this->search))));
        $presences = $audit->presences->filter(fn ($presence) => $presence->seen_at->gt(now()->subHours(12)))->values();
        $supportCounts = $boxes->countBy(fn (AuditBox $box) => $this->supportGroup($box));
        $supportFilter = $this->supportFilter !== '' && ($supportCounts[$this->supportFilter] ?? 0) > 0 ? $this->supportFilter : '';

        $rows = $boxes->filter(fn (AuditBox $box) => ($this->kindFilter === '' || $box->kind->value === $this->kindFilter)
            && ($supportFilter === '' || $this->supportGroup($box) === $supportFilter)
            && collect($words)->every(fn (string $word) => str_contains(mb_strtolower($box->name.' '.$box->support_number), $word)))
            ->map(fn (AuditBox $box) => [
                'box' => $box, 'percent' => $progress->percent($box, $mode),
                'unanswered' => $box->remarks->contains(fn (AuditRemark $remark) => $remark->author_side === 'office' && $remark->acknowledged_at === null),
                'distance' => $this->distanceTo($box),
                'details' => $this->details($box, $mode),
                'presences' => $presences->where('audit_box_id', $box->id)->values(),
            ])->values();

        $nearest = $this->nearestFirst && $this->here !== null;

        if ($nearest) {
            $rows = $rows->sortBy(fn (array $row) => $row['distance'] ?? PHP_INT_MAX)->values();
        }

        $kinds = AuditBoxKind::cases();
        $groups = $this->kindFilter === '' && ! $nearest
            ? collect($kinds)->map(fn (AuditBoxKind $kind) => ['kind' => $kind, 'rows' => $rows->filter(fn (array $row) => $row['box']->kind === $kind)->values()])->filter(fn (array $group) => $group['rows']->isNotEmpty())->values()
            : collect([['kind' => null, 'rows' => $rows]]);
        $hasCabinetPosition = $audit->cabinet_latitude !== null && $audit->cabinet_longitude !== null;

        return view('livewire.audit.audit-page', [
            'audit' => $audit,
            'auditMode' => $mode,
            'percent' => $progress->auditPercent($audit),
            'boxesTotal' => $boxes->count(),
            'boxesDone' => $boxes->filter(fn (AuditBox $box) => $box->isFinal())->count(),
            'groups' => $groups,
            'hasRows' => $rows->isNotEmpty(),
            'kinds' => $kinds,
            'kindCounts' => $boxes->countBy(fn (AuditBox $box) => $box->kind->value),
            'supportFilters' => array_filter(['aerial' => '🔌 ', 'underground' => '🕳 ', 'none' => ''], fn (string $icon, string $group) => $group !== 'none' || ($supportCounts['none'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH),
            'supportCounts' => $supportCounts,
            'activeSupportFilter' => $supportFilter,
            'points' => $audit->controlPoints->reject(fn ($point) => $mode === AuditMode::Optical && $point->kind === 'cleanup' && $point->photos->isEmpty()),
            'presences' => $presences,
            'isManager' => $user->can('manage', Audit::class),
            'locked' => ($audit->isValidated() && ! $user->can('manage', Audit::class)) || app(FieldDataGuard::class)->isFrozen($audit),
            'closureLink' => $audit->usesDesignOfficeWorkflow() && $user->can('viewClosure', $audit),
            'clientNotes' => $remarks->pendingFor($user, $audit),
            'deliverablesPath' => $user->can('open-feature', Feature::MediaLibrary) ? $tree->segment($audit->folder ?? $audit->cabinet_key) : null,
            'plansAfterWorks' => $audit->boxes()->whereHas('recheck', fn ($query) => $query->whereNotNull('recnok_file_id'))->count(),
            'drawerRecheck' => $mode === AuditMode::Optical && $drawer->planFor($audit) !== null,
            'drawerAfterWorks' => AuditDrawerRecheck::query()->where('audit_id', $audit->id)->whereNotNull('recnok_file_id')->exists(),
            'hasCabinetPosition' => $hasCabinetPosition,
            'navigationLinks' => $hasCabinetPosition ? array_map(fn (string $template) => str_replace([':lat', ':lng'], [(string) $audit->cabinet_latitude, (string) $audit->cabinet_longitude], $template), (array) config('audit.navigation')) : [],
        ])->layout('components.layouts.app', ['title' => $audit->cabinet_key, 'page' => 'audits', 'focus' => true]);
    }

    /**
     * Support family used by the filters: aerial (pole, facade), underground (chamber) or not set.
     */
    private function supportGroup(AuditBox $box): string
    {
        return match ($box->support) {
            AuditSupport::Pole, AuditSupport::Facade => 'aerial',
            AuditSupport::Chamber => 'underground',
            default => 'none',
        };
    }

    /**
     * Second line of a box: its type, its support (full mode) and its cables.
     */
    private function details(AuditBox $box, AuditMode $mode): string
    {
        $support = null;
        $cables = null;

        if ($mode === AuditMode::Optical) {
            $cables = $box->cables->isNotEmpty() ? trans_choice('audit.cables_count', $box->cables->count(), ['count' => $box->cables->count()]) : null;
        } else {
            $support = $box->support ? $box->support->label().($box->support_number ? ' '.$box->support_number : '') : __('audit.support_unknown');
            $cables = $box->cables->pluck('code')->filter()->implode(', ') ?: null;
        }

        return collect([$box->type, $support, $cables])->filter()->implode(' · ');
    }

    private function markSeen(Audit $audit): void
    {
        $this->seenRevision = $audit->revision;
        $this->seenPhotos = $audit->photos()->count();
        $this->seenDone = $this->finishedBoxes($audit);
        $this->boxSignatures = $this->signatures($audit);
    }

    /**
     * What the design office is told about on a box: photos, status, cables, confirmed position, notes, report and
     * messages from the technician.
     *
     * @return array<int, string>
     */
    private function signatures(Audit $audit): array
    {
        return $audit->boxes()->whereNull('removed_at')->with(['cables', 'remarks'])->withCount('photos')->get()->mapWithKeys(fn (AuditBox $box) => [$box->id => md5(implode('|', [
            $box->photos_count, $box->status?->value, $box->cables->map(fn ($cable) => $cable->code.$cable->capacity.$cable->fibre_type?->value.$cable->has_no_label)->implode(','),
            $box->confirmed_at?->timestamp, mb_strlen($box->note_before.$box->note_route.$box->note_tco), count($box->report_items ?? []),
            $box->remarks->where('author_side', 'tech')->count(),
        ]))])->all();
    }

    private function finishedBoxes(Audit $audit): int
    {
        return $audit->boxes()->whereNull('removed_at')->whereNull('returned_at')->whereIn('status', ['ok', 'anomaly', 'not_installed'])->count();
    }

    private function workable(): Audit
    {
        $audit = $this->audit() ?? abort(404);
        $this->authorize('work', $audit);

        return $audit;
    }

    private function audit(): ?Audit
    {
        return Audit::query()->where('cabinet_key', $this->cabinet)->first();
    }

    /**
     * @param  callable(User): mixed  $action
     */
    private function run(callable $action): void
    {
        $this->resetErrorBag('audit');

        try {
            $action($this->user());
        } catch (AuditOperationException $exception) {
            $this->addError('audit', __('audit.errors.'.$exception->reason));
        }

        $audit = $this->audit();

        if ($audit !== null) {
            $this->markSeen($audit);
        }
    }

    private function operationId(): string
    {
        return 'o_'.bin2hex(random_bytes(6));
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
