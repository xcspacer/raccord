<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\ImpactedBoxes;
use App\DesignOfficeWorkflow\OccurrenceView;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Lang;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Detail of an occurrence for the design office and the supervisors: what was found and done, its state, the photos
 * by role, the boxes it concerns (origin, proposed, confirmed, excluded) with their result, and its journal. The design
 * office confirms or excludes the impacted boxes proposed by the field (a reason for an exclusion), adds an impacted
 * box itself and gives the result on each confirmed box.
 */
class OccurrencePage extends Component
{
    #[Locked]
    public int $occurrenceId;

    #[Locked]
    public ?int $excludingBoxId = null;

    public string $exclusionReason = '';

    public string $newBoxId = '';

    public string $newBoxJustification = '';

    /** @var array<int, string> Result typed per box id: « conforming » or « non_conforming ». */
    public array $results = [];

    /** @var array<int, string> Note typed per box id. */
    public array $notes = [];

    public function mount(AuditOccurrence $occurrence): void
    {
        $this->authorize('view', $occurrence);
        $this->occurrenceId = $occurrence->id;
        $this->fillResults($occurrence);
    }

    public function confirmBox(ImpactedBoxes $boxes, int $boxId): void
    {
        $occurrence = $this->occurrence();
        $this->authorize('decideImpactedBox', $occurrence);

        $this->attempt(fn () => $boxes->decide($this->user(), $occurrence, $this->box($occurrence, $boxId), true), __('design_office_workflow.occurrence.box_confirmed'));
    }

    public function startExclusion(int $boxId): void
    {
        $this->authorize('decideImpactedBox', $this->occurrence());
        $this->excludingBoxId = $boxId;
        $this->exclusionReason = '';
        $this->resetErrorBag();
    }

    public function cancelExclusion(): void
    {
        $this->excludingBoxId = null;
        $this->exclusionReason = '';
    }

    public function excludeBox(ImpactedBoxes $boxes): void
    {
        $occurrence = $this->occurrence();
        $this->authorize('decideImpactedBox', $occurrence);
        $this->validate(['exclusionReason' => ['required', 'string', 'max:2000']]);

        if ($this->excludingBoxId === null) {
            return;
        }

        $box = $this->box($occurrence, $this->excludingBoxId);

        if ($this->attempt(fn () => $boxes->decide($this->user(), $occurrence, $box, false, $this->exclusionReason), __('design_office_workflow.occurrence.box_excluded'))) {
            $this->cancelExclusion();
        }
    }

    public function addBox(ImpactedBoxes $boxes): void
    {
        $occurrence = $this->occurrence();
        $this->authorize('decideImpactedBox', $occurrence);
        $this->authorize('proposeImpactedBox', $occurrence);
        $this->validate([
            'newBoxId' => ['required', 'integer', 'min:1'],
            'newBoxJustification' => ['required', 'string', 'max:2000'],
        ]);

        $box = $this->box($occurrence, (int) $this->newBoxId);

        if ($this->attempt(fn () => $boxes->propose($this->user(), $occurrence, $box, $this->newBoxJustification), __('design_office_workflow.occurrence.box_added'))) {
            $this->reset('newBoxId', 'newBoxJustification');
            $this->fillResults($occurrence->refresh());
        }
    }

    public function saveResult(ImpactedBoxes $boxes, int $boxId): void
    {
        $occurrence = $this->occurrence();
        $this->authorize('setImpactedResult', $occurrence);
        $this->validate([
            'results.'.$boxId => ['required', 'in:'.AuditConformity::Conforming->value.','.AuditConformity::NonConforming->value],
            'notes.'.$boxId => ['nullable', 'string', 'max:1000'],
        ]);

        $this->attempt(fn () => $boxes->setResult($this->user(), $occurrence, $this->box($occurrence, $boxId), AuditConformity::from($this->results[$boxId]), $this->notes[$boxId] ?? null));
    }

