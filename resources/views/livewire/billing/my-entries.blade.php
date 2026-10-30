@php
    $works = $type === \App\Enums\BillingEntryType::Works->value;
    $form = $client?->form_type;
    $types = $client?->interventionTypes ?? collect();
@endphp

<div @unless ($embedded) wire:poll.5s.visible @endunless>
    @if ($pickingClient && $editingId === null && ! $embedded)
        <div class="billing__overlay" role="dialog" aria-modal="true" aria-labelledby="billing-client-title">
            <div class="billing__modal">
                <p class="billing__modal-title" id="billing-client-title">{{ __('billing.form.pick_client') }}</p>
                <p class="billing__modal-text">{{ __('billing.form.pick_client_text') }}</p>
                <div class="billing__client-grid">
                    @foreach ($clients as $option)
                        <button type="button" @class(['billing__client-option', 'is-on' => (string) $option->id === $clientId]) wire:click="chooseClient({{ $option->id }})">{{ $option->name }}</button>
                    @endforeach
                </div>
                <div class="billing__modal-actions">
                    <button type="button" class="billing__button billing__button--small" wire:click="closeClientPicker">✕ {{ __('billing.form.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    @unless ($embedded)
    <div class="billing__heading">
        <p class="billing__eyebrow">{{ __('billing.form.eyebrow') }}</p>
        <h2 class="billing__title">{{ __('billing.form.title') }}</h2>
    </div>

    @if ($reminders !== [])
        <div class="billing__banner billing__banner--accent">
            <p class="billing__banner-title">{{ __('billing.reminders.title', ['count' => count($reminders)]) }}</p>
            @foreach ($reminders as $reminder)
                <p class="billing__banner-line">{!! __('billing.reminders.line', ['name' => e($reminder['entry']->user->name), 'client' => '<strong>'.e($reminder['entry']->billingClient->name).'</strong>', 'date' => $reminder['entry']->work_date->format('d/m/Y'), 'ticket' => e($reminder['entry']->ticket)]) !!}</p>
            @endforeach
        </div>
    @endif

    @if ($partnerMismatches->isNotEmpty())
        <div class="billing__banner billing__banner--danger">
            <p class="billing__banner-title">{{ __('billing.conflicts.crew_title', ['count' => $partnerMismatches->count()]) }}</p>
            <p class="billing__banner-line">{{ __('billing.conflicts.crew_text') }}</p>
        </div>
    @endif

    @if ($contentMismatches->isNotEmpty())
        <div class="billing__banner billing__banner--danger">
            <p class="billing__banner-title">{{ __('billing.conflicts.content_title', ['count' => $contentMismatches->count()]) }}</p>
            <p class="billing__banner-line">{{ __('billing.conflicts.content_text') }}</p>
        </div>
    @endif

    @endunless

    <form class="billing__card" wire:submit="save">
        <div class="billing__card-head">
            <p class="billing__card-title">{{ $editingId ? __('billing.form.edit_title') : __('billing.form.new_title') }}</p>
            <p class="billing__muted">{{ __('billing.form.month_count', ['count' => $monthCount]) }}</p>
        </div>

        @if ($editingId)
            <p class="billing__muted billing__client-locked">{!! __('billing.form.client_locked', ['client' => '<strong>'.e($client?->name).'</strong>']) !!}</p>
        @else
            <div class="billing__client-row">
                <p>{!! __('billing.form.client', ['client' => '<strong>'.e($client?->name ?? '—').'</strong>']) !!}</p>
                <button type="button" class="billing__button billing__button--small" wire:click="openClientPicker">{{ __('billing.form.change_client') }}</button>
            </div>
        @endif

        @if ($client)
            <div class="billing__toggle">
                <button type="button" @class(['billing__toggle-option', 'is-on' => ! $works]) wire:click="switchType('breakdown')" @disabled(! $client->has_breakdown) @if (! $client->has_breakdown) title="{{ __('billing.form.no_breakdown', ['client' => $client->name]) }}" @endif>{{ __('enums.billing_entry_types.breakdown') }}</button>
                <button type="button" @class(['billing__toggle-option', 'is-on' => $works]) wire:click="switchType('works')" @disabled(! $client->has_works) @if (! $client->has_works) title="{{ __('billing.form.no_works') }}" @endif>{{ __('enums.billing_entry_types.works') }}</button>
            </div>

            <div class="billing__grid2">
                <div>
                    <label class="billing__label" for="billing-date">{{ $timed ? __('billing.form.start_date') : __('billing.form.date') }}</label>
                    <input class="billing__input" type="date" id="billing-date" wire:model.live="date">
                </div>
                <div>
                    <label class="billing__label" for="billing-ticket">{{ __('billing.form.ticket') }}</label>
                    <input class="billing__input" type="text" id="billing-ticket" wire:model.blur="ticket" maxlength="60" placeholder="{{ $client->ticket_example ?: __('billing.form.ticket_placeholder') }}">
                    @if ($client->ticket_hint)
                        <p class="billing__hint">{{ __('billing.form.ticket_format', ['hint' => $client->ticket_hint, 'example' => $client->ticket_example]) }}</p>
                    @endif
                </div>
            </div>

            @include('livewire.billing.partials.form-fields')

            @if ($works && $form !== \App\Enums\BillingFormType::DayRatePair)
                <div class="billing__block">
                    <label class="billing__label">{{ __('billing.form.articles') }}</label>
                    <input class="billing__input billing__input--wide" type="text" wire:model.live.debounce.250ms="articleSearch" placeholder="{{ __('billing.form.article_search') }}" aria-label="{{ __('billing.form.article_search') }}">
                    <div class="billing__row">
                        <select class="billing__input billing__input--grow2" wire:model="articleToAdd" aria-label="{{ __('billing.form.article_pick') }}">
                            <option value="">{{ __('billing.form.article_pick') }}</option>
                            @foreach ($articleOptions as $article)
                                <option value="{{ $article->id }}">{{ $article->label }} ({{ $article->unit }})</option>
                            @endforeach
                        </select>
                        <input class="billing__input billing__input--grow1" type="number" min="0" step="0.01" wire:model="articleQuantity" placeholder="{{ __('billing.form.quantity') }}" aria-label="{{ __('billing.form.quantity') }}">
                        <button type="button" class="billing__button" wire:click="addLine">+ {{ __('billing.form.add') }}</button>
                    </div>
                    @if ($lines !== [])
                        <div class="billing__lines">
                            @foreach ($lines as $line)
                                @php($article = $articles->firstWhere('id', $line['article']))
                                <div class="billing__line" wire:key="line-{{ $line['article'] }}">
                                    <span><strong>{{ \App\Billing\EntryBadges::quantity($line['quantity']) }}</strong> {{ $article ? $article->unit.' — '.$article->label : '' }}</span>
                                    <button type="button" class="billing__icon-button" wire:click="removeLine({{ $line['article'] }})" aria-label="{{ __('billing.form.remove_named', ['name' => $article?->code]) }}">✕</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="billing__block">
                <label class="billing__label">{{ __('billing.form.partners') }}</label>
                <label @class(['billing__choice', 'billing__choice--alone', 'is-on' => $alone])>
                    <input type="checkbox" wire:model.live="alone">
                    <span>{{ __('billing.form.alone') }}</span>
                </label>
                <input class="billing__input billing__input--wide" type="text" wire:model.live.debounce.250ms="partnerSearch" placeholder="{{ __('billing.form.partner_search') }}" aria-label="{{ __('billing.form.partner_search') }}" @disabled($alone)>
                <div class="billing__row">
                    <select class="billing__input billing__input--grow1" wire:model="partnerToAdd" @disabled($alone) aria-label="{{ __('billing.form.partner_pick') }}">
                        <option value="">{{ $partnerOptions->isEmpty() && trim($partnerSearch) !== '' ? __('billing.form.partner_none') : __('billing.form.partner_pick') }}</option>
                        @foreach ($partnerOptions as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="billing__button" wire:click="addPartner" @disabled($alone)>+ {{ __('billing.form.add') }}</button>
                </div>
                @if ($partners !== [])
                    <div class="billing__chips">
                        @foreach ($candidates->whereIn('id', $partners) as $partner)
                            <span class="billing__chip" wire:key="partner-{{ $partner->id }}">{{ $partner->name }} <button type="button" wire:click="removePartner({{ $partner->id }})" aria-label="{{ __('billing.form.remove_named', ['name' => $partner->name]) }}">✕</button></span>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($liveCrew)
                <div class="billing__banner billing__banner--danger billing__banner--inline">
                    <p class="billing__banner-title">{{ __('billing.conflicts.live_crew_title') }}</p>
                    <p class="billing__banner-line">{{ __('billing.conflicts.live_crew', ['name' => $liveCrew->user->name, 'declared' => $liveCrew->partners->isEmpty() ? __('billing.conflicts.no_partner') : __('billing.conflicts.partner_list', ['names' => $liveCrew->partners->pluck('name')->implode(', ')]), 'ticket' => $liveCrew->ticket, 'date' => $liveCrew->work_date->toDateString()]) }}</p>
                </div>
            @endif

            @if ($liveContent)
                <div class="billing__banner billing__banner--danger billing__banner--inline">
                    <p class="billing__banner-title">{{ __('billing.conflicts.live_content_title') }}</p>
                    <p class="billing__banner-line">{{ __('billing.conflicts.live_content', ['name' => $liveContent->user->name, 'ticket' => $liveContent->ticket, 'date' => $liveContent->work_date->toDateString(), 'client' => $liveContent->billingClient->name]) }}</p>
                </div>
            @endif

            <div class="billing__block">
                <label class="billing__label" for="billing-comment">{{ __('billing.form.comment') }}</label>
                <textarea class="billing__input billing__input--wide" id="billing-comment" rows="3" wire:model="comment" placeholder="{{ __('billing.form.comment_placeholder') }}"></textarea>
            </div>

            @foreach ($works ? ['before', 'after'] : ['none'] as $group)
                @include('livewire.billing.partials.photo-group', ['group' => $group, 'label' => __('billing.form.photos.'.$group), 'existing' => $existingPhotos->filter(fn ($photo) => $group === 'none' ? $photo->category === null : $photo->category?->value === $group)])
            @endforeach
            @if ($works && $existingPhotos->whereNull('category')->isNotEmpty())
                @include('livewire.billing.partials.photo-group', ['group' => 'none', 'label' => __('billing.form.photos.other'), 'existing' => $existingPhotos->whereNull('category')])
            @endif

            @if ($error !== '')
                <p class="billing__error" role="alert">{{ $error }}</p>
            @endif

            <div class="billing__actions">
                <button type="submit" class="billing__button billing__button--grow">{{ $editingId ? '✓ '.__('billing.form.save_changes') : '+ '.__('billing.form.add_entry') }}</button>
                @if ($editingId && ! $embedded)
                    <button type="button" class="billing__button" wire:click="cancelEdit">✕ {{ __('billing.form.cancel') }}</button>
                @endif
            </div>
        @endif
    </form>

    @unless ($embedded)
    <p class="billing__section-title">{{ __('billing.list.title') }}</p>
    @if ($months->isEmpty())
        <p class="billing__muted">{{ __('billing.list.empty') }}</p>
    @else
        <div class="billing__months">
            @foreach ($months as $key => $count)
                <button type="button" @class(['billing__month', 'is-on' => $key === $activeMonth]) wire:click="chooseMonth('{{ $key }}')">{{ \Illuminate\Support\Str::ucfirst(\Carbon\CarbonImmutable::createFromFormat('!Y-m', $key)->translatedFormat('F Y')) }} ({{ $count }})</button>
            @endforeach
        </div>
        @if ($typeSummary->isNotEmpty())
            <div class="billing__summary">
                @foreach ($typeSummary as $label => $count)
                    <span class="billing__pill">{{ $label }} : {{ $count }}</span>
                @endforeach
            </div>
        @endif
        <div class="billing__entries">
            @foreach ($monthEntries as $entry)
                <div wire:key="entry-{{ $entry->id }}">
                    @include('livewire.billing.partials.entry-row', ['entry' => $entry, 'warnings' => array_filter([$partnerMismatches->has($entry->id) ? __('billing.warnings.crew') : null, $contentMismatches->has($entry->id) ? __('billing.warnings.content') : null]), 'mine' => true, 'amount' => null])
                    @if ($partnerMismatches->has($entry->id))
                        @php($other = $partnerMismatches->get($entry->id))
                        <p class="billing__row-warning">{{ __('billing.conflicts.row_crew', ['name' => $other->user->name, 'declared' => $other->partners->isEmpty() ? __('billing.conflicts.no_partner') : __('billing.conflicts.partner_list', ['names' => $other->partners->pluck('name')->implode(', ')])]) }}</p>
                    @endif
                    @if ($contentMismatches->has($entry->id))
                        <p class="billing__row-warning">{{ __('billing.conflicts.row_content', ['name' => $contentMismatches->get($entry->id)->user->name]) }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($detail)
        @include('livewire.billing.partials.entry-detail', ['entry' => $detail, 'amount' => null])
    @endif
    @endunless
</div>
