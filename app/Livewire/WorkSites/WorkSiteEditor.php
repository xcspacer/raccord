<?php

namespace App\Livewire\WorkSites;

use App\Actions\Files\UploadRules;
use App\Actions\Planning\PlanAuditWorkSites;
use App\Actions\Planning\ResolveClient;
use App\Actions\Planning\SaveDailyWorkSite;
use App\Audit\AuditOperationException;
use App\Audit\BoxName;
use App\Audit\ConfigureAudit;
use App\Audit\Import\KmlReader;
use App\Audit\Import\SynopticListParser;
use App\Audit\Import\SynopticPdfReader;
use App\Audit\OpenAudit;
use App\Enums\AuditMode;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\WorkSiteMemberRole;
use App\Enums\WorkSiteStatus;
use App\Livewire\Forms\DailyWorkSiteForm;
use App\Livewire\Forms\WorkSiteForm;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\CapacityCalculator;
use App\Support\EffectiveAgency;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Work site window (create / edit): crews and capacity for agencies that plan in detail, one-day
 * work sites with a crew, boxes and files for agencies that plan day by day.
 */
class WorkSiteEditor extends Component
{
    use WithFileUploads;

    private const int MAX_KILOBYTES = 15360;

    private const array AUDIT_FIELDS = ['kmzFile', 'synopticFile', 'auditSro', 'drawerPlan', 'boxPlans', 'showAuditFiles', 'synopticText'];

    public WorkSiteForm $form;

    public DailyWorkSiteForm $daily;

    public bool $open = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public int $loadedVersion = 0;

    public ?TemporaryUploadedFile $kmzFile = null;

    public ?TemporaryUploadedFile $synopticFile = null;

    /** Audit documents collected by the audit form and handed to the audit of the cabinet. */
    public string $auditSro = '';

    public ?TemporaryUploadedFile $drawerPlan = null;

    /** @var list<TemporaryUploadedFile> */
    public array $boxPlans = [];

    /** A cabinet already configured hides its documents until they are asked for. */
    public bool $showAuditFiles = false;

