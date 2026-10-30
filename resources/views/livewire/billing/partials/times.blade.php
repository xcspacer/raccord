<div class="billing__grid2">
    <div>
        <label class="billing__label" for="billing-start">{{ __('billing.form.start_time') }}</label>
        <input class="billing__input" type="time" id="billing-start" wire:model.live.debounce.400ms="startTime">
    </div>
    <div>
        <label class="billing__label" for="billing-end">{{ __('billing.form.end_time') }}</label>
        <input class="billing__input" type="time" id="billing-end" wire:model.live.debounce.400ms="endTime">
    </div>
</div>
<div class="billing__end-date">
    <label class="billing__label" for="billing-end-date">{{ __('billing.form.end_date') }}</label>
    <input class="billing__input" type="date" id="billing-end-date" wire:model.live="endDate">
</div>
