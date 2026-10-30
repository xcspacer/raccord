<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\Actions\Files\UploadRules;
use App\DesignOfficeWorkflow\DocumentVersions;
use App\DesignOfficeWorkflow\SynopticCorrections;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditSynopticCorrectionState;
use App\Models\Audit;
use App\Models\AuditDocumentVersion;
use App\Models\AuditSynopticCorrection;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Documents » of a PM in the design office workflow: the versions of the synoptic and of the map file (the current
 * one and the archived ones, each downloadable), publishing a new version, and the corrections of the synoptic proposed
 * by the technicians — the open ones are ticked to be included in the consolidated synoptic being published, or
 * rejected with a reason. For the design office and the supervisors.
 */
class DocumentsPanel extends Component
{
    use WithFileUploads;

    /** Largest document, in kilobytes (the server accepts uploads up to 20 MB). */
    public const int MAX_KILOBYTES = 20480;

    #[Locked]
    public int $auditId;

    public string $kind = 'synoptic';

    public ?TemporaryUploadedFile $document = null;

    /** @var list<int|string> Open corrections included in the synoptic being published. */
    public array $included = [];

    /** Correction being rejected (its reason is asked for). */
    public int $rejectingId = 0;

    public string $rejectionReason = '';

    /**
     * What the last map file published matched (names of the map that match no box are listed to be checked).
     *
     * @var array{matched: int, unmatched: list<string>}|null
     */
    #[Locked]
    public ?array $mapResult = null;

    public function mount(int $auditId): void
    {
        $this->auditId = $auditId;
        $this->authorize('viewAny', [AuditDocumentVersion::class, $this->audit()]);
    }

    public function publish(DocumentVersions $versions): void
    {
        $audit = $this->audit();
        $this->authorize('publish', [AuditDocumentVersion::class, $audit]);
        $this->resetErrorBag();

        $kind = AuditDocumentKind::tryFrom($this->kind) ?? AuditDocumentKind::Synoptic;
        $this->validate([
            'kind' => ['required', Rule::enum(AuditDocumentKind::class)],
            'document' => ['required', ...UploadRules::document($kind === AuditDocumentKind::Map ? UploadRules::MAP : UploadRules::VERSIONED_SYNOPTIC, self::MAX_KILOBYTES)],
            'included' => ['array'],
            'included.*' => ['integer'],
        ]);

        try {
            $result = $versions->publish($this->user(), $audit, $kind, $this->document ?? abort(422), $kind === AuditDocumentKind::Synoptic ? array_map('intval', $this->included) : []);
        } catch (WorkflowException $exception) {
            $this->addError('document', $exception->translated());

            return;
        }

        $summary = $result['version']->summary ?? [];
        $this->mapResult = $kind === AuditDocumentKind::Map ? ['matched' => (int) ($summary['matched'] ?? 0), 'unmatched' => array_values(array_map('strval', (array) ($summary['unmatched'] ?? [])))] : null;
        $this->reset('document', 'included');
        $this->dispatch('toast', message: $result['duplicate'] ? __('design_office_workflow.documents.unchanged') : __('design_office_workflow.documents.published', ['version' => $result['version']->version_number]));
    }

    public function startRejecting(int $correctionId): void
    {
        $this->rejectingId = $correctionId;
        $this->rejectionReason = '';
        $this->resetErrorBag();
    }

    public function cancelRejecting(): void
    {
        $this->reset('rejectingId', 'rejectionReason');
    }

    public function rejectCorrection(SynopticCorrections $corrections): void
    {
        $correction = AuditSynopticCorrection::query()->where('audit_id', $this->auditId)->findOrFail($this->rejectingId);
        $this->authorize('decide', $correction);
        $this->validate(['rejectionReason' => ['required', 'string', 'min:'.SynopticCorrections::MIN_REJECTION_REASON, 'max:1000']]);

        try {
            $corrections->reject($this->user(), $correction, $this->rejectionReason);
        } catch (WorkflowException $exception) {
            $this->addError('corrections', $exception->translated());

            return;
        }

        $this->reset('rejectingId', 'rejectionReason');
        $this->dispatch('toast', message: __('design_office_workflow.documents.correction_rejected'));
    }

    public function render(): View
    {
        $audit = $this->audit();
        $versions = AuditDocumentVersion::query()->where('audit_id', $audit->id)->with(['file', 'publisher:id,name'])->orderByDesc('version_number')->orderByDesc('id')->get();
        $corrections = AuditSynopticCorrection::query()->where('audit_id', $audit->id)->with(['box:id,name', 'proposer:id,name', 'documentVersion:id,version_number'])->latest('id')->limit(100)->get();

        return view('livewire.design-office-workflow.documents-panel', [
            'audit' => $audit,
            'kinds' => AuditDocumentKind::cases(),
            'accept' => implode(',', array_map(fn (string $extension) => '.'.$extension, $this->kind === AuditDocumentKind::Map->value ? UploadRules::MAP : UploadRules::VERSIONED_SYNOPTIC)),
            'versions' => $versions->groupBy(fn (AuditDocumentVersion $version) => $version->kind->value),
            'openCorrections' => $corrections->filter(fn (AuditSynopticCorrection $correction) => $correction->state === AuditSynopticCorrectionState::Open)->values(),
            'decidedCorrections' => $corrections->reject(fn (AuditSynopticCorrection $correction) => $correction->state === AuditSynopticCorrectionState::Open)->take(20)->values(),
            'canEdit' => ! $audit->isClosed(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'kind' => __('design_office_workflow.documents.kind'),
            'document' => __('design_office_workflow.documents.file'),
            'included' => __('design_office_workflow.documents.corrections'),
            'included.*' => __('design_office_workflow.documents.corrections'),
            'rejectionReason' => __('design_office_workflow.documents.rejection_reason'),
        ];
    }

    private function audit(): Audit
    {
        return Audit::query()->findOrFail($this->auditId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
