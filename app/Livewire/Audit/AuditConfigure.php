<?php

namespace App\Livewire\Audit;

use App\Actions\Files\UploadRules;
use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\Audit\BoxName;
use App\Audit\ConfigureAudit;
use App\Audit\Import\KmlReader;
use App\Audit\Import\SynopticListParser;
use App\Audit\Import\SynopticPdfReader;
use App\Audit\OpenAudit;
use App\Enums\AuditBoxKind;
use App\Enums\AuditMode;
use App\Enums\AuditSupport;
use App\Enums\AuditWorkflow;
use App\Enums\Module;
use App\Models\Audit;
use App\Models\User;
use App\Settings\ModuleSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Configure » form of a cabinet audit, for managers: mode and SRO, the list of boxes of the synoptic, the map file
 * with the positions, the drawer plan and the plans of the boxes. What is read from the files is shown before saving.
 */
class AuditConfigure extends Component
{
    use WithFileUploads;

    private const int MAX_KILOBYTES = 15360;

    #[Locked]
    public string $cabinet = '';

    public string $mode = 'full';

    public string $sro = '';

    public string $workflow = 'standard';

    public string $list = '';

    public ?TemporaryUploadedFile $synopticFile = null;

    public ?TemporaryUploadedFile $mapFile = null;

    public ?TemporaryUploadedFile $drawerPlan = null;

    /** @var list<TemporaryUploadedFile> */
    public array $boxPlans = [];

    /** @var list<string> */
    public array $toVerify = [];

    /** @var list<string> */
    public array $unmatchedPlans = [];

    public ?string $summary = null;

    #[Locked]
    public string $pdfText = '';

    public function mount(string $cabinet, string $defaultMode = 'full'): void
    {
        $this->authorize('manage', Audit::class);

        $this->cabinet = BoxName::cabinetKey($cabinet);
        $audit = Audit::query()->where('cabinet_key', $this->cabinet)->first();
        $this->mode = $audit?->effectiveMode()->value ?? (in_array($defaultMode, ['optical', 'full'], true) ? $defaultMode : 'full');
        $this->sro = (string) $audit?->sro;
        $this->workflow = $audit?->workflow->value ?? AuditWorkflow::Standard->value;
    }

    /**
     * The text of the synoptic PDF is read as soon as it is chosen; a text file is read as it is.
     */
    public function updatedSynopticFile(SynopticPdfReader $pdf): void
    {
        $this->pdfText = '';

        if ($this->synopticFile === null) {
            return;
        }

        $this->validate(['synopticFile' => UploadRules::document(UploadRules::SYNOPTIC, self::MAX_KILOBYTES)]);
        $contents = (string) file_get_contents($this->synopticFile->getRealPath());
        $this->pdfText = str_starts_with($contents, '%PDF') ? $pdf->text($contents) : mb_substr($contents, 0, 300000);
    }

    /**
     * Boxes of the pasted list win over those read from the file when both name the same box.
     *
     * @return list<array{name: string, kind: AuditBoxKind, support: AuditSupport|null, support_number: string, type: string, address: string}>
     */
    private function listed(SynopticListParser $parser): array
    {
        $boxes = [];

        foreach ([...$parser->parse($this->pdfText), ...$parser->parse($this->list)] as $box) {
            $boxes[$box['name']] = $box;
        }

        return array_values($boxes);
    }

