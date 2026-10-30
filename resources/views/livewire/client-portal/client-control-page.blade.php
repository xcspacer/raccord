<div class="client-control">
    <h2 class="client-control__title">🤝 {{ __('client_portal.control.title') }}</h2>
    <p class="client-control__intro">{{ __('client_portal.control.intro') }}</p>

    <h3 class="client-control__heading">{{ __('client_portal.control.companies') }}</h3>
    @forelse ($clients as $client)
        <div class="client-control__company" wire:key="client-{{ $client->id }}">
            <div class="client-control__row">
                <div>
                    <b>{{ $client->name }}</b>
                    @if ($client->portal_subtitle) <span class="client-control__meta">— {{ $client->portal_subtitle }}</span> @endif
                </div>
                <button type="button" class="btn btn--ghost btn--small" wire:click="startBranding({{ $client->id }})">{{ __('client_portal.control.edit_branding') }}</button>
            </div>
            <div class="client-control__link">
                <input type="text" readonly id="portal-link-{{ $client->id }}" value="{{ route('portal.login', $client->portalSlug()) }}" aria-label="{{ __('client_portal.control.link') }}">
                <button type="button" class="btn btn--ghost btn--small" data-copy-target="portal-link-{{ $client->id }}" data-copied="{{ __('client_portal.control.link_copied') }}" data-copy-failed="{{ __('client_portal.control.link_copy_failed') }}">{{ __('client_portal.control.copy_link') }}</button>
                <a class="btn btn--ghost btn--small" href="{{ route('portal.login', $client->portalSlug()) }}" target="_blank" rel="noopener">{{ __('client_portal.control.open_link') }} ↗</a>
            </div>

            @if ($brandingFor === $client->id)
                <form class="client-control__form" wire:submit="saveBranding" novalidate>
                    <div class="field">
                        <label class="field__label" for="portal-subtitle">{{ __('client_portal.control.subtitle') }}</label>
                        <input class="field__input" id="portal-subtitle" wire:model="subtitle" maxlength="160">
                        <p class="field__hint">{{ __('client_portal.control.subtitle_hint') }}</p>
                        @error('subtitle') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field__label" for="portal-document-locale">{{ __('client_portal.control.document_locale') }}</label>
                        <select class="field__input" id="portal-document-locale" wire:model="documentLocale">
                            @foreach (config('app.supported_locales') as $locale)
                                <option value="{{ $locale }}">{{ __('platform.languages.'.$locale) }}</option>
                            @endforeach
                        </select>
                        <p class="field__hint">{{ __('client_portal.control.document_locale_hint') }}</p>
                        @error('documentLocale') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <span class="field__label">{{ __('client_portal.control.logo') }}</span>
                        @if ($client->logoUrl())
                            <img class="client-control__logo" src="{{ $client->logoUrl() }}" alt="{{ $client->name }}">
                        @endif
                        <label class="file-picker">
                            <span class="btn btn--ghost">{{ __('client_portal.control.choose_logo') }}</span>
                            <input class="file-picker__input" type="file" accept="image/png,image/jpeg,image/webp" wire:model="logo">
                        </label>
                        @error('logo') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="form-actions">
                        @if ($client->logo_path)
                            <button type="button" class="btn btn--ghost" wire:click="removeLogo">{{ __('client_portal.control.remove_logo') }}</button>
                        @endif
                        <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('client_portal.control.cancel') }}</button>
                        <button type="submit" class="btn btn--primary">{{ __('client_portal.control.save') }}</button>
                    </div>
                </form>
            @endif
        </div>
    @empty
        <div class="empty-state">{{ __('client_portal.control.no_companies') }}</div>
    @endforelse

    <h3 class="client-control__heading">{{ __('client_portal.control.accounts') }}</h3>

    @if ($revealed)
        <div class="overlay" wire:keydown.escape="hidePassword">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="client-password-title">
                <h2 id="client-password-title">{{ __('client_portal.control.password_title') }}</h2>
                <p>{{ __('client_portal.control.new_password_for', ['name' => $revealed['name']]) }}</p>
                <dl class="client-control__credentials">
                    <dt>{{ __('client_portal.control.username') }}</dt>
                    <dd><code class="client-control__secret">{{ $revealed['username'] }}</code></dd>
                    <dt>{{ __('client_portal.control.password_label') }}</dt>
                    <dd><code class="client-control__secret">{{ $revealed['password'] }}</code></dd>
                </dl>
                <p class="client-control__meta">{{ __('client_portal.control.new_password_hint') }}</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn--primary" wire:click="hidePassword" autofocus>{{ __('client_portal.control.done') }}</button>
                </div>
            </div>
        </div>
    @endif

    <x-password-confirmation :open="$pendingConfirmation !== null" id="confirm-password-client-control" />

    @forelse ($accounts as $account)
        <div @class(['client-control__account', 'is-off' => ! $account->is_active]) wire:key="account-{{ $account->id }}">
            <div class="client-control__row">
                <div>
                    <b>{{ $account->name }}</b> <span class="client-control__company-name">{{ $account->client->name }}</span>
                    <div class="client-control__meta">
                        {{ '@'.$account->username }}
                        · {{ __('client_portal.control.audits_label') }} {{ $account->has_all_audits ? __('client_portal.control.audits_all') : $account->audits->pluck('cabinet_key')->sort(SORT_NATURAL)->implode(', ') }}
                        · {{ __('client_portal.control.last_login') }} {{ $account->last_login_at?->timezone(config('platform.timezone'))->format('d/m/Y H:i') ?? __('client_portal.control.never') }}
                    </div>
                </div>
                <div class="client-control__actions">
                    <button type="button" class="btn btn--ghost btn--small" wire:click="toggle({{ $account->id }})">{{ $account->is_active ? __('client_portal.control.deactivate') : __('client_portal.control.reactivate') }}</button>
                    <button type="button" class="btn btn--ghost btn--small" wire:click="startPassword({{ $account->id }})">{{ __('client_portal.control.new_password') }}</button>
                    <button type="button" class="btn btn--ghost btn--small" wire:click="startAudits({{ $account->id }})">{{ __('client_portal.control.audits') }}</button>
                    <button type="button" class="btn btn--danger btn--small" wire:click="delete({{ $account->id }})" wire:confirm="{{ __('client_portal.control.confirm_delete', ['name' => $account->name]) }}">{{ __('client_portal.control.delete') }}</button>
                </div>
            </div>

            @if ($passwordFor === $account->id)
                <form class="client-control__form" wire:submit="savePassword" novalidate>
                    <div class="field">
                        <label class="field__label" for="portal-new-password">{{ __('client_portal.control.new_password_label', ['min' => \App\Livewire\ClientPortal\ClientControlPage::PASSWORD_MIN]) }}</label>
                        <input class="field__input" id="portal-new-password" wire:model="newPassword" autocomplete="off">
                        @error('newPassword') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('client_portal.control.cancel') }}</button>
                        <button type="submit" class="btn btn--primary">{{ __('client_portal.control.save') }}</button>
                    </div>
                </form>
            @endif

            @if ($auditsFor === $account->id)
                <form class="client-control__form" wire:submit="saveAudits" novalidate>
                    <div class="client-control__audits">
                        <label><input type="checkbox" wire:model.live="editAllAudits"> {{ __('client_portal.control.all_audits') }}</label>
                        @foreach ($audits as $audit)
                            <label wire:key="edit-audit-{{ $audit->id }}"><input type="checkbox" value="{{ $audit->id }}" wire:model="editAuditIds" @disabled($editAllAudits)> {{ $audit->cabinet_key }}</label>
                        @endforeach
                    </div>
                    @error('editAuditIds') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    <div class="form-actions">
                        <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('client_portal.control.cancel') }}</button>
                        @if ($editAllAudits)
                            <button type="button" class="btn btn--primary" wire:key="save-audits-all" wire:click="saveAudits" wire:confirm="{{ __('client_portal.control.confirm_all_audits') }}">{{ __('client_portal.control.save') }}</button>
                        @else
                            <button type="submit" class="btn btn--primary" wire:key="save-audits-chosen">{{ __('client_portal.control.save') }}</button>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    @empty
        <div class="empty-state">{{ __('client_portal.control.no_accounts') }}</div>
    @endforelse

    <details class="client-control__new">
        <summary>＋ {{ __('client_portal.control.new_account') }}</summary>
        <form class="client-control__form" wire:submit="create" novalidate>
            <div class="field">
                <label class="field__label" for="portal-name">{{ __('client_portal.control.name') }}</label>
                <input class="field__input" id="portal-name" wire:model="name" maxlength="80" placeholder="{{ __('client_portal.control.name_placeholder') }}">
                @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label class="field__label" for="portal-company">{{ __('client_portal.control.company') }}</label>
                <input class="field__input" id="portal-company" list="portal-companies" wire:model="company" maxlength="120" autocomplete="off">
                <datalist id="portal-companies">
                    @foreach ($clientNames as $clientName)
                        <option value="{{ $clientName }}"></option>
                    @endforeach
                </datalist>
                @error('company') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label class="field__label" for="portal-username-new">{{ __('client_portal.control.username') }}</label>
                <input class="field__input" id="portal-username-new" wire:model="username" maxlength="60" autocomplete="off" placeholder="{{ __('client_portal.control.username_placeholder') }}">
                @error('username') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <span class="field__label">{{ __('client_portal.control.audits_field') }}</span>
                <div class="client-control__audits">
                    <label><input type="checkbox" wire:model.live="allAudits"> {{ __('client_portal.control.all_audits') }}</label>
                    @foreach ($audits as $audit)
                        <label wire:key="new-audit-{{ $audit->id }}"><input type="checkbox" value="{{ $audit->id }}" wire:model="auditIds" @disabled($allAudits)> {{ $audit->cabinet_key }}</label>
                    @endforeach
                </div>
                @error('auditIds') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <p class="client-control__meta">{{ __('client_portal.control.create_hint') }}</p>
            <div class="form-actions">
                @if ($allAudits)
                    <button type="button" class="btn btn--primary" wire:key="create-all" wire:click="create" wire:confirm="{{ __('client_portal.control.confirm_all_audits') }}">{{ __('client_portal.control.create') }}</button>
                @else
                    <button type="submit" class="btn btn--primary" wire:key="create-chosen">{{ __('client_portal.control.create') }}</button>
                @endif
            </div>
        </form>
    </details>

    <h3 class="client-control__heading">
        {{ __('client_portal.control.notes') }}
        <span class="client-control__count">{{ __('client_portal.control.notes_open', ['count' => $openNotes]) }}</span>
        <span class="client-control__filter">
            <button type="button" @class(['is-on' => $notesFilter === 'open']) wire:click="filterNotes('open')">{{ __('client_portal.control.filter_open') }}</button>
            <button type="button" @class(['is-on' => $notesFilter === 'all']) wire:click="filterNotes('all')">{{ __('client_portal.control.filter_all') }}</button>
        </span>
    </h3>

    @forelse ($remarks as $remark)
        <div class="client-control__note" wire:key="note-{{ $remark->id }}">
            <div class="client-control__note-head">
                <span class="client-control__pill client-control__pill--{{ $remark->status->value }}">{{ __('client_portal.control.note_status.'.$remark->status->value) }}</span>
                @if ($remark->acknowledged_at)
                    <span class="client-control__pill client-control__pill--ok" title="{{ $remark->acknowledged_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}">✓ {{ __('client_portal.notes.acknowledged', ['name' => $remark->acknowledged_by_name]) }}</span>
                @elseif (! $remark->isClosed())
                    <span class="client-control__pill client-control__pill--open">⏳ {{ __('client_portal.control.to_acknowledge') }}</span>
                @endif
                <b>{{ $remark->audit->cabinet_key }}</b>
                @if ($remark->target === \App\Enums\ClientRemarkTarget::Audit)
                    <span>· {{ __('client_portal.control.general_note') }}</span>
                @elseif ($remark->audit_box_id)
                    <a href="{{ route('audits.box', ['cabinet' => $remark->audit->cabinet_key, 'name' => $remark->target_label]) }}">· {{ __('client_portal.control.on_box', ['name' => $remark->target_label]) }} ↗</a>
                @elseif ($remark->audit_control_point_id)
                    <a href="{{ route('audits.point', ['cabinet' => $remark->audit->cabinet_key, 'id' => $remark->audit_control_point_id]) }}">· {{ __('client_portal.control.on_point', ['name' => $remark->target_label]) }} ↗</a>
                @else
                    <span>· {{ $remark->target_label }}</span>
                @endif
                <span class="client-control__meta">{{ $remark->author_name }} · {{ $remark->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}</span>
            </div>
            <div class="client-control__note-text">{{ $remark->body }}</div>
            @foreach ($remark->replies as $reply)
                <div @class(['client-control__reply', 'client-control__reply--client' => $reply->author_side === \App\Enums\ClientRemarkSide::Client])><small>{{ $reply->author_side === \App\Enums\ClientRemarkSide::Staff ? $company : $reply->author_side->label() }} · {{ $reply->author_name }} · {{ $reply->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}</small>{{ $reply->body }}</div>
            @endforeach
            <div class="client-control__note-actions">
                @if ($remark->isClosed())
                    <button type="button" class="btn btn--ghost btn--small" wire:click="reopenNote({{ $remark->id }})">{{ __('client_portal.notes.reopen') }}</button>
                @else
                    <button type="button" class="btn btn--primary btn--small" wire:click="startReply({{ $remark->id }})">{{ __('client_portal.notes.reply') }}</button>
                    <button type="button" class="btn btn--ghost btn--small" wire:click="closeNote({{ $remark->id }})">{{ __('client_portal.notes.close') }}</button>
                @endif
            </div>
            @if ($replyTo === $remark->id)
                <form class="client-control__note-reply" wire:submit="sendReply">
                    <textarea class="field__input" rows="3" maxlength="2000" wire:model="replyText" placeholder="{{ __('client_portal.control.reply_placeholder') }}"></textarea>
                    @error('replyText') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                    <button type="submit" class="btn btn--primary btn--small">{{ __('client_portal.control.send_reply') }}</button>
                </form>
            @endif
        </div>
    @empty
        <div class="empty-state">{{ __('client_portal.control.no_notes') }}</div>
    @endforelse
</div>
