@if ($clientNotes->isNotEmpty())
    @php
        $general = $clientNotes->filter(fn ($note) => $note->target === \App\Enums\ClientRemarkTarget::Audit);
        $folders = $clientNotes->reject(fn ($note) => $note->target === \App\Enums\ClientRemarkTarget::Audit);
        $groups = ($sections ?? true) ? array_filter(['general' => $general, 'boxes' => $folders], fn ($group) => $group->isNotEmpty()) : ['all' => $clientNotes];
    @endphp
    <div class="audit-client-notes" role="status">
        <b>📝 {{ __('client_portal.staff.title', ['count' => $clientNotes->count()]) }}</b>
        @foreach ($groups as $groupKey => $group)
            @if ($groupKey !== 'all')
                <div class="audit-client-notes__section">{{ $groupKey === 'general' ? '🗂 '.__('client_portal.staff.section_general') : '📦 '.__('client_portal.staff.section_boxes') }} <span>{{ $group->count() }}</span></div>
            @endif
            @foreach ($group as $note)
                <div class="audit-client-notes__item" wire:key="client-note-{{ $note->id }}">
                    <div class="audit-client-notes__head">
                        @if ($note->audit_box_id)
                            <a href="{{ route('audits.box', ['cabinet' => $note->audit->cabinet_key, 'name' => $note->target_label]) }}">{{ $note->target_label }}</a> ·
                        @elseif ($note->audit_control_point_id)
                            <a href="{{ route('audits.point', ['cabinet' => $note->audit->cabinet_key, 'id' => $note->audit_control_point_id]) }}">{{ $note->target_label }}</a> ·
                        @endif
                        {{ $note->audit->cabinet_key }} · {{ $note->author_name }} ({{ __('client_portal.staff.client') }}) · {{ $note->created_at->timezone(config('platform.timezone'))->format('d/m H:i') }}
                    </div>
                    <div class="audit-client-notes__text">{{ $note->body }}</div>
                    <button type="button" class="btn btn--primary audit-client-notes__ack" wire:click="acknowledgeClientNote({{ $note->id }})">✓ {{ __('client_portal.staff.acknowledge') }}</button>
                </div>
            @endforeach
        @endforeach
    </div>
@endif
