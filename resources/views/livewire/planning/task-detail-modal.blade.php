<div>
    @if ($open && $task)
        <div class="overlay" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="task-detail-title">
                <h2 id="task-detail-title">{{ $day->translatedFormat('l d F Y') }}</h2>

                @if ($absence)
                    <span class="task-badge task-badge--{{ $absence->value }}">{{ __('tasks.absence_badge.'.$absence->value) }}</span>
                @endif

                @if ($task->state_code !== '' && $task->state_code !== 'HO')
                    <span class="task-state">{{ $task->state_code }}</span>
                @endif
                @if ($task->label)
                    <div class="task-label">{{ $task->label }}</div>
                @endif
                @if ($detail !== '')
                    <div class="task-detail">{{ $detail }}</div>
                @endif

                @if ($team !== [] || $boxes !== [])
                    <section class="detail-section">
                        <h3 class="detail-section__title">{{ __('tasks.detail.team') }}</h3>
                        @if ($team !== [])
                            <p class="detail-section__line">{{ __('tasks.detail.team_pm') }} {{ implode(', ', $team) }}@if ($cabinet) · @if ($points['cabinet'])<button type="button" class="task-nav" wire:click="navigate('cabinet', 0)" title="{{ __('audit.field.navigate_to', ['name' => $cabinet]) }}">{{ $cabinet }} 🧭</button>@else<strong>{{ $cabinet }}</strong>@endif @endif</p>
                        @endif
                        @foreach ($boxes as $index => $box)
                            <p class="detail-section__line">{{ __('tasks.detail.team_box', ['n' => $box['number']]) }} {{ implode(', ', $box['people']) }} · @if (isset($points['boxes'][$box['reference']]))<button type="button" class="task-nav" wire:click="navigate('box', {{ $index }})" title="{{ __('audit.field.navigate_to', ['name' => $box['reference']]) }}">{{ $box['reference'] }} 🧭</button>@else<strong>{{ $box['reference'] }}</strong>@endif</p>
                        @endforeach
                    </section>
                @endif

                @if ($task->note)
                    <section class="detail-section">
                        <h3 class="detail-section__title">{{ __('tasks.fields.note') }}</h3>
                        <div class="task-note">{{ $task->note }}</div>
                    </section>
                @endif

                @if ($task->work_site_id)
                    <section class="detail-section">
                        <h3 class="detail-section__title">{{ __('tasks.detail.documents') }}</h3>
                        <p class="detail-section__line">
                            @forelse ($files as $file)
                                <a href="{{ route('work-site-attachments.show', $file) }}" target="_blank" rel="noopener" data-file-view data-file-name="{{ $file->original_name }}">{{ __('enums.work_site_attachment_types.'.$file->type->value) }}</a>@unless ($loop->last) · @endunless
                            @empty
                                —
                            @endforelse
                        </p>
                    </section>
                @endif

                @if ($photoTargets !== [])
                    <section class="detail-section">
                        <h3 class="detail-section__title">{{ __('tasks.photos.title') }}</h3>
                        @foreach ($photoTargets as $target)
                            <div class="task-photos" wire:key="photos-{{ $target['key'] }}">
                                <div class="task-photos__target">{{ $target['label'] }}</div>
                                @foreach ($target['phases'] as $entry)
                                    <div class="task-photos__phase">
                                        <span class="task-photos__label">{{ $entry['phase']->label() }}</span>
                                        <button type="button" class="task-photos__add" x-data="taskPhotoUpload"
                                                data-url="{{ route('task-photos.store') }}" data-date="{{ $date }}" data-target="{{ $target['key'] }}" data-phase="{{ $entry['phase']->value }}"
                                                data-texts="{{ json_encode(__('tasks.photos.upload'), JSON_UNESCAPED_UNICODE) }}"
                                                x-bind:disabled="busy" x-on:click="pick" x-text="label">📷 {{ __('tasks.photos.add') }}</button>
                                        @if ($entry['files']->isEmpty())
                                            <span class="task-photos__empty">{{ __('tasks.photos.none') }}</span>
                                        @else
                                            <div class="task-photos__grid" data-file-group>
                                                @foreach ($entry['files'] as $file)
                                                    @php($minutesLeft = (int) ceil(max(1, $deleteWindow * 60 - $file->uploaded_at->diffInSeconds(now())) / 60))
                                                    <div class="task-photos__photo" wire:key="task-photo-{{ $file->id }}">
                                                        @if ($file->uploaded_by === auth()->id() && $file->uploaded_at->gt(now()->subMinutes($deleteWindow)))
                                                            <button type="button" class="task-photos__delete" wire:click="deletePhoto({{ $file->id }})" wire:confirm="{{ __('tasks.photos.confirm_delete') }}" title="{{ __('tasks.photos.delete_title', ['minutes' => $minutesLeft]) }}" aria-label="{{ __('tasks.photos.delete_title', ['minutes' => $minutesLeft]) }}">✕</button>
                                                        @endif
                                                        <a href="{{ route('task-photos.show', [$file->id, 'full']) }}" target="_blank" rel="noreferrer" data-file-view data-file-name="{{ $file->file_name }}">
                                                            <img src="{{ route('task-photos.show', [$file->id, 'thumb']) }}" loading="lazy" alt="">
                                                        </a>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </section>
                @endif

                @if ($auditCabinet)
                    <button type="button" class="btn btn--primary task-open-audit" wire:click="openAudit">🔍 {{ __('tasks.detail.open_audit') }}</button>
                @endif

                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="close">{{ __('profile.close') }}</button>
                </div>
            </div>
        </div>

        @if ($navigation)
            <div class="overlay" wire:click.self="closeNavigation" wire:keydown.escape="closeNavigation">
                <div class="modal task-nav-sheet" role="dialog" aria-modal="true" aria-labelledby="task-nav-title">
                    <h3 id="task-nav-title" class="task-nav-sheet__title">🧭 {{ __('audit.field.navigate_to', ['name' => $navigation['name']]) }}</h3>
                    <p class="task-nav-sheet__coordinates">{{ number_format($navigation['latitude'], 6, '.', '') }}, {{ number_format($navigation['longitude'], 6, '.', '') }}</p>
                    <div class="task-nav-sheet__links" x-data="navigationChoice">
                        @foreach ($navigationLinks as $key => $url)
                            <a class="btn task-nav-sheet__link" x-bind:class="linkClass('{{ $key }}')" href="{{ $url }}" target="_blank" rel="noopener" x-on:click="remember('{{ $key }}')" wire:click="closeNavigation">{{ $key === 'waze' ? '🚗' : '🗺️' }} {{ __('tasks.detail.navigation.'.$key) }}</a>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn--ghost task-nav-sheet__close" wire:click="closeNavigation">{{ __('audit.field.close') }}</button>
                </div>
            </div>
        @endif
    @endif
</div>