    public function save(SynopticListParser $parser, KmlReader $reader, ConfigureAudit $configure, OpenAudit $open): void
    {
        $this->authorize('manage', Audit::class);
        $this->resetErrorBag();

        $this->validate([
            'mode' => ['required', Rule::enum(AuditMode::class)], 'sro' => ['nullable', 'string', 'max:60'], 'list' => ['nullable', 'string', 'max:300000'],
            'workflow' => ['required', Rule::enum(AuditWorkflow::class)],
            'synopticFile' => ['nullable', ...UploadRules::document(UploadRules::SYNOPTIC, self::MAX_KILOBYTES)], 'mapFile' => ['nullable', ...UploadRules::document(UploadRules::MAP, self::MAX_KILOBYTES)],
            'drawerPlan' => ['nullable', ...UploadRules::document(UploadRules::PLAN, self::MAX_KILOBYTES)], 'boxPlans' => ['array', 'max:300'], 'boxPlans.*' => UploadRules::document(UploadRules::PLAN, self::MAX_KILOBYTES),
        ]);

        $map = $this->mapFile === null ? null : $reader->read((string) file_get_contents($this->mapFile->getRealPath()));

        if ($this->mapFile !== null && $map === null) {
            $this->addError('mapFile', __('audit.configure.map_unreadable'));

            return;
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $audit = Audit::query()->where('cabinet_key', $this->cabinet)->first() ?? $open->handle($user, $this->cabinet);
            $settings = ['sro' => $this->sro, 'mode' => $this->mode];

            if ($this->workflowChoice()) {
                $settings['workflow'] = $this->workflow;
            }

            $result = $configure->handle($user, $audit, $settings, $this->listed($parser), $map, [
                'synoptic' => $this->synopticFile, 'map' => $this->mapFile, 'drawer' => $this->drawerPlan, 'boxes' => $this->boxPlans,
            ]);
        } catch (AuditOperationException $exception) {
            $this->addError('form', __('audit.errors.'.$exception->reason));

            return;
        }

        $this->toVerify = $result['to_verify'];
        $this->unmatchedPlans = $result['plans_unmatched'];
        $this->summary = __('audit.configure.saved', ['added' => $result['added'], 'updated' => $result['updated'], 'located' => $result['located'], 'plans' => $result['plans_linked']]);
        $this->pdfText = '';
        $this->reset('list', 'synopticFile', 'mapFile', 'drawerPlan', 'boxPlans');
        $this->dispatch('audit-configured');
        $this->dispatch('toast', message: $this->summary);
    }

    public function render(SynopticListParser $parser, KmlReader $reader): View
    {
        $boxes = $this->list === '' && $this->pdfText === '' ? [] : $this->listed($parser);
        $kinds = collect($boxes)->countBy(fn (array $box) => $box['kind']->value);
        $map = $this->mapFile === null ? null : $reader->read((string) file_get_contents($this->mapFile->getRealPath()));
        $names = collect($boxes)->pluck('name')->merge(Audit::query()->where('cabinet_key', $this->cabinet)->first()?->boxes()->pluck('name') ?? []);
        $located = collect(array_keys($map['boxes'] ?? []))->filter(fn (string $point) => $names->contains(fn (string $name) => KmlReader::sameBox($name, $point)))->count();

        return view('livewire.audit.audit-configure', [
            'boxCount' => count($boxes),
            'synopticChosen' => $this->synopticFile !== null,
            'boxKinds' => collect(AuditBoxKind::cases())->mapWithKeys(fn (AuditBoxKind $kind) => [$kind->value => $kinds[$kind->value] ?? 0])->all(),
            'map' => $map,
            'located' => $located,
            'modeLocked' => $this->modeLocked(),
            'workflowChoice' => $this->workflowChoice(),
            'workflowLocked' => $this->workflowLocked(),
            'audit' => Audit::query()->where('cabinet_key', $this->cabinet)->with('plans')->first(),
        ]);
    }

    /**
     * The workflow is offered while the design office workflow module is on (otherwise every audit stays standard).
     */
    private function workflowChoice(): bool
    {
        return app(ModuleSettings::class)->isEnabled(Module::DesignOfficeWorkflow);
    }

    private function workflowLocked(): bool
    {
        $audit = Audit::query()->where('cabinet_key', $this->cabinet)->first();

        return $audit !== null && app(AuditOperations::class)->isWorkflowLocked($audit);
    }

    private function modeLocked(): bool
    {
        $audit = Audit::query()->where('cabinet_key', $this->cabinet)->first();

        return $audit !== null && ($audit->photos()->exists() || $audit->boxes()->where(fn ($query) => $query->whereNotNull('status')->orWhereNotNull('confirmed_at'))->exists());
    }
}
