@php
    $palette = config('audit.recheck_palette');
    $options = function (string $selected, ?string $placeholder = null) use ($cables) {
        return [$selected, $placeholder];
    };
@endphp

<div class="recheck">
    <div class="recheck__bar">
        <button type="button" class="btn btn--ghost" wire:click="leave" @if ($canEdit && $record->has_unsaved_changes) wire:confirm="{{ __('audit.recheck.unsaved') }}" @endif>← {{ __('audit.recheck.back') }}</button>
        <span class="recheck__title">🔧 {{ __('audit.recheck.title') }} · {{ $box->name }}</span>
    </div>
    @if ($validated && $isManager)
        @if ($editing)
            <button type="button" class="btn btn--primary" wire:click="stopEditing">✓ {{ __('audit.field.finish_editing') }}</button>
        @else
            <button type="button" class="btn btn--ghost" wire:click="startEditing">✎ {{ __('audit.field.edit') }}</button>
        @endif
    @endif
    <p class="recheck__plan">{{ __('audit.recheck.plan_file') }} : <b>{{ $record->recnok_name ?: $planName }}</b></p>
    @error('plan') <p class="field__error">{{ $message }}</p> @enderror

    @if ($error)
        <p class="recheck__empty">{{ $error }}</p>
    @else
        @php($isNonConform = $box->recheck_state === 'nonconforming')
        @php($isConform = $boxConform && ! $isNonConform)
        @if ($canEdit)
            <div class="recheck__verdict">
                @if (! $modified || $isConform)
                    <button type="button" @class(['recheck__verdict-button', 'is-ok', 'is-on' => $isConform, 'is-dim' => $isNonConform]) wire:click="conform" @unless ($boxConform) wire:confirm="{{ __('audit.recheck.conform_question').($record->has_unsaved_changes ? "\n\n".__('audit.recheck.conform_lose') : '') }}" @endunless>✓ {{ __('audit.recheck.conform') }}</button>
                @endif
                <button type="button" @class(['recheck__verdict-button', 'is-bad', 'is-on' => $isNonConform, 'is-dim' => $isConform]) wire:click="toggleNonConform">✗ {{ __('audit.recheck.nonconform') }}</button>
            </div>
            @if ($isNonConform)
                <p class="recheck__state is-bad">{{ __('audit.recheck.nonconform_state') }}</p>
            @elseif ($isConform)
                <p class="recheck__state is-ok">{{ __('audit.recheck.conform_state') }}</p>
            @elseif (! $modified)
                <p class="recheck__small">{{ __('audit.recheck.conform_hint') }}</p>
            @endif
        @elseif ($isConform)
            <p class="recheck__badge is-ok">✓ {{ __('audit.recheck.conform_badge') }}</p>
        @elseif ($isNonConform)
            <p class="recheck__badge is-bad">✗ {{ __('audit.recheck.nonconform_badge') }}</p>
        @endif
        @if ($isNonConform || $paperPhotos > 0)
            <p class="recheck__small">📄 {{ __('audit.recheck.paper_title') }} ({{ $paperPhotos }}) — {{ __('audit.recheck.paper_hint') }} <a href="{{ $backUrl }}" wire:navigate>{{ __('audit.recheck.paper_open') }}</a></p>
        @endif

        <div class="recheck__top">
            <div class="recheck__count">
                @foreach ($types as $type)
                    @unless ($loop->first) · @endunless<span class="recheck__tally is-{{ $type->value }}">{{ $counts[$type->value] }}</span> {{ $type->label() }}
                @endforeach
            </div>
            <div class="recheck__filters">
                <button type="button" @class(['recheck__chip', 'is-active' => $filter === 'all']) wire:click="setFilter('all')">{{ __('audit.recheck.all') }}</button>
                <button type="button" @class(['recheck__chip', 'is-active' => $filter === 'todo']) wire:click="setFilter('todo')">{{ __('audit.recheck.todo') }} ({{ $counts['waiting'] }})</button>
            </div>
        </div>

        @php($shown = collect($cassettes)->filter(fn ($c) => $filter !== 'todo' || $c['counts']['waiting'] > 0))
        @if ($shown->isEmpty())
            <p class="recheck__empty">{{ __('audit.recheck.nothing') }}</p>
        @endif
        @foreach ($shown as $cassette)
            @php($folded = in_array($cassette['name'], $collapsed, true) && $filter !== 'todo')
            <section class="recheck__cassette" wire:key="cassette-{{ $cassette['name'] }}">
                <button type="button" class="recheck__toggle" wire:click="toggleCassette(@js($cassette['name']))">
                    <b>{{ $folded ? '▸' : '▾' }} {{ $cassette['name'] ?: '—' }}</b>
                    <span class="recheck__small">{{ count($cassette['lines']) }} · <span class="recheck__tally is-waiting">{{ $cassette['counts']['waiting'] }}</span>/<span class="recheck__tally is-splice">{{ $cassette['counts']['splice'] }}</span>/<span class="recheck__tally is-pass">{{ $cassette['counts']['pass'] }}</span></span>
                </button>
                <div class="recheck__small">{{ \App\Audit\Recheck\BoxRecheck::compactCable($cassette['lines'][0]['cable_a']) }}</div>

                @if ($canEdit)
                    <div class="recheck__actions">
                        <label class="recheck__number">{{ __('audit.recheck.cassette_number') }}
                            <input type="text" inputmode="numeric" maxlength="3" value="{{ $cassette['number']['value'] }}" placeholder="{{ $cassette['number']['default'] ?: '--' }}" wire:change="setCassetteNumber(@js($cassette['name']), $event.target.value)">
                        </label>
                        <span>{{ __('audit.recheck.whole_cassette') }}</span>
                        @foreach ($types as $type)
                            <button type="button" @class(['recheck__button', 'is-on is-'.$type->value => collect($cassette['lines'])->every(fn ($l) => $l['type'] === $type->value)]) wire:click="setCassetteType(@js($cassette['name']), '{{ $type->value }}')">{{ $type->label() }}</button>
                        @endforeach
                        <button type="button" @class(['recheck__button', 'is-on is-splice' => $cassette['straight']]) title="{{ __('audit.recheck.straight_hint') }}" wire:click="straight(@js($cassette['name']))">{{ __('audit.recheck.straight') }}</button>
                        <button type="button" @class(['recheck__button', 'is-on is-splice' => $cassette['conform']]) title="{{ __('audit.recheck.conform_cassette_hint') }}" wire:click="conformCassette(@js($cassette['name']))" @if (collect($cassette['lines'])->contains('changed', true)) wire:confirm="{{ __('audit.recheck.conform_cassette_question') }}" @endif>✓ {{ __('audit.recheck.conform_cassette') }}</button>
                        <select class="recheck__select" wire:change="setCassetteCable(@js($cassette['name']), 'cable_b', $event.target.value)" aria-label="{{ __('audit.recheck.cable_b') }}">
                            @include('livewire.audit.partials.recheck-cable-options', ['selected' => $cassette['cable_b'], 'placeholder' => __('audit.recheck.cable_b').'…', 'cables' => $cables, 'manual' => true])
                        </select>
                        <button type="button" @class(['recheck__button', 'is-on' => isset($extraCable[$cassette['name']])]) wire:click="toggleExtraCable(@js($cassette['name']))">＋ {{ __('audit.recheck.cable_b') }}</button>
                        <select class="recheck__select" wire:change="setCassetteCable(@js($cassette['name']), 'cable_a', $event.target.value)" aria-label="{{ __('audit.recheck.cable_a') }}">
                            @include('livewire.audit.partials.recheck-cable-options', ['selected' => $cassette['cable_a'], 'placeholder' => __('audit.recheck.cable_a').'…', 'cables' => $cables, 'manual' => true])
                        </select>
                    </div>

                    @if (isset($extraCable[$cassette['name']]))
                        @php($extra = $extraCable[$cassette['name']])
                        <div class="recheck__extra">
                            <div class="recheck__label">{{ __('audit.recheck.extra_title') }}</div>
                            <p class="recheck__small">{{ __('audit.recheck.extra_hint') }}</p>
                            <select class="recheck__select" wire:change="setExtraCable(@js($cassette['name']), $event.target.value)">
                                @include('livewire.audit.partials.recheck-cable-options', ['selected' => $extra['cable'], 'placeholder' => '—', 'cables' => $cables, 'manual' => true])
                            </select>
                            <div class="recheck__extra-lines">
                                @foreach ($cassette['lines'] as $line)
                                    <button type="button" @class(['recheck__extra-line', 'is-on' => in_array($line['index'], $extra['lines'], true)]) wire:click="toggleExtraLine(@js($cassette['name']), {{ $line['index'] }})">{{ $line['tube_a'] ? $line['tube_a'].'·' : '' }}{{ $line['fibre_a'] ?: '?' }}@if ($line['cable_b'] !== '')<small>{{ mb_substr(\Illuminate\Support\Str::before(\App\Audit\Recheck\BoxRecheck::compactCable($line['cable_b']), ' · '), 0, 22) }}</small>@endif</button>
                                @endforeach
                            </div>
                            <div class="recheck__actions">
                                <button type="button" class="recheck__button" wire:click="toggleAllExtraLines(@js($cassette['name']))">{{ __('audit.recheck.all_lines') }}</button>
                                <button type="button" class="recheck__button is-splice" wire:click="applyExtraCable(@js($cassette['name']))" @disabled($extra['cable'] === '' || $extra['lines'] === [])>✓ {{ __('audit.recheck.apply') }}</button>
                            </div>
                        </div>
                    @endif
                @endif

                @unless ($folded)
                    @foreach ($cassette['lines'] as $line)
                        @continue($filter === 'todo' && $line['type'] !== 'waiting')
                        @php($fibre = \App\Livewire\Audit\BoxRecheckPage::number($line['fibre_b']))
                        @php($tube = \App\Livewire\Audit\BoxRecheckPage::number($line['tube_b']))
                        <div @class(['recheck__line', 'is-'.$line['type'] => $line['type'] !== '']) wire:key="line-{{ $line['index'] }}">
                            <div class="recheck__row">
                                @include('livewire.audit.partials.recheck-chip', ['code' => $line['tube_a'], 'palette' => $palette])
                                @include('livewire.audit.partials.recheck-chip', ['code' => $line['fibre_a'], 'palette' => $palette])
                                @if ($line['type'] !== 'waiting')
                                    <span class="recheck__arrow">→</span>
                                    @if ($fibre) @include('livewire.audit.partials.recheck-chip', ['code' => $line['fibre_b'], 'palette' => $palette]) @else <span class="recheck__chip-empty">F?</span> @endif
                                    @if ($tube) @include('livewire.audit.partials.recheck-chip', ['code' => $line['tube_b'], 'palette' => $palette]) @else <span class="recheck__chip-empty">T?</span> @endif
                                @endif
                                @if ($canEdit)
                                    <button type="button" @class(['recheck__edit', 'is-on' => in_array($line['index'], $editingInput, true)]) title="{{ __('audit.recheck.edit_input') }}" wire:click="toggleInput({{ $line['index'] }})">✎</button>
                                @endif
                            </div>

                            @if ($canEdit && in_array($line['index'], $editingInput, true))
                                <div class="recheck__input">
                                    @foreach (['fibre_a' => 'fibre_a_label', 'tube_a' => 'tube_a_label'] as $field => $label)
                                        <div class="recheck__label">{{ __('audit.recheck.'.$label) }} @if (\App\Livewire\Audit\BoxRecheckPage::number($line[$field])) <button type="button" class="recheck__change" wire:click="setColour({{ $line['index'] }}, '{{ $field }}', 0)">{{ __('audit.recheck.change') }}</button> @endif</div>
                                        @unless (\App\Livewire\Audit\BoxRecheckPage::number($line[$field]))
                                            @include('livewire.audit.partials.recheck-palette', ['index' => $line['index'], 'field' => $field, 'selected' => 0, 'palette' => $palette])
                                        @endunless
                                    @endforeach
                                    <div class="recheck__label">{{ __('audit.recheck.cable_a') }}</div>
                                    <select class="recheck__select" wire:change="setCable({{ $line['index'] }}, 'cable_a', $event.target.value)">
                                        @include('livewire.audit.partials.recheck-cable-options', ['selected' => $line['cable_a'], 'placeholder' => '—', 'cables' => $cables, 'manual' => true])
                                    </select>
                                </div>
                            @endif

                            <div class="recheck__types">
                                @foreach ($types as $type)
                                    <button type="button" @class(['recheck__type', 'is-on is-'.$type->value => $line['type'] === $type->value]) wire:click="setType({{ $line['index'] }}, '{{ $type->value }}')" @disabled(! $canEdit)>{{ $type->label() }}</button>
                                @endforeach
                            </div>

                            @if ($line['type'] !== 'waiting')
                                @if ($canEdit)
                                    <div class="recheck__label">{{ __('audit.recheck.fibre_b_label') }} @if ($fibre) <button type="button" class="recheck__change" wire:click="setColour({{ $line['index'] }}, 'fibre_b', 0)">{{ __('audit.recheck.change') }}</button> @endif</div>
                                    @unless ($fibre) @include('livewire.audit.partials.recheck-palette', ['index' => $line['index'], 'field' => 'fibre_b', 'selected' => 0, 'palette' => $palette]) @endunless
                                    <div class="recheck__label">{{ __('audit.recheck.tube_b_label') }} @if ($tube) <button type="button" class="recheck__change" wire:click="setColour({{ $line['index'] }}, 'tube_b', 0)">{{ __('audit.recheck.change') }}</button> @endif</div>
                                    @unless ($tube) @include('livewire.audit.partials.recheck-palette', ['index' => $line['index'], 'field' => 'tube_b', 'selected' => 0, 'palette' => $palette]) @endunless
                                    <div class="recheck__label">{{ __('audit.recheck.cable_b') }}</div>
                                    <select class="recheck__select" wire:change="setCable({{ $line['index'] }}, 'cable_b', $event.target.value)">
                                        @include('livewire.audit.partials.recheck-cable-options', ['selected' => $line['cable_b'], 'placeholder' => '—', 'cables' => $cables, 'manual' => true])
                                    </select>
                                    @if (count($cables['box']) === 0 && count($cables['plan']) === 0)
                                        <p class="recheck__small">{{ __('audit.recheck.no_cable') }}</p>
                                    @endif
                                @else
                                    <p class="recheck__small">{{ collect([$line['fibre_b'], $line['tube_b'], $line['cable_b']])->filter()->implode(' · ') ?: '—' }}</p>
                                @endif
                            @endif
                        </div>
                    @endforeach
                @endunless
            </section>
        @endforeach
    @endif

    @if ($canEdit && ! $error)
        <div class="recheck__savebar">
            <button type="button" class="btn btn--primary" wire:click="save" @disabled(! $record->has_unsaved_changes)>💾 {{ __('audit.recheck.save') }}</button>
        </div>
    @endif

    @if ($manualTarget !== '')
        <div class="modal" role="dialog" aria-modal="true">
            <form class="modal__panel" wire:submit="saveManualCable">
                <label class="field__label" for="manual-cable">{{ __('audit.recheck.manual_question') }}</label>
                <input class="field__input" id="manual-cable" wire:model="manualCable" maxlength="160" autofocus>
                @error('manualCable') <p class="field__error">{{ $message }}</p> @enderror
                <div class="form-actions">
                    <button type="button" class="btn btn--ghost" wire:click="cancelManual">{{ __('audit.field.cancel') }}</button>
                    <button type="submit" class="btn btn--primary">{{ __('audit.recheck.apply') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
