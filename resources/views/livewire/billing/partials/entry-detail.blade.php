@php($badges = app(\App\Billing\EntryBadges::class)->for($entry, $amount))
<div class="billing__overlay" wire:click.self="closeDetail">
    <div class="billing__modal billing__modal--wide" role="dialog" aria-modal="true" aria-labelledby="billing-detail-title">
        <div class="billing__card-head">
            <p class="billing__modal-title" id="billing-detail-title">{{ __('billing.detail.title') }}</p>
            <button type="button" class="billing__icon-button" wire:click="closeDetail" aria-label="{{ __('billing.detail.close') }}">✕</button>
        </div>
        <div class="billing__badges">
            <span class="billing__badge billing__badge--{{ $entry->type->value }}">{{ $entry->type->label() }}</span>
            <span class="billing__badge">{{ $entry->billingClient->name }}</span>
            @foreach ($badges as $badge)
                <span class="billing__badge">{{ $badge }}</span>
            @endforeach
        </div>
        <div class="billing__detail-row"><span>{{ __('billing.detail.technician') }}</span><strong>{{ $entry->user->name }}@if ($entry->partners->isNotEmpty()) (+ {{ $entry->partners->pluck('name')->implode(', ') }})@endif</strong></div>
        <div class="billing__detail-row"><span>{{ __('billing.detail.ticket') }}</span><strong>{{ $entry->ticket }} · {{ $entry->work_date->toDateString() }}</strong></div>
        <p class="billing__detail-title">{{ __('billing.detail.report') }}</p>
        @if ($entry->comment)
            <p class="billing__report">{{ $entry->comment }}</p>
        @else
            <p class="billing__muted">{{ __('billing.detail.no_comment') }}</p>
        @endif
        <p class="billing__detail-title">{{ __('billing.detail.photos') }}</p>
        @if ($entry->photos->isEmpty())
            <p class="billing__muted">{{ __('billing.detail.no_photo') }}</p>
        @else
            @php($groups = $entry->type === \App\Enums\BillingEntryType::Works ? ['before' => $entry->photos->where('category', \App\Enums\BillingPhotoCategory::Before), 'after' => $entry->photos->where('category', \App\Enums\BillingPhotoCategory::After), 'other' => $entry->photos->whereNull('category')] : ['all' => $entry->photos])
            @foreach ($groups as $group => $photos)
                @if ($photos->isNotEmpty())
                    @if ($group !== 'all' && ($group !== 'other' || count(array_filter([$groups['before']->count(), $groups['after']->count()])) > 0))
                        <p class="billing__muted billing__detail-group">{{ __('billing.detail.groups.'.$group) }}</p>
                    @endif
                    <div class="billing__thumbs billing__thumbs--large">
                        @foreach ($photos as $photo)
                            <a class="billing__thumb" href="{{ route('billing.photos', [$photo->id, 'full']) }}" target="_blank" rel="noopener"><img src="{{ route('billing.photos', [$photo->id, 'thumb']) }}" alt=""></a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        @endif
        <div class="billing__actions">
            <button type="button" class="billing__button billing__button--grow" wire:click="closeDetail">{{ __('billing.detail.close') }}</button>
        </div>
    </div>
</div>
