<div wire:poll.5s.visible>
    <div class="billing__grid-auto billing__filters">
        <div>
            <label class="billing__label" for="reviews-month">{{ __('billing.expenses.month') }}</label>
            <input class="billing__input" type="month" id="reviews-month" wire:model.live="month">
        </div>
        <div>
            <label class="billing__label" for="reviews-technician">{{ __('billing.detail.technician') }}</label>
            <select class="billing__input" id="reviews-technician" wire:model.live="technician">
                <option value="">{{ __('billing.expenses.all') }}</option>
                @foreach ($technicians as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="billing__label" for="reviews-status">{{ __('billing.expenses.status') }}</label>
            <select class="billing__input" id="reviews-status" wire:model.live="status">
                <option value="">{{ __('billing.expenses.all') }}</option>
                @foreach (\App\Enums\BillingExpenseStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($exportable)
        <div class="billing__panel-buttons">
            @if ($technician === '')
                <a class="billing__button" href="{{ route('billing.exports.expenses', ['month' => $month]) }}" download>{{ __('billing.expenses.export_all') }}</a>
            @else
                <a class="billing__button" href="{{ route('billing.exports.expenses', ['month' => $month, 'technician' => $technician, 'scope' => 'all']) }}" download>{{ __('billing.expenses.export_person', ['name' => $technicians[(int) $technician] ?? '']) }}</a>
                @if ($exportPending)
                    <a class="billing__button" href="{{ route('billing.exports.expenses', ['month' => $month, 'technician' => $technician, 'scope' => 'pending']) }}" download>{{ __('billing.expenses.export_pending') }}</a>
                @endif
                @if ($exportApproved)
                    <a class="billing__button" href="{{ route('billing.exports.expenses', ['month' => $month, 'technician' => $technician, 'scope' => 'approved']) }}" download>{{ __('billing.expenses.export_approved') }}</a>
                @endif
            @endif
        </div>
    @endif

    @if ($pendingOnly)
        <div class="billing__banner billing__banner--accent billing__pending-bar">
            <span class="billing__banner-title">{{ __('billing.expenses.pending_all', ['count' => $pendingCount]) }}</span>
            <button type="button" class="billing__link" wire:click="showPending(false)">{{ __('billing.expenses.back_to_filters') }}</button>
        </div>
    @else
        <p class="billing__section-title">
            {{ __('billing.expenses.count', ['count' => $expenses->count()]) }}@if ($pendingCount > 0) <button type="button" class="billing__link" wire:click="showPending(true)" title="{{ __('billing.expenses.pending_link_title') }}">· {{ __('billing.expenses.pending_link', ['count' => $pendingCount]) }}</button>@endif
            · {{ __('billing.expenses.approved_total', ['month' => $month, 'amount' => \App\Billing\EntryBadges::money($approvedTotal)]) }}
        </p>
    @endif

    @if ($expenses->isEmpty())
        <p class="billing__muted">{{ $pendingOnly ? __('billing.expenses.no_pending') : __('billing.expenses.no_match') }}</p>
    @else
        <div class="billing__entries">
            @foreach ($expenses as $expense)
                @include('livewire.billing.partials.expense-row', ['expense' => $expense, 'showTech' => true, 'canReview' => $canReview])
            @endforeach
        </div>
    @endif

    @if ($detail)
        @include('livewire.billing.partials.expense-detail', ['expense' => $detail])
    @endif
</div>
