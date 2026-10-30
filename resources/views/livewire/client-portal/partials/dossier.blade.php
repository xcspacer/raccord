@php
    $slug = $client->portalSlug();
    $audit = $current['audit'];
    $claimPills = ['open' => 'open', 'accepted' => 'ok', 'refused' => 'anomaly'];
@endphp

<div class="portal-toolbar">
    <h2 class="portal-notes__title">{{ __('client_portal.design_office.title') }}</h2>
    @if ($audit->isClosed())
        <span class="portal-pill portal-pill--ok">{{ __('client_portal.design_office.closed', ['date' => $charts->time(\Carbon\CarbonImmutable::parse($audit->closed_at), $now)]) }}</span>
    @endif
</div>

<section class="portal-card portal-dossier__section">
    <h3>{{ __('client_portal.design_office.versions') }}</h3>
    @forelse ($dossiers as $version)
        <div class="portal-dossier__row" wire:key="dossier-{{ $version->id }}">
            <div>
                <b>{{ __('client_portal.design_office.version', ['version' => $version->version_number]) }}</b>
                <span class="portal-meta">· {{ $version->kind->label() }} · {{ $charts->time(\Carbon\CarbonImmutable::parse($version->published_at), $now) }}</span>
            </div>
            <div class="portal-dossier__actions">
                <a class="portal-btn portal-btn--small" href="{{ route('portal.dossier', [$slug, $version->id, 'full']) }}">⬇ {{ __('client_portal.design_office.download_full') }}</a>
                @if ($version->changes !== null)
                    <a class="portal-btn portal-btn--ghost portal-btn--small" href="{{ route('portal.dossier', [$slug, $version->id, 'changes']) }}">⬇ {{ __('client_portal.design_office.download_changes') }}</a>
                @endif
            </div>
        </div>
    @empty
        <div class="portal-empty">{{ __('client_portal.design_office.no_version') }}</div>
    @endforelse
</section>

<section class="portal-card portal-dossier__section">
    <h3>{{ __('client_portal.design_office.reserves') }} <span class="portal-card__aside">{{ count($reserves) }}</span></h3>
    @forelse ($reserves as $reserve)
        <div class="portal-dossier__reserve" wire:key="reserve-{{ $reserve['id'] }}">
            <div class="portal-remark__head">
                <b>{{ $reserve['box'] }}</b>
                @if ($reserve['reference'] !== '')<span>· {{ $reserve['reference'] }}</span>@endif
                <span>· {{ $reserve['kind'] }}</span>
                <span class="portal-pill portal-pill--{{ $reserve['open'] ? 'open' : 'closed' }}">{{ $reserve['open'] ? __('client_portal.design_office.reserve_open') : __('client_portal.design_office.reserve_closed') }}</span>
            </div>
            <div class="portal-remark__text">{{ $reserve['reason'] }}</div>
            @if ($reserve['text'] !== '')
                <div class="portal-note"><small>{{ __('client_portal.design_office.description') }}</small>{{ $reserve['text'] }}</div>
            @endif
            @if (! $reserve['open'] && $reserve['closing_reason'] !== '')
                <div class="portal-note"><small>{{ __('client_portal.design_office.closing_reason') }}</small>{{ $reserve['closing_reason'] }}</div>
            @endif
            @if ($reserve['photos'] !== [])
                <div class="portal-photos">
                    @foreach ($reserve['photos'] as $photo)
                        <a class="portal-photo" href="{{ route('portal.photos', [$slug, $photo['id'], 'full']) }}" target="_blank" rel="noopener">
                            <img loading="lazy" src="{{ route('portal.photos', [$slug, $photo['id'], 'thumb']) }}" alt="">
                            <span>{{ __('client_portal.design_office.photo_roles.'.$photo['role']) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
            <div class="portal-remark__actions">
                @if ($reserve['can_dispute'])
                    <button type="button" class="portal-btn portal-btn--ghost portal-btn--small" wire:click="startDispute({{ $reserve['id'] }})">{{ $reserve['disputes'] > 0 ? __('client_portal.design_office.dispute_again') : __('client_portal.design_office.dispute') }}</button>
                @endif
            </div>
            @if ($disputeFor === $reserve['id'])
                <form wire:submit="sendDispute">
                    <textarea class="portal-textarea" rows="3" maxlength="2000" wire:model="disputeText" placeholder="{{ __('client_portal.design_office.dispute_placeholder') }}"></textarea>
                    @error('disputeText') <p class="portal-field__error">{{ $message }}</p> @enderror
                    <div class="portal-remark__actions"><button type="submit" class="portal-btn portal-btn--small">{{ __('client_portal.design_office.send_dispute') }}</button></div>
                </form>
            @endif
        </div>
    @empty
        <div class="portal-empty">{{ __('client_portal.design_office.no_reserves') }}</div>
    @endforelse
</section>

@if ($audit->isClosed())
    <form class="portal-card portal-dossier__section" wire:submit="declareOccurrence">
        <h3>{{ __('client_portal.design_office.new_occurrence') }}</h3>
        <p class="portal-meta">{{ __('client_portal.design_office.new_occurrence_hint', ['company' => $company]) }}</p>
        <select class="portal-field__input" wire:model="occurrenceBox" aria-label="{{ __('client_portal.design_office.box_attribute') }}">
            <option value="">{{ __('client_portal.design_office.pick_box') }}</option>
            @foreach ($claimBoxes as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        @error('occurrenceBox') <p class="portal-field__error">{{ $message }}</p> @enderror
        <textarea class="portal-textarea" rows="3" maxlength="2000" wire:model="occurrenceText" placeholder="{{ __('client_portal.design_office.occurrence_placeholder') }}"></textarea>
        @error('occurrenceText') <p class="portal-field__error">{{ $message }}</p> @enderror
        <div class="portal-notes__send"><button type="submit" class="portal-btn">{{ __('client_portal.notes.send_to', ['company' => $company]) }}</button></div>
    </form>
@endif

<section class="portal-card portal-dossier__section">
    <h3>{{ __('client_portal.design_office.claims') }} <span class="portal-card__aside">{{ count($claims) }}</span></h3>
    @forelse ($claims as $claim)
        <div class="portal-remark" wire:key="claim-{{ $claim['id'] }}">
            <div class="portal-remark__head">
                <b>{{ __('client_portal.design_office.claim_kinds.'.$claim['kind']) }}</b>
                @if ($claim['box'] !== '')<span>· {{ $claim['box'] }}</span>@endif
                @if ($claim['round'] > 1)<span>· {{ __('client_portal.design_office.round', ['round' => $claim['round']]) }}</span>@endif
                <span>{{ $charts->time($claim['at'], $now) }}</span>
                <span class="portal-pill portal-pill--{{ $claimPills[$claim['state']] ?? 'open' }}">{{ __('client_portal.design_office.claim_states.'.$claim['state']) }}</span>
            </div>
            <div class="portal-remark__text">{{ $claim['body'] }}</div>
            @if ($claim['decided_at'])
                <div class="portal-remark__reply"><small>{{ $company }} · {{ $charts->time($claim['decided_at'], $now) }}@if ($claim['reference'] !== '') · {{ $claim['reference'] }}@endif</small>{{ $claim['answer'] !== '' ? $claim['answer'] : __('client_portal.design_office.claim_states.'.$claim['state']) }}</div>
            @endif
        </div>
    @empty
        <div class="portal-empty">{{ __('client_portal.design_office.no_claims') }}</div>
    @endforelse
</section>
