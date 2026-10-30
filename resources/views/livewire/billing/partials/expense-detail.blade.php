<div class="billing__overlay" wire:click.self="closeDetail">
    <div class="billing__modal billing__modal--narrow" role="dialog" aria-modal="true" aria-labelledby="billing-expense-title">
        <div class="billing__card-head">
            <p class="billing__modal-title" id="billing-expense-title">{{ __('billing.expenses.detail_title') }}</p>
            <button type="button" class="billing__icon-button" wire:click="closeDetail" aria-label="{{ __('billing.detail.close') }}">✕</button>
        </div>
        <div class="billing__badges">
            <span class="billing__badge billing__badge--{{ $expense->status->value }}">{{ $expense->status->label() }}</span>
            <span class="billing__badge">{{ $expense->category === \App\Enums\BillingExpenseCategory::Other && $expense->category_other ? $expense->category->label().' — '.$expense->category_other : $expense->category->label() }}</span>
        </div>
        <div class="billing__detail-row"><span>{{ __('billing.detail.technician') }}</span><strong>{{ $expense->user->name }}</strong></div>
        <div class="billing__detail-row"><span>{{ __('billing.expenses.date') }}</span><strong>{{ $expense->spent_on->format('d/m/Y') }}</strong></div>
        <div class="billing__detail-row"><span>{{ __('billing.expenses.amount_label') }}</span><strong>{{ \App\Billing\EntryBadges::money((float) $expense->amount) }}</strong></div>
        @if ($expense->category === \App\Enums\BillingExpenseCategory::Fuel && $expense->mileage_km !== null)
            <div class="billing__detail-row"><span>{{ __('billing.expenses.mileage_label') }}</span><strong>{{ __('app.units.kilometres', ['value' => \App\Billing\EntryBadges::quantity($expense->mileage_km)]) }}</strong></div>
        @endif
        @if ($expense->comment)
            <div class="billing__detail-row"><span>{{ __('billing.expenses.comment_label') }}</span><strong>{{ $expense->comment }}</strong></div>
        @endif
        @if ($expense->status === \App\Enums\BillingExpenseStatus::Rejected)
            <div class="billing__detail-row"><span>{{ __('billing.expenses.reason_label') }}</span><strong>{{ $expense->rejection_reason ?: '—' }}</strong></div>
        @endif
        <p class="billing__detail-title">{{ __('billing.expenses.attachments') }}</p>
        @if ($expense->photos->isEmpty())
            <p class="billing__muted">{{ __('billing.expenses.no_attachment') }}</p>
        @else
            <div class="billing__thumbs billing__thumbs--large">
                @foreach ($expense->photos as $photo)
                    <a class="billing__thumb" href="{{ route('billing.expense-photos', [$photo->id, 'full']) }}" target="_blank" rel="noopener"><img src="{{ route('billing.expense-photos', [$photo->id, 'thumb']) }}" alt=""></a>
                @endforeach
            </div>
        @endif
        <div class="billing__actions">
            <a class="billing__button billing__button--grow" href="{{ route('billing.expenses.export', $expense->id) }}" download>{{ __('billing.expenses.export_one') }}</a>
            <button type="button" class="billing__button billing__button--grow" wire:click="closeDetail">{{ __('billing.detail.close') }}</button>
        </div>
    </div>
</div>
