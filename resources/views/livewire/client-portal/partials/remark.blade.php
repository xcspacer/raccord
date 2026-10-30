<div class="portal-remark" wire:key="remark-{{ $remark->id }}">
    <div class="portal-remark__head">
        <b>{{ $remark->author_name }}</b>
        <span>{{ $charts->time(\Carbon\CarbonImmutable::parse($remark->created_at), $now) }}</span>
        <span class="portal-pill portal-pill--{{ $remark->status->value }}">{{ __('client_portal.notes.status.'.$remark->status->value, ['company' => $company]) }}</span>
        @if ($remark->acknowledged_at)
            <span class="portal-pill portal-pill--ok" title="{{ __('client_portal.notes.acknowledged_on', ['time' => $charts->time(\Carbon\CarbonImmutable::parse($remark->acknowledged_at), $now)]) }}">✓ {{ __('client_portal.notes.acknowledged', ['name' => \App\ClientPortal\PortalOverview::shortName($remark->acknowledged_by_name)]) }}</span>
        @elseif (! $remark->isClosed())
            <span class="portal-pill portal-pill--open">⏳ {{ __('client_portal.notes.waiting') }}</span>
        @endif
        @if ($remark->target === \App\Enums\ClientRemarkTarget::Audit)
            <span>· {{ __('client_portal.notes.general') }}</span>
        @endif
    </div>
    <div class="portal-remark__text">{{ $remark->body }}</div>
    @foreach ($remark->replies as $reply)
        <div @class(['portal-remark__reply', 'portal-remark__reply--client' => $reply->author_side === \App\Enums\ClientRemarkSide::Client])><small>{{ $reply->author_side === \App\Enums\ClientRemarkSide::Staff ? $company : __('client_portal.notes.you') }} · {{ $reply->author_side === \App\Enums\ClientRemarkSide::Staff ? \App\ClientPortal\PortalOverview::shortName($reply->author_name) : $reply->author_name }} · {{ $charts->time(\Carbon\CarbonImmutable::parse($reply->created_at), $now) }}</small>{{ $reply->body }}</div>
    @endforeach
    <div class="portal-remark__actions">
        @if ($remark->isClosed())
            <button type="button" class="portal-btn portal-btn--ghost portal-btn--small" wire:click="reopenRemark({{ $remark->id }})">{{ __('client_portal.notes.reopen') }}</button>
        @else
            <button type="button" class="portal-btn portal-btn--ghost portal-btn--small" wire:click="startReply({{ $remark->id }})">{{ __('client_portal.notes.reply') }}</button>
            <button type="button" class="portal-btn portal-btn--ghost portal-btn--small" wire:click="closeRemark({{ $remark->id }})">{{ __('client_portal.notes.close') }}</button>
        @endif
    </div>
    @if ($replyingTo === $remark->id)
        <form wire:submit="sendReply">
            <textarea class="portal-textarea" rows="3" maxlength="2000" wire:model="replyText" placeholder="{{ __('client_portal.notes.reply_placeholder') }}"></textarea>
            @error('replyText') <p class="portal-field__error">{{ $message }}</p> @enderror
            <div class="portal-remark__actions"><button type="submit" class="portal-btn portal-btn--small">{{ __('client_portal.notes.send') }}</button></div>
        </form>
    @endif
</div>