    /** Text read from the synoptic chosen on the audit form. */
    #[Locked]
    public string $synopticText = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('viewAny', WorkSite::class) || $this->actor()->can('create', WorkSite::class), 403);
    }

    #[On('work-site-open')]
    public function openFor(?int $id = null): void
    {
        $this->reset(self::AUDIT_FIELDS);
        $this->form->reset();
        $this->daily->reset();
        $this->resetValidation();

        if ($id === null) {
            $this->authorize('create', WorkSite::class);

            $this->editingId = null;
            $this->form->agencyId = (string) ($this->effectiveAgency()?->id);
            $this->daily->date = CarbonImmutable::today(config('platform.timezone'))->toDateString();
            $this->open = $this->form->agencyId !== '';

            return;
        }

        $site = $this->scopedWorkSites()->with(['client', 'members', 'boxes.partners'])->findOrFail($id);
        $this->authorize('update', $site);

        $this->editingId = $site->id;
        $this->loadedVersion = $site->version;
        $this->form->agencyId = (string) $site->agency_id;

        if ($site->agency?->planning_mode === PlanningMode::Daily) {
            $this->fillDaily($site);
            $this->auditSro = $site->project_type === ProjectType::Audit ? (string) $this->auditOf($site->cabinet_ref)?->sro : '';
        } else {
            $this->fillDetailed($site);
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->editingId = null;
        $this->reset(self::AUDIT_FIELDS);
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if ($property === 'daily.cabinetRef') {
            $this->showAuditFiles = false;
        }

        if ($property === 'daily.boxCount') {
            $this->resizeBoxes();
        }

        if (preg_match('/^daily\.boxes\.(\d+)\.partnerCount$/', $property, $match) === 1) {
            $this->resizeBoxPartners((int) $match[1]);
        }
    }

    public function addTeam(): void
    {
        if ($this->editingId === null && count($this->daily->teams) < 20) {
            $this->daily->teams[] = ['lead' => '', 'partner' => ''];
        }
    }

    public function removeTeam(int $index): void
    {
        if ($this->editingId === null && count($this->daily->teams) > 1) {
            unset($this->daily->teams[$index]);
            $this->daily->teams = array_values($this->daily->teams);
        }
    }

    public function addPartner(): void
    {
        $id = $this->daily->partnerToAdd;

        if ($id !== '' && $id !== $this->daily->lead && ! in_array($id, $this->daily->partners, true) && $this->technicians()->contains('id', (int) $id)) {
            $this->daily->partners[] = $id;
        }

        $this->daily->partnerToAdd = '';
    }

    public function removePartner(string $id): void
    {
        $this->daily->partners = array_values(array_diff($this->daily->partners, [$id]));
    }

    /**
     * The synoptic of an audit is read as soon as it is chosen, to say how many boxes it lists.
     */
    public function updatedSynopticFile(SynopticPdfReader $pdf): void
    {
        $this->synopticText = '';

        if ($this->synopticFile === null || ! $this->isAudit()) {
            return;
        }

        $this->validate(['synopticFile' => UploadRules::document(UploadRules::SYNOPTIC, self::MAX_KILOBYTES)]);
        $contents = (string) file_get_contents($this->synopticFile->getRealPath());
        $this->synopticText = str_starts_with($contents, '%PDF') ? $pdf->text($contents) : mb_substr($contents, 0, 300000);
    }

    /**
     * « PM en cours » chip: replans a cabinet already under way. The dates become next week (Monday to Friday)
     * when none is chosen yet, and the teams of its last planned day are taken again.
     */
    public function pickPm(string $reference): void
    {
        $this->authorize('create', WorkSite::class);
        abort_unless($this->editingId === null && $this->isAudit(), 404);

        $key = BoxName::cabinetKey($reference);
        $chip = collect($this->pmsInProgress())->firstWhere('key', $key);
        abort_if($chip === null, 404);

        $this->daily->cabinetRef = $chip['reference'];
        $this->showAuditFiles = false;
        $this->auditSro = (string) $this->auditOf($chip['reference'])?->sro;
        $today = CarbonImmutable::today(config('platform.timezone'));

        if ($this->daily->endDate === '' && in_array($this->daily->date, ['', $today->toDateString()], true)) {
            $monday = $today->startOfWeek()->addWeek();
            $this->daily->date = $monday->toDateString();
            $this->daily->endDate = $monday->addDays(4)->toDateString();
        }

        $this->prefillTeams($key);
    }

    public function toggleAuditFiles(): void
    {
        $this->showAuditFiles = ! $this->showAuditFiles;
    }

    public function save(CapacityCalculator $capacity, SaveDailyWorkSite $saveDaily): void
    {
        $this->authorizeSave();

        if ($this->isDaily() && $this->isAudit()) {
            $this->validateAudit();
            $preview = app(PlanAuditWorkSites::class)->preview($this->existingSite(), $this->auditTeams(), CarbonImmutable::parse($this->daily->date), $this->auditEndDate());

            if ($preview['reassigned']->isNotEmpty() && $preview['absent']->isEmpty()) {
                $this->dispatch('confirm-action', message: __('work_sites.confirm_reassign', ['names' => $preview['reassigned']->implode(', ')]), method: 'confirmSave', componentId: $this->getId());

                return;
            }

            $this->persistAudit();

            return;
        }

        if ($this->isDaily()) {
            $this->validateDaily();
            $reassigned = $saveDaily->reassignedTechnicians($this->existingSite(), $saveDaily->technicianIds($this->dailyInput()), CarbonImmutable::parse($this->daily->date));

            if ($reassigned->isNotEmpty()) {
                $this->dispatch('confirm-action', message: __('work_sites.confirm_reassign', ['names' => $reassigned->implode(', ')]), method: 'confirmSave', componentId: $this->getId());

                return;
            }

            $this->persistDaily();

            return;
        }

        $this->form->validate();
        $overrun = $this->overrun($capacity);

        if ($overrun !== null) {
            $this->dispatch('confirm-action',
                message: __('work_sites.confirm_capacity', ['peak' => $overrun['peak'], 'capacity' => $overrun['capacity'], 'days' => $overrun['days']]),
                method: 'confirmSave',
                componentId: $this->getId(),
            );

            return;
        }

        $this->persistDetailed();
    }

    public function confirmSave(): void
    {
        $this->authorizeSave();

        if ($this->isDaily() && $this->isAudit()) {
            $this->validateAudit();
            $this->persistAudit();

            return;
        }

        if ($this->isDaily()) {
            $this->validateDaily();
            $this->persistDaily();

            return;
        }

        $this->form->validate();
        $this->persistDetailed();
    }

    public function render(CapacityCalculator $capacity): View
    {
        $agency = $this->selectedAgency();

        return view('livewire.work-sites.work-site-editor', [
            'agencies' => Agency::query()->orderBy('sort_order')->get(),
            'statuses' => WorkSiteStatus::cases(),
            'canChangeStatus' => $this->canChangeStatus(),
            'clients' => $this->open ? Client::query()->where('is_active', true)->orderBy('name')->pluck('name') : collect(),
            'isDaily' => $this->isDaily(),
            'isAudit' => $this->isAudit(),
            'plannedPms' => $this->open && $this->isAudit() ? WorkSite::query()->where('project_type', 'audit')->whereNotNull('cabinet_ref')->distinct()->orderBy('cabinet_ref')->pluck('cabinet_ref') : collect(),
            'overrun' => $this->open && ! $this->isDaily() ? $this->overrunPreview($capacity) : null,
            'technicians' => $this->open && $this->isDaily() ? $this->technicians() : collect(),
            'defaultClient' => $agency?->defaultClient?->name,
            'existing' => $this->editingId ? $this->existingSite()?->attachments()->get()->keyBy(fn ($attachment) => $attachment->type->value) : collect(),
            'cabinetLabelKey' => $this->daily->project === 'nro' ? 'work_sites.fields.cabinet_nro' : 'work_sites.fields.cabinet',
        ] + ($this->open && $this->isDaily() && $this->isAudit() ? $this->auditFormData() : []));
    }

    private function isAudit(): bool
    {
        return str_starts_with($this->daily->project, 'audit');
    }

    private function auditMode(): ?AuditMode
    {
        return AuditMode::tryFrom(substr($this->daily->project, 6));
    }

    private function validateAudit(): void
    {
        $this->daily->validate();

        $this->validate([
            'auditSro' => ['nullable', 'string', 'max:60'],
            'kmzFile' => ['nullable', ...UploadRules::document(UploadRules::MAP, self::MAX_KILOBYTES)],
            'synopticFile' => ['nullable', ...UploadRules::document(UploadRules::SYNOPTIC, self::MAX_KILOBYTES)],
            'drawerPlan' => ['nullable', ...UploadRules::document(UploadRules::PLAN, self::MAX_KILOBYTES)],
            'boxPlans' => ['array', 'max:300'],
            'boxPlans.*' => UploadRules::document(UploadRules::PLAN, self::MAX_KILOBYTES),
        ]);
    }

    /**
     * Hands the documents of the form to the audit of the cabinet (opened when needed), as « Configurer » does on the
     * audit page. The planning never opens an audit by itself: only a mode or a document does.
     *
     * @return bool False when the audit refused the configuration (it can be done again from the audit page).
     */
    private function configureAudit(string $pm): bool
    {
        $mode = $this->auditMode();
        $sro = trim($this->auditSro);
        $map = $this->kmzFile === null ? null : app(KmlReader::class)->read((string) file_get_contents($this->kmzFile->getRealPath()));
        $listed = $this->synopticText === '' ? [] : app(SynopticListParser::class)->parse($this->synopticText);

        $documents = $sro !== '' || $this->kmzFile !== null || $this->synopticFile !== null || $this->drawerPlan !== null || $this->boxPlans !== [];

        if (! $this->actor()->can('manage', Audit::class)) {
            return ! $documents;
        }

        if ($mode === null && ! $documents) {
            return true;
        }

        try {
            $actor = $this->actor();
            $audit = $this->auditOf($pm) ?? app(OpenAudit::class)->handle($actor, $pm);
            app(ConfigureAudit::class)->handle($actor, $audit, ['sro' => $sro === '' ? null : $sro, 'mode' => $mode?->value], $listed, $map, [
                'synoptic' => $this->synopticFile, 'map' => $this->kmzFile, 'drawer' => $this->drawerPlan, 'boxes' => $this->boxPlans,
            ]);
        } catch (AuditOperationException) {
            return false;
        }

        return true;
    }

    private function auditOf(?string $reference): ?Audit
    {
        $key = BoxName::cabinetKey((string) $reference);

        return $key === '' ? null : Audit::query()->where('cabinet_key', $key)->first();
    }

    /**
     * Cabinets under way: audits not validated yet, with their progress, and cabinets planned as audit work sites.
     *
     * @return list<array{key: string, reference: string, boxes: int|null, done: int|null}>
     */
    private function pmsInProgress(): array
    {
        $chips = [];

        Audit::query()->whereNull('validated_at')->with('boxes:id,audit_id,status,returned_at,removed_at')->get(['id', 'cabinet_key'])
            ->each(function (Audit $audit) use (&$chips): void {
                $boxes = $audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved());
                $chips[$audit->cabinet_key] = ['key' => $audit->cabinet_key, 'reference' => $audit->cabinet_key, 'boxes' => $boxes->count(), 'done' => $boxes->filter(fn (AuditBox $box) => $box->isFinal())->count()];
            });

        WorkSite::query()->where('project_type', ProjectType::Audit->value)->whereNotNull('cabinet_ref')->orderBy('id')->pluck('cabinet_ref')
            ->each(function (string $reference) use (&$chips): void {
                $key = BoxName::cabinetKey($reference);

                if ($key !== '') {
                    $chips[$key] = ['key' => $key, 'reference' => $reference, 'boxes' => $chips[$key]['boxes'] ?? null, 'done' => $chips[$key]['done'] ?? null];
                }
            });

        uksort($chips, 'strnatcasecmp');

        return array_values($chips);
    }

    /**
     * Teams of the last day the cabinet was planned, for an empty form.
     */
    private function prefillTeams(string $key): void
    {
        $sites = WorkSite::query()->with('members')->where('project_type', ProjectType::Audit->value)->whereNotNull('cabinet_ref')->whereNotNull('lead_technician_id')->get()
            ->filter(fn (WorkSite $site) => BoxName::cabinetKey((string) $site->cabinet_ref) === $key);

        if ($sites->isEmpty()) {
            return;
        }

        $last = $sites->max(fn (WorkSite $site) => $site->starts_on->toDateString());
        $available = $this->technicians()->pluck('id')->all();
        $teams = $sites->filter(fn (WorkSite $site) => $site->starts_on->toDateString() === $last && in_array($site->lead_technician_id, $available, true))->sortBy('id')
            ->map(fn (WorkSite $site) => [
                'lead' => (string) $site->lead_technician_id,
                'partner' => (string) ($site->members->first(fn (User $member) => $member->getRelation('pivot')->role === WorkSiteMemberRole::Partner->value && $member->id !== $site->lead_technician_id && in_array($member->id, $available, true))->id ?? ''),
            ])->values();

        if ($teams->isEmpty()) {
            return;
        }

        if ($this->daily->teams[0]['lead'] === '') {
            $this->daily->teams[0] = $teams[0];
        }

        if (count($this->daily->teams) === 1) {
            $this->daily->teams = [...$this->daily->teams, ...$teams->slice(1)->values()->all()];
        }
    }

    /**
     * « PM en cours » chips, what is known of the typed cabinet and what was read from the chosen documents.
     *
     * @return array<string, mixed>
     */
    private function auditFormData(): array
    {
        $audit = $this->auditOf($this->daily->cabinetRef)?->loadMissing('boxes');
        $boxes = $audit?->boxes->reject(fn (AuditBox $box) => $box->isRemoved()) ?? collect();
        $configured = $audit !== null && ($audit->synoptic_file_id !== null || $audit->sro !== null);
        $listed = $this->synopticText === '' ? [] : app(SynopticListParser::class)->parse($this->synopticText);
        $kinds = collect($listed)->countBy(fn (array $box) => $box['kind']->value);

        return [
            'canConfigureAudit' => $this->actor()->can('manage', Audit::class),
            'pmsInProgress' => $this->editingId === null ? $this->pmsInProgress() : [],
            'pmKey' => BoxName::cabinetKey($this->daily->cabinetRef),
            'pmAudit' => $audit === null ? null : ['sro' => $audit->sro, 'boxes' => $boxes->count(), 'done' => $boxes->filter(fn (AuditBox $box) => $box->isFinal())->count(), 'configured' => $configured],
            'auditFilesShown' => ! $configured || $this->showAuditFiles,
            'synopticSummary' => match (true) {
                $this->synopticFile !== null => $listed === [] ? '⚠ '.__('audit.configure.nothing_found') : '✅ '.__('audit.configure.boxes_found', ['count' => count($listed), 'bpe' => $kinds['bpe'] ?? 0, 'pbo' => $kinds['pbo'] ?? 0, 'joint' => $kinds['jd'] ?? 0]),
                $audit?->synoptic_name !== null => __('work_sites.audit_files.synoptic_already', ['name' => $audit->synoptic_name, 'count' => $boxes->count()]),
                default => null,
            },
            'mapSummary' => $this->mapSummary(collect($listed)->pluck('name')->merge($boxes->pluck('name'))->all()),
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function mapSummary(array $names): ?string
    {
        if ($this->kmzFile === null) {
            return null;
        }

        $map = app(KmlReader::class)->read((string) file_get_contents($this->kmzFile->getRealPath()));

        if ($map === null) {
            return '⚠ '.__('work_sites.audit_files.map_unreadable');
        }

        if ($map['boxes'] === [] && $map['cabinet'] === null) {
            return '⚠ '.__('audit.configure.map_unreadable');
        }

        $cabinet = $map['cabinet'] === null ? '' : ' · '.__('audit.configure.cabinet_found');

        if ($names === []) {
            return '✅ '.trans_choice('work_sites.audit_files.positions', count($map['boxes']), ['count' => count($map['boxes'])]).$cabinet;
        }

        $located = collect(array_keys($map['boxes']))->filter(fn (string $point) => collect($names)->contains(fn (string $name) => KmlReader::sameBox($name, $point)))->count();

        return '✅ '.__('audit.configure.map_read', ['located' => $located, 'total' => count($names)]).$cabinet;
    }

    private function auditEndDate(): ?CarbonImmutable
    {
        return $this->daily->endDate === '' ? ($this->editingId === null ? CarbonImmutable::parse($this->daily->date) : null) : CarbonImmutable::parse($this->daily->endDate);
    }

    /**
     * Teams with a lead technician; a person never appears twice in the same team.
     *
     * @return list<array{lead: int, partners: list<int>}>
     */
    private function auditTeams(): array
    {
        return collect($this->daily->teams)
            ->filter(fn (array $team) => $team['lead'] !== '')
            ->map(fn (array $team) => [
                'lead' => (int) $team['lead'],
                'partners' => $team['partner'] !== '' && $team['partner'] !== $team['lead'] ? [(int) $team['partner']] : [],
            ])
            ->values()
            ->all();
    }

    private function persistAudit(): void
    {
        $agency = $this->selectedAgency();
        abort_unless($agency?->planning_mode === PlanningMode::Daily, 422);

        $site = $this->existingSite();

        if ($site !== null && $site->version !== $this->loadedVersion) {
            $this->addError('daily.cabinetRef', __('work_sites.conflict'));

            return;
        }

        $count = app(PlanAuditWorkSites::class)->handle(
            $this->actor(), $agency, $site, trim($this->daily->cabinetRef), $this->auditMode(), $this->auditTeams(),
            CarbonImmutable::parse($this->daily->date), $this->auditEndDate(), trim($this->daily->notes) ?: null,
        );

        $configured = $this->configureAudit(trim($this->daily->cabinetRef));

        $this->finish($site === null ? trans_choice('work_sites.audit_created', $count, ['count' => $count]) : __('work_sites.saved'));

        if (! $configured) {
            $this->dispatch('toast', message: __('work_sites.audit_files.setup_later'));
        }
    }

    private function isDaily(): bool
    {
        return $this->selectedAgency()?->planning_mode === PlanningMode::Daily;
    }

    private function selectedAgency(): ?Agency
    {
        return ctype_digit($this->form->agencyId) ? Agency::query()->with('defaultClient')->find((int) $this->form->agencyId) : null;
    }

    private function existingSite(): ?WorkSite
    {
        return $this->editingId === null ? null : $this->scopedWorkSites()->find($this->editingId);
    }

    private function persistDetailed(): void
    {
        $agency = $this->selectedAgency();
        abort_unless($agency?->planning_mode === PlanningMode::Detailed, 422);

        $attributes = [
            'agency_id' => $agency->id,
            'client_id' => app(ResolveClient::class)->handle($this->form->client)->id,
            'city' => trim($this->form->city),
            'starts_on' => $this->form->startsOn,
            'ends_on' => $this->form->endsOn,
            'crew_count' => max(1, (int) $this->form->crews),
            'status' => $this->status(),
            'site_manager' => trim($this->form->manager) ?: null,
            'notes' => trim($this->form->notes) ?: null,
        ];

        if ($this->editingId === null) {
            WorkSite::query()->create($attributes + ['created_by' => $this->actor()->id]);
            $message = __('work_sites.created');
        } else {
            $site = $this->scopedWorkSites()->lockForUpdate()->findOrFail($this->editingId);

            if ($site->version !== $this->loadedVersion) {
                $this->addError('form.city', __('work_sites.conflict'));

                return;
            }

            if ($site->status->value !== $this->form->status) {
                $this->authorize('changeStatus', $site);
            }

            $site->update($attributes + ['version' => $site->version + 1]);
            $message = __('work_sites.saved');
        }

        $this->finish($message);
    }

    private function persistDaily(): void
    {
        $agency = $this->selectedAgency();
        abort_unless($agency?->planning_mode === PlanningMode::Daily, 422);

        $site = $this->existingSite();

        if ($site !== null && $site->version !== $this->loadedVersion) {
            $this->addError('daily.city', __('work_sites.conflict'));

            return;
        }

        $uploads = ['kmz' => $this->kmzFile, 'synoptic' => $this->synopticFile];

        app(SaveDailyWorkSite::class)->handle($this->actor(), $site, $agency, $this->dailyInput(), $uploads);

        $this->finish($site === null ? __('work_sites.created') : __('work_sites.saved'));
    }

    private function validateDaily(): void
    {
        $this->daily->validate();

        $this->validate([
            'kmzFile' => ['nullable', ...UploadRules::document(UploadRules::MAP, self::MAX_KILOBYTES)],
            'synopticFile' => ['nullable', ...UploadRules::document(UploadRules::WORK_SITE_SYNOPTIC, self::MAX_KILOBYTES)],
        ]);

        if ($this->clientName() === null) {
            throw ValidationException::withMessages(['daily.client' => __('work_sites.daily_required')]);
        }
    }

    private function clientName(): ?string
    {
        return trim($this->daily->client) ?: $this->selectedAgency()?->defaultClient?->name;
    }

    /**
     * @return array{date: CarbonImmutable, client: string, project: ProjectType, lead: int, partners: list<int>, city: string, cabinet_ref: string|null, notes: string|null, boxes: list<array{reference: string, technician_id: int|null, partners: list<int>}>}
     */
    private function dailyInput(): array
    {
        $daily = $this->daily;

        return [
            'date' => CarbonImmutable::parse($daily->date),
            'client' => (string) $this->clientName(),
            'project' => ProjectType::from($daily->project),
            'lead' => (int) $daily->lead,
            'partners' => array_map('intval', $daily->partners),
            'city' => trim($daily->city),
            'cabinet_ref' => trim($daily->cabinetRef) ?: null,
            'notes' => trim($daily->notes) ?: null,
            'boxes' => array_map(fn (array $box) => [
                'reference' => $box['reference'],
                'technician_id' => $box['technicianId'] === '' ? null : (int) $box['technicianId'],
                'partners' => array_map('intval', array_filter($box['partners'])),
            ], $daily->boxes),
        ];
    }

    private function finish(string $message): void
    {
        $this->close();
        $this->dispatch('work-site-saved');
        $this->dispatch('toast', message: $message);
    }

    private function fillDetailed(WorkSite $site): void
    {
        $this->form->client = (string) $site->client?->name;
        $this->form->city = $site->city;
        $this->form->startsOn = $site->starts_on->toDateString();
        $this->form->endsOn = $site->ends_on->toDateString();
        $this->form->crews = (string) $site->crew_count;
        $this->form->status = $site->status->value;
        $this->form->manager = (string) $site->site_manager;
        $this->form->notes = (string) $site->notes;
    }

    private function fillDaily(WorkSite $site): void
    {
        $this->daily->project = match ($site->project_type) {
            ProjectType::Nro => 'nro',
            ProjectType::Audit => $site->audit_mode === null ? 'audit' : 'audit:'.$site->audit_mode->value,
            default => 'swap',
        };
        $this->daily->date = $site->starts_on->toDateString();
        $this->daily->client = (string) $site->client?->name;
        $this->daily->lead = (string) $site->lead_technician_id;
        $this->daily->partners = $site->members->filter(fn (User $member) => $member->getRelation('pivot')->role === WorkSiteMemberRole::Partner->value)->pluck('id')->map(fn (int $id) => (string) $id)->values()->all();
        $this->daily->city = $site->city;
        $this->daily->cabinetRef = (string) $site->cabinet_ref;
        $this->daily->notes = (string) $site->notes;
        $this->daily->teams = [['lead' => $this->daily->lead, 'partner' => $this->daily->partners[0] ?? '']];
        $this->daily->boxCount = (string) $site->boxes->count();
        $this->daily->boxes = $site->boxes->map(fn ($box) => [
            'reference' => $box->reference,
            'technicianId' => (string) $box->technician_id,
            'partnerCount' => (string) $box->partners->count(),
            'partners' => $box->partners->pluck('id')->map(fn (int $id) => (string) $id)->values()->all(),
        ])->all();
    }

    private function resizeBoxes(): void
    {
        $count = max(0, min(50, (int) $this->daily->boxCount));
        $boxes = array_slice($this->daily->boxes, 0, $count);

        while (count($boxes) < $count) {
            $boxes[] = ['reference' => '', 'technicianId' => '', 'partnerCount' => '0', 'partners' => []];
        }

        $this->daily->boxes = $boxes;
    }

    private function resizeBoxPartners(int $index): void
    {
        if (! isset($this->daily->boxes[$index])) {
            return;
        }

        $count = max(0, min(10, (int) $this->daily->boxes[$index]['partnerCount']));
        $partners = array_slice($this->daily->boxes[$index]['partners'], 0, $count);

        while (count($partners) < $count) {
            $partners[] = '';
        }

        $this->daily->boxes[$index]['partners'] = $partners;
    }

    /**
     * People who can be assigned on this agency's planning: still in the company, in the planning.
     *
     * @return Collection<int, User>
     */
    private function technicians(): Collection
    {
        $agencyId = (int) $this->form->agencyId;

        return User::query()
            ->with('vehicle')
            ->where(fn (Builder $query) => $query->where('is_plannable', true)->orWhereHas('roles', fn (Builder $query) => $query->where('name', 'technician')))
            ->where('is_hidden_from_planning', false)
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>', CarbonImmutable::today(config('platform.timezone'))))
            ->where(fn (Builder $query) => $query->where('agency_id', $agencyId)->orWhere('all_agencies', true))
            ->orderBy('planning_order')->orderBy('name')->get();
    }

    /**
     * @return array{peak: int, capacity: int, days: int, first: CarbonImmutable, last: CarbonImmutable}|null
     */
    private function overrun(CapacityCalculator $capacity): ?array
    {
        $agency = $this->selectedAgency();

        return $agency === null ? null : $capacity->overrunFor(
            $agency,
            CarbonImmutable::parse($this->form->startsOn),
            CarbonImmutable::parse($this->form->endsOn),
            max(1, (int) $this->form->crews),
            WorkSiteStatus::from($this->status()),
            $this->editingId,
        );
    }

    /**
     * @return array{peak: int, capacity: int, days: int, first: CarbonImmutable, last: CarbonImmutable}|null
     */
    private function overrunPreview(CapacityCalculator $capacity): ?array
    {
        $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->form->startsOn) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->form->endsOn) === 1
            && WorkSiteStatus::tryFrom($this->form->status) !== null;

        return $valid && $this->form->startsOn <= $this->form->endsOn ? $this->overrun($capacity) : null;
    }

    /**
     * Only managers choose the status (D7): a work site created by anyone else starts with the default one.
     */
    private function canChangeStatus(): bool
    {
        return $this->actor()->can('changeStatus', $this->existingSite() ?? new WorkSite);
    }

    private function status(): string
    {
        return $this->editingId === null && ! $this->canChangeStatus() ? WorkSiteStatus::Forecast->value : $this->form->status;
    }

    private function authorizeSave(): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', WorkSite::class);
        } else {
            $this->authorize('update', $this->scopedWorkSites()->findOrFail($this->editingId));
        }
    }

    /**
     * @return Builder<WorkSite>
     */
    private function scopedWorkSites(): Builder
    {
        return WorkSite::query()->when($this->effectiveAgency(), fn (Builder $query, Agency $agency) => $query->where('agency_id', $agency->id));
    }

    private function effectiveAgency(): ?Agency
    {
        return app(EffectiveAgency::class)->for($this->actor());
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
