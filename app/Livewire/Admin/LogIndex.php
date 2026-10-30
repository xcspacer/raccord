<?php

namespace App\Livewire\Admin;

use App\Actions\Logs\RestoreChanges;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\Module;
use App\Logs\ChangeJournal;
use App\Settings\ModuleSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * « Logs »: every save of work sites, tasks and users, most recent first, with restore; and, when the design office
 * workflow is on, its hash-chained journal (read only: an entry is never changed nor restored), whose chain can be
 * checked here.
 */
class LogIndex extends Component
{
    private const int PAGE_SIZE = 150;

    public string $search = '';

    public string $type = '';

    public int $limit = self::PAGE_SIZE;

    /** @var list<int|string> Selected journal entries (ids). */
    public array $selected = [];

    /** @var array{intact: bool, checked: int, broken_at: int|null}|null Result of the last chain check of the workflow journal. */
    #[Locked]
    public ?array $chain = null;

    public function mount(): void
    {
        $this->authorize('manage-platform');
    }

    public function loadMore(): void
    {
        $this->limit = min($this->limit + self::PAGE_SIZE, 3000);
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function toggleMany(array $ids, bool $checked): void
    {
        $ids = array_map('intval', $ids);
        $current = array_map('intval', $this->selected);

        $this->selected = array_values($checked ? array_unique([...$current, ...$ids]) : array_diff($current, $ids));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * @param  list<int|string>|null  $ids
     */
    public function restore(RestoreChanges $restoreChanges, ?array $ids = null): void
    {
        $this->authorize('manage-platform');

        $ids = array_map('intval', $ids ?? $this->selected);

        if ($ids === []) {
            return;
        }

        $result = $restoreChanges->handle($ids);

        $this->selected = array_values(array_diff(array_map('intval', $this->selected), $ids));
        $message = $result['failed'] > 0
            ? __('logs.restored_partial', ['restored' => $result['restored'], 'failed' => $result['failed']])
            : trans_choice('logs.restored', $result['restored'], ['count' => $result['restored']]);

        if ($result['conflicts'] > 0) {
            $message .= ' '.trans_choice('logs.restore_conflicts', $result['conflicts'], ['count' => $result['conflicts']]);
        }

        $this->dispatch('toast', message: $message);
    }

    public function verifyChain(WorkflowJournal $workflow): void
    {
        $this->authorize('manage-platform');

        $this->chain = $workflow->verify();
    }

    public function render(ChangeJournal $journal, ModuleSettings $modules): View
    {
        $types = [...ChangeJournal::TYPES, ...($modules->isEnabled(Module::DesignOfficeWorkflow) ? [WorkflowJournal::LOG_NAME] : [])];

        if ($this->type === WorkflowJournal::LOG_NAME && in_array($this->type, $types, true)) {
            return $this->renderWorkflowJournal($types);
        }

        $activities = $journal->query($this->type)->limit($this->limit)->get();
        $summaries = $journal->summaries($activities);
        $loadedAll = $activities->count() < $this->limit;

        $events = $activities
            ->groupBy(fn (Activity $activity) => $activity->getProperty('batch', 'single-'.$activity->id).'|'.$activity->log_name)
            ->map(fn (Collection $changes) => [
                'type' => (string) $changes->first()->log_name,
                'at' => $changes->first()->created_at,
                'author' => $journal->authorName($changes->first()),
                'changes' => $changes->sortBy('id')->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'op' => $activity->event,
                    'summary' => $summaries[$activity->id] ?? '',
                    'restorable' => $journal->isRestorable($activity),
                ])->values(),
            ]);

        $search = Str::lower(trim($this->search));

        if ($search !== '') {
            $events = $events->filter(fn (array $event) => Str::contains(Str::lower(implode(' ', [
                $event['author'], __('logs.types.'.$event['type']), ...$event['changes']->pluck('summary')->all(),
            ])), $search));
        }

        $restorableIds = $events->flatMap(fn (array $event) => $event['changes']->where('restorable', true)->pluck('id'))->values()->all();
        $selected = array_map('intval', $this->selected);

        return view('livewire.admin.log-index', [
            'events' => $events->values(),
            'restorableIds' => $restorableIds,
            'selectedIds' => $selected,
            'filtered' => $search !== '' || $this->type !== '',
            'canLoadMore' => ! $loadedAll && $this->limit < 3000,
            'types' => $types,
            'workflow' => null,
        ])->layout('components.layouts.app', ['title' => __('logs.title'), 'page' => 'logs']);
    }

    /**
     * The design office workflow journal, newest first, one line per entry with what it recorded.
     *
     * @param  list<string>  $types
     */
    private function renderWorkflowJournal(array $types): View
    {
        $activities = Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->with('causer')->latest('id')->limit($this->limit)->get();
        $search = Str::lower(trim($this->search));

        $entries = $activities->map(fn (Activity $activity) => [
            'id' => $activity->id,
            'at' => $activity->created_at,
            'author' => $activity->causer?->getAttribute('name'),
            'event' => Lang::has('logs.workflow_events.'.$activity->event) ? __('logs.workflow_events.'.$activity->event) : (string) $activity->event,
            'subject' => $activity->subject_type === null ? '' : class_basename((string) $activity->subject_type).' #'.$activity->subject_id,
            'details' => $this->details($activity),
            'hash' => substr((string) $activity->getAttribute('chain_hash'), 0, 12),
        ])->filter(fn (array $entry) => $search === '' || Str::contains(Str::lower(implode(' ', [$entry['author'], $entry['event'], $entry['subject'], $entry['details']])), $search))->values();

        return view('livewire.admin.log-index', [
            'events' => collect(),
            'restorableIds' => [],
            'selectedIds' => [],
            'filtered' => $search !== '',
            'canLoadMore' => $activities->count() >= $this->limit && $this->limit < 3000,
            'types' => $types,
            'workflow' => $entries,
        ])->layout('components.layouts.app', ['title' => __('logs.title'), 'page' => 'logs']);
    }

    /**
     * « key: value » pairs of what the entry recorded (the platform's batch marker left out).
     */
    private function details(Activity $activity): string
    {
        return collect($activity->properties ?? [])
            ->except(['batch'])
            ->map(fn (mixed $value, string $key) => $key.': '.(is_array($value) ? implode(', ', array_map(fn (mixed $item) => is_scalar($item) ? (string) $item : json_encode($item), $value)) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)))
            ->implode(' · ');
    }
}
