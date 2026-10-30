<div class="billing__entry" wire:key="expense-{{ $expense->id }}">
    <div class="billing__entry-head">
        <div class="billing__entry-tags">
            <span class="billing__badge">{{ $expense->category === \App\Enums\BillingExpenseCategory::Other && $expense->category_other ? $expense->category->label().' — '.$expense->category_other : $expense->category->label() }}</span>
            @if ($expense->category === \App\Enums\BillingExpenseCategory::Fuel && $expense->mileage_km !== null)
                <span class="billing__badge">{{ __('app.units.kilometres', ['value' => \App\Billing\EntryBadges::quantity($expense->mileage_km)]) }}</span>
            @endif
            <span class="billing__badge billing__badge--{{ $expense->status->value }}">{{ $expense->status->label() }}</span>
            @if ($showTech ?? false)
                <span class="billing__muted">· {{ $expense->user->name }}</span>
            @endif
        </div>
        <div class="billing__entry-actions">
            <span class="billing__muted">{{ $expense->spent_on->toDateString() }}</span>
            <strong>{{ \App\Billing\EntryBadges::money((float) $expense->amount) }}</strong>
            <button type="button" class="billing__icon-button" wire:click="showDetail({{ $expense->id }})" title="{{ __('billing.list.view') }}" aria-label="{{ __('billing.list.view') }}">👁</button>
            @if (($editable ?? false) && $expense->isPending())
                <button type="button" class="billing__icon-button" wire:click="edit({{ $expense->id }})" title="{{ __('billing.list.edit') }}" aria-label="{{ __('billing.list.edit') }}">✎</button>
                <button type="button" class="billing__icon-button" wire:click="delete({{ $expense->id }})" title="{{ __('billing.list.delete') }}" aria-label="{{ __('billing.list.delete') }}">🗑</button>
            @endif
        </div>
    </div>
    @if ($expense->comment)
        <p class="billing__comment">{{ $expense->comment }}</p>
    @endif
    @if ($expense->status === \App\Enums\BillingExpenseStatus::Rejected && $expense->rejection_reason)
        <p class="billing__row-warning">{{ __('billing.expenses.reason_shown', ['reason' => $expense->rejection_reason]) }}</p>
    @endif
    @if ($expense->photos->isNotEmpty())
        <div class="billing__thumbs billing__thumbs--small">
            @foreach ($expense->photos as $photo)
                <a class="billing__thumb" href="{{ route('billing.expense-photos', [$photo->id, 'full']) }}" target="_blank" rel="noopener"><img src="{{ route('billing.expense-photos', [$photo->id, 'thumb']) }}" alt=""></a>
            @endforeach
        </div>
    @endif
    @if (($canReview ?? false) && $expense->isPending())
        <div class="billing__review">
            @if ($rejectingId === $expense->id)
                <input class="billing__input billing__review-reason" type="text" wire:model="reason" maxlength="255" placeholder="{{ __('billing.expenses.reason_placeholder') }}" aria-label="{{ __('billing.expenses.reason_placeholder') }}">
                <button type="button" class="billing__button" wire:click="confirmReject">✓ {{ __('billing.expenses.confirm_reject') }}</button>
                <button type="button" class="billing__button" wire:click="cancelReject">{{ __('billing.form.cancel') }}</button>
                @error('reason') <p class="billing__error billing__review-error" role="alert">{{ $message }}</p> @enderror
            @else
                <button type="button" class="billing__button" wire:click="approve({{ $expense->id }})">✓ {{ __('billing.expenses.approve') }}</button>
                <button type="button" class="billing__button" wire:click="startReject({{ $expense->id }})">✕ {{ __('billing.expenses.reject') }}</button>
            @endif
        </div>
    @endif
</div>
