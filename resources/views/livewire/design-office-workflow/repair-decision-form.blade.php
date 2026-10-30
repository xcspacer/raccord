<div class="repairs-decision">
    @if ($recheck)
        <section class="settings-card">
            <h2 class="settings-card__title">{{ __('design_office_workflow.repairs.recheck') }}</h2>
            <p class="review-card__meta">
                <span @class(['repairs-chip', 'repairs-chip--ok' => $recheck['is_conform'], 'repairs-chip--warn' => ! $recheck['is_conform']])>{{ $recheck['is_conform'] ? __('design_office_workflow.repairs.recheck_conform') : __('design_office_workflow.repairs.recheck_not_conform') }}</span>
                @if ($recheck['recnok_name']) · {{ $recheck['recnok_name'] }}@endif
                @if ($recheck['saved_by']) · {{ $recheck['saved_by'] }}@endif
                @if ($recheck['saved_at']) · {{ \Illuminate\Support\Carbon::parse($recheck['saved_at'])->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}@endif
            </p>
            <a class="btn btn--ghost btn--small" href="{{ route('audits.recheck', ['cabinet' => $request->box->audit->cabinet_key, 'box' => $request->box->id]) }}" target="_blank" rel="noopener">{{ __('design_office_workflow.repairs.open_recheck') }}</a>
        </section>
    @endif

    <form class="settings-card" wire:submit="decide">
        <h2 class="settings-card__title">{{ __('design_office_workflow.repairs.items_title') }}</h2>

        @error('decision') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

        @foreach ($items as $item)
            @php($verdict = $verdictsByItem->get($item['id']))
            <article class="review-occurrence repairs-decision__item" wire:key="repair-{{ $item['id'] }}">
                <div class="review-card__title">
                    <span class="review-card__box">{{ $item['reference'] }}</span>
                    <span class="review-card__cabinet">{{ $item['type'] }}</span>
                    <span class="review-nature review-nature--{{ $item['nature'] }}">{{ \App\Enums\AuditAnomalyNature::from($item['nature'])->label() }}</span>
                    @if ($verdict)
                        <span @class(['repairs-chip', 'repairs-chip--ok' => $verdict->is_accepted, 'repairs-chip--bad' => ! $verdict->is_accepted])>{{ $verdict->is_accepted ? __('design_office_workflow.request.accepted') : __('design_office_workflow.request.refused') }}</span>
                    @endif
                </div>
                <p class="review-card__text">{{ $item['description'] }}</p>

                @foreach (['before' => __('design_office_workflow.request.before'), 'after' => __('design_office_workflow.request.after')] as $role => $label)
                    @if ($item[$role] !== [])
                        <div class="review-folder">
                            <h3 class="review-folder__title">{{ $label }}</h3>
                            <div class="review-thumbs">
                                @foreach ($item[$role] as $id)
                                    @if ($photos->has($id))
                                        <a class="review-thumb" href="{{ route('audits.photos.show', [$id, 'full']) }}" target="_blank" rel="noopener">
                                            <img src="{{ route('audits.photos.show', [$id, 'thumb']) }}" alt="{{ $label }}" loading="lazy">
                                        </a>
                                    @else
                                        <span class="review-thumb review-thumb--gone">{{ __('design_office_workflow.request.photo_gone') }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

                @if ($item['action'])<p class="review-card__text"><b>{{ __('design_office_workflow.repairs.action') }}</b> {{ $item['action'] }}</p>@endif
                @if ($item['checks'])<p class="review-card__text"><b>{{ __('design_office_workflow.repairs.checks') }}</b> {{ $item['checks'] }}</p>@endif
                @if ($item['observations'])<p class="review-card__text"><b>{{ __('design_office_workflow.repairs.observations') }}</b> {{ $item['observations'] }}</p>@endif

                @if ($verdict)
                    @if ($verdict->reason)<p class="review-card__text">{{ $verdict->reason }}</p>@endif
                @elseif (! $request->isDecided())
                    <div class="repairs-decision__verdict">
                        <div class="segmented">
                            <label><input type="radio" value="accepted" wire:model="verdicts.{{ $item['id'] }}"> {{ __('design_office_workflow.request.accept') }}</label>
                            <label><input type="radio" value="refused" wire:model="verdicts.{{ $item['id'] }}"> {{ __('design_office_workflow.request.refuse') }}</label>
                        </div>
                        <input class="field__input repairs-decision__reason" wire:model="reasons.{{ $item['id'] }}" maxlength="2000" placeholder="{{ __('design_office_workflow.repairs.item_reason') }}" aria-label="{{ __('design_office_workflow.repairs.item_reason') }} {{ $item['reference'] }}">
                    </div>
                @endif
            </article>
        @endforeach

        @if ($request->isDecided() && $request->decision)
            <p><span class="review-outcome review-outcome--{{ $request->decision->type->outcome->value }}">{{ $request->decision->type->displayLabel() }}</span></p>
            <p class="review-card__meta">{{ __('design_office_workflow.request.decided_by', ['name' => $request->decision->decider->name, 'time' => $request->decision->decided_at->timezone(config('platform.timezone'))->format('d/m/Y H:i:s')]) }}</p>
            @if ($request->decision->reason)
                <p class="review-card__text">{{ $request->decision->reason }}</p>
            @endif
        @else
            <div class="field">
                <label class="field__label" for="repairs-comment">{{ __('design_office_workflow.repairs.global_reason') }}</label>
                <textarea class="field__input" id="repairs-comment" rows="2" wire:model="comment" maxlength="2000"></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary" wire:loading.attr="disabled">{{ __('design_office_workflow.repairs.submit') }}</button>
            </div>
        @endif
    </form>
</div>
