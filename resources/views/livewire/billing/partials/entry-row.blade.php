@php($badges = app(\App\Billing\EntryBadges::class)->for($entry, $amount))
<div @class(['billing__entry', 'has-warning' => $warnings !== []])>
    <div class="billing__entry-head">
        <div class="billing__entry-tags">
            <span class="billing__badge billing__badge--{{ $entry->type->value }}">{{ $entry->type->label() }}</span>
            <span class="billing__badge">{{ $entry->billingClient->name }}</span>
            @if (($ticketEditable ?? false) && $ticketEditId === $entry->id)
                <span class="billing__ticket-edit">
                    <input class="billing__input billing__ticket-input" type="text" wire:model="ticketValue" maxlength="60" aria-label="{{ __('billing.dashboard.ticket_edit') }}" autofocus>
                    <button type="button" class="billing__icon-button" wire:click="saveTicket" aria-label="{{ __('billing.dashboard.ticket_save') }}">✓</button>
                    <button type="button" class="billing__icon-button" wire:click="cancelTicketEdit" aria-label="{{ __('billing.dashboard.ticket_cancel') }}">✕</button>
                </span>
            @else
                <span class="billing__ticket">{{ $entry->ticket }}</span>
                @if ($ticketEditable ?? false)
                    <button type="button" class="billing__icon-button billing__icon-button--small" wire:click="startTicketEdit({{ $entry->id }})" title="{{ __('billing.dashboard.ticket_edit') }}" aria-label="{{ __('billing.dashboard.ticket_edit') }}">✎</button>
                @endif
            @endif
            @if ($showTech ?? false)
                <span class="billing__muted">· {{ $entry->user->name }}</span>
            @endif
            @foreach ($warnings as $warning)
                <span class="billing__badge billing__badge--danger">{{ $warning }}</span>
            @endforeach
        </div>
        <div class="billing__entry-actions">
            <span class="billing__muted">{{ $entry->work_date->toDateString() }}</span>
            <button type="button" class="billing__icon-button" wire:click="showDetail({{ $entry->id }})" title="{{ __('billing.list.view') }}" aria-label="{{ __('billing.list.view') }}">👁</button>
            @if ($mine && $entry->user_id === $userId)
                <button type="button" class="billing__icon-button" wire:click="edit({{ $entry->id }})" title="{{ __('billing.list.edit') }}" aria-label="{{ __('billing.list.edit') }}">✎</button>
                <button type="button" class="billing__icon-button" wire:click="delete({{ $entry->id }})" title="{{ __('billing.list.delete') }}" aria-label="{{ __('billing.list.delete') }}">🗑</button>
            @elseif ($canEditOthers ?? false)
                <button type="button" class="billing__icon-button" wire:click="edit({{ $entry->id }})" title="{{ __('billing.list.edit') }}" aria-label="{{ __('billing.list.edit') }}">✎</button>
            @endif
        </div>
    </div>
    @if (($ticketEditable ?? false) && $ticketEditId === $entry->id && $ticketError !== '')
        <p class="billing__row-warning">{{ $ticketError }}</p>
    @endif
    @if ($badges !== [])
        <div class="billing__badges">
            @foreach ($badges as $badge)
                <span class="billing__badge">{{ $badge }}</span>
            @endforeach
        </div>
    @endif
    @if ($entry->comment)
        <p class="billing__comment">{{ $entry->comment }}</p>
    @endif
</div>
