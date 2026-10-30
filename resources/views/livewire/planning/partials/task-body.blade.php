@if ($cell['absence'])
    <span class="task-badge task-badge--{{ $cell['absence']->value }}">{{ __('tasks.absence_badge.'.$cell['absence']->value) }}</span>
@endif
@if ($cell['task'])
    @php($task = $cell['task'])
    @if ($cell['absence'])
        <span class="task-reassign" title="{{ __('tasks.to_reassign_hint') }}">⚠ {{ __('tasks.to_reassign') }}</span>
    @endif
    @if ($withSiteLink && $task->work_site_id)
        <button type="button" class="icon-mini task-site-link" wire:click.stop="openWorkSite({{ $task->work_site_id }})" title="{{ __('tasks.open_work_site') }}" aria-label="{{ __('tasks.open_work_site') }}">✎</button>
    @endif
    @if ($task->state_code !== '' && $task->state_code !== 'HO')
        <span class="task-state">{{ $task->state_code }}</span>
    @endif
    @if ($task->label)
        <div class="task-label">{{ $task->label }}</div>
    @endif
    @if ($cell['detail'] !== '')
        <div class="task-detail">{{ $cell['detail'] }}</div>
    @endif
    @if ($task->note)
        <div class="task-note">{{ $task->note }}</div>
    @endif
@endif

@if ($cell['punch'])
    @include('livewire.planning.partials.punch-line', ['punch' => $cell['punch'], 'day' => $day, 'flagsAccuracy' => ! $technicianView])
@endif