    public function render(): View
    {
        $occurrence = $this->occurrence()->load(['audit', 'box', 'type', 'photoLinks.photo', 'boxLinks.box', 'boxLinks.confirmer', 'boxLinks.excluder', 'creator', 'corrector', 'validator', 'pendingAuthorizer']);
        $linked = $occurrence->boxLinks->pluck('audit_box_id')->all();
        $excluded = $occurrence->boxLinks->whereNotNull('excluded_at')->pluck('audit_box_id')->all();

        return view('livewire.design-office-workflow.occurrence-page', [
            'occurrence' => $occurrence,
            'photos' => collect(AuditOccurrencePhotoRole::cases())
                ->mapWithKeys(fn (AuditOccurrencePhotoRole $role) => [$role->value => $occurrence->photoLinks->where('role', $role)->filter(fn ($link) => $link->photo !== null)->values()])
                ->filter(fn ($links) => $links->isNotEmpty()),
            'links' => $occurrence->boxLinks->sortBy([['is_origin', 'desc'], ['id', 'asc']])->values(),
            'linkState' => fn (AuditOccurrenceBox $link) => OccurrenceView::linkState($link),
            'canAddBox' => $occurrence->nature === AuditAnomalyNature::Optical && ! $occurrence->state->isClosed() && ! $occurrence->audit->isClosed(),
            'candidates' => AuditBox::query()->where('audit_id', $occurrence->audit_id)->whereNull('removed_at')
                ->where(fn ($query) => $query->whereNotIn('id', $linked)->orWhereIn('id', $excluded))
                ->orderBy('name')->get(['id', 'name']),
            'journal' => $this->journal($occurrence),
            'conformities' => [AuditConformity::Conforming, AuditConformity::NonConforming],
        ])->layout('components.layouts.app', ['title' => __('design_office_workflow.occurrence.title', ['reference' => $occurrence->reference]), 'page' => 'design-office']);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'exclusionReason' => __('design_office_workflow.occurrence.exclusion_reason'),
            'newBoxId' => __('design_office_workflow.occurrence.box'),
            'newBoxJustification' => __('design_office_workflow.occurrence.justification'),
            'results.*' => __('design_office_workflow.occurrence.result'),
            'notes.*' => __('design_office_workflow.occurrence.note'),
        ];
    }

    /**
     * Journal of the occurrence, oldest first.
     *
     * @return list<array{at: string, by: string, event: string, reason: string|null}>
     */
    private function journal(AuditOccurrence $occurrence): array
    {
        return Activity::query()
            ->where('log_name', WorkflowJournal::LOG_NAME)
            ->where('subject_type', $occurrence->getMorphClass())
            ->where('subject_id', $occurrence->id)
            ->with('causer')
            ->orderBy('id')
            ->get()
            ->map(function (Activity $entry): array {
                $key = 'design_office_workflow.occurrence.events.'.$entry->event;
                $properties = $entry->properties;
                $reason = $properties->get('reason') ?? $properties->get('action') ?? $properties->get('note');

                return [
                    'at' => $entry->created_at?->timezone((string) config('platform.timezone'))->format('d/m/Y H:i:s') ?? '',
                    'by' => $entry->causer instanceof User ? $entry->causer->name : __('design_office_workflow.occurrence.system'),
                    'event' => Lang::has($key) ? __($key, ['box' => (string) $properties->get('box', ''), 'old' => $this->stateLabel($properties->get('old')), 'new' => $this->stateLabel($properties->get('new'))]) : (string) $entry->event,
                    'reason' => is_string($reason) ? $reason : null,
                ];
            })->values()->all();
    }

    private function stateLabel(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return AuditOccurrenceState::tryFrom($value)?->label() ?? AuditConformity::tryFrom($value)?->label() ?? $value;
    }

    private function fillResults(AuditOccurrence $occurrence): void
    {
        foreach ($occurrence->boxLinks()->get() as $link) {
            $this->results[$link->audit_box_id] = $link->conformity === AuditConformity::Conforming ? AuditConformity::Conforming->value : AuditConformity::NonConforming->value;
            $this->notes[$link->audit_box_id] = (string) $link->note;
        }
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action, ?string $done = null): bool
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->addError('action', $exception->translated());

            return false;
        }

        $this->dispatch('toast', message: $done ?? __('design_office_workflow.queue.saved'));

        return true;
    }

    private function box(AuditOccurrence $occurrence, int $boxId): AuditBox
    {
        return AuditBox::query()->where('audit_id', $occurrence->audit_id)->findOrFail($boxId);
    }

    private function occurrence(): AuditOccurrence
    {
        return AuditOccurrence::query()->findOrFail($this->occurrenceId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
