<div class="drawer">
    <div class="recheck__bar">
        <button type="button" class="btn btn--ghost" wire:click="leave" @if ($canEdit && $record->has_unsaved_changes) wire:confirm="{{ __('audit.recheck.unsaved') }}" @endif>← {{ __('audit.recheck.back') }}</button>
        <span class="recheck__title">🗄 {{ __('audit.drawer.title') }} · {{ $cabinet }}</span>
    </div>
    <p class="recheck__plan">{{ __('audit.recheck.plan_file') }} : <b>{{ $record->recnok_name ?: $planName }}</b></p>
    @error('plan') <p class="field__error">{{ $message }}</p> @enderror

    @if ($planError)
        <p class="recheck__empty">{{ $planError }}</p>
    @elseif ($guided && $canEdit)
        @php($total = count($order))
        @php($done = $counts['conform'] + $counts['modified'])
        @php($percent = $counts['total'] > 0 ? (int) round(100 * $done / $counts['total']) : 100)
        <div class="drawer__top">
            <div class="recheck__count"><span class="recheck__tally is-splice">{{ $done }}</span> / {{ $counts['total'] }} · {{ $percent }}%</div>
            <button type="button" class="drawer__button" wire:click="overview">☰ {{ __('audit.drawer.overview') }}</button>
        </div>
        <div class="drawer__progress"><i class="drawer__progress-{{ (int) (round($percent / 5) * 5) }}"></i></div>

        @if ($guideIndex >= $total)
            <div class="drawer__done">
                <div class="drawer__big">✓ {{ __('audit.drawer.guide_done') }}</div>
                <p class="recheck__small">{{ __('audit.drawer.guide_summary', ['ok' => $counts['conform'], 'modified' => $counts['modified']]) }}</p>
                <button type="button" class="drawer__button" wire:click="overview">☰ {{ __('audit.drawer.overview') }}</button>
            </div>
        @else
            @php($pbo = $order[$guideIndex])
            @php($mine = $now[$pbo])
            @php($number = $mine[0]['t'])
            @php($todoInDrawer = collect($order)->filter(fn ($name) => ($statuses[$name] ?? '') === 'unchecked' && ! \App\Audit\Drawer\DrawerRecheck::isUnnamed($name) && ($now[$name][0]['t'] ?? null) === $number)->count())
            <div class="drawer__guide">
                <div class="recheck__small">{{ __('audit.drawer.guide_progress', ['t' => $number, 'i' => $guideIndex + 1, 'n' => $total]) }}</div>
                @php($unnamed = \App\Audit\Drawer\DrawerRecheck::isUnnamed($pbo))
                <div class="drawer__big">{{ $pbo }}</div>
                @if ($unnamed)
                    <p class="drawer__unnamed">{{ __('audit.drawer.errors.unnamed') }}</p>
                @else
                    <p class="recheck__small">{{ __('audit.drawer.guide_question') }}</p>
                @endif
                @foreach ($mine as $segment)
                    <div class="drawer__guide-segment" wire:key="guide-{{ $loop->index }}">
                        <div class="drawer__code">{{ \App\Audit\Drawer\DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']) }}</div>
                        @php($module = collect($drawers)->firstWhere('n', $segment['t']))
                        <div class="drawer__mini drawer__grid-{{ $module['npos'] }}">
                            @for ($p = 1; $p <= $module['npos']; $p++)
                                <i @class(['is-on' => $p >= $segment['a'] && $p <= $segment['b'], 'is-other' => ! ($p >= $segment['a'] && $p <= $segment['b']) && ($currentCells[\App\Audit\Drawer\DrawerPlan::key($segment['t'], $segment['L'], $p)]['pb'] ?? '') !== '', 'is-separator' => $p % 12 === 0 && $p < $module['npos']])></i>
                            @endfor
                        </div>
                        <div class="drawer__info"><b>{{ \App\Audit\Drawer\DrawerPlan::shortCable($segment['cable']) }}</b><span>T{{ \App\Audit\Drawer\DrawerPlan::pad($segment['tube']) }} · {{ \App\Audit\Drawer\DrawerPlan::tubeName($segment['tube']) }} · F{{ \App\Audit\Drawer\DrawerPlan::pad($segment['f1']) }}{{ $segment['f2'] > $segment['f1'] ? '–F'.\App\Audit\Drawer\DrawerPlan::pad($segment['f2']) : '' }}</span></div>
                    </div>
                @endforeach
                <div class="drawer__actions">
                    @unless ($unnamed)
                        <button type="button" class="drawer__guide-button is-ok" wire:click="guideConfirm">✓ {{ __('audit.drawer.guide_ok') }}</button>
                    @endunless
                    <button type="button" class="drawer__guide-button is-fix" wire:click="openEdit(@js($pbo))">✎ {{ __('audit.drawer.guide_fix') }}</button>
                </div>
                <div class="drawer__actions">
                    <button type="button" class="drawer__button" wire:click="guidePrevious" @disabled($guideIndex === 0)>◀ {{ __('audit.drawer.previous') }}</button>
                    <button type="button" class="drawer__button" wire:click="guideSkip">{{ __('audit.drawer.skip') }} ▶</button>
                </div>
                @if ($todoInDrawer > 1)
                    <button type="button" class="drawer__button is-ok drawer__wide" wire:click="guideConfirmDrawer" wire:confirm="{{ __('audit.drawer.confirm_all_question', ['n' => $todoInDrawer]) }}">✓✓ {{ __('audit.drawer.guide_drawer', ['t' => $number]) }} ({{ $todoInDrawer }})</button>
                @endif
            </div>
        @endif
    @else
        <div class="drawer__top">
            <div class="recheck__count"><span class="recheck__tally is-splice">{{ $counts['conform'] }}</span> / {{ $counts['total'] }} {{ __('audit.drawer.confirmed') }} · <span class="recheck__tally is-waiting">{{ $counts['modified'] }}</span> {{ mb_strtolower(__('audit.drawer.status.modified')) }}</div>
            @if ($canEdit && $counts['unchecked'] > 0)
                <button type="button" class="drawer__button is-ok" wire:click="startGuide">▶ {{ __('audit.drawer.guide') }}</button>
            @endif
            @if ($canEdit && $counts['confirmable'] > 0)
                <button type="button" class="drawer__button" wire:click="confirmAll" wire:confirm="{{ __('audit.drawer.confirm_all_question', ['n' => $counts['confirmable']]) }}">✓ {{ __('audit.drawer.confirm_all') }} ({{ $counts['confirmable'] }})</button>
            @endif
        </div>
        <div class="recheck__filters">
            @foreach ($drawers as $drawer)
                <button type="button" @class(['recheck__chip', 'is-active' => $drawer['n'] === $current['n']]) wire:click="setTab({{ $drawer['n'] }})" wire:key="tab-{{ $drawer['n'] }}">{{ __('audit.drawer.drawer') }} {{ $drawer['n'] }}</button>
            @endforeach
        </div>
        <p class="recheck__small">{{ __('audit.drawer.legend') }}</p>

        <div class="drawer__mockup">
            <div class="drawer__grid drawer__grid-{{ $current['npos'] }} drawer__grid--wide">
                <span></span>
                @for ($p = 1; $p <= $current['npos']; $p++) <span class="drawer__position">{{ $p }}</span> @endfor
                @foreach ($current['mods'] as $module)
                    <span class="drawer__module">{{ $module['L'] }}</span>
                    @foreach (collect($segments)->where('L', $module['L']) as $segment)
                        @php($length = $segment['b'] - $segment['a'] + 1)
                        @php($bar = $segment['tube'] > 0 ? 'drawer__bar-'.\App\Audit\Drawer\DrawerPlan::colourOfTube($segment['tube']) : '')
                        @if ($segment['pb'] !== '')
                            <button type="button" @class(['drawer__cell', 'drawer-pbo-'.((array_search($segment['pb'], $names, true) % 14) + 1), 'is-changed' => $changed($segment), $bar => $bar !== '', 'drawer__span-'.$length => true]) title="{{ $segment['pb'] }} · {{ \App\Audit\Drawer\DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']) }}" wire:click="$set('search', @js($segment['pb']))">{{ $length > 1 ? \App\Audit\Drawer\DrawerPlan::shortName($segment['pb']) : '' }}</button>
                        @elseif ($canEdit)
                            <button type="button" @class(['drawer__cell', 'is-free', $bar => $bar !== '', 'drawer__span-'.$length => true]) title="{{ __('audit.drawer.free') }} {{ \App\Audit\Drawer\DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']) }}" wire:click="openFree({{ $segment['t'] }}, '{{ $segment['L'] }}', {{ $segment['a'] }}, {{ $segment['b'] }})"></button>
                        @else
                            <span @class(['drawer__cell', 'is-free', 'drawer__span-'.$length => true])></span>
                        @endif
                    @endforeach
                @endforeach
            </div>
        </div>

        <input class="field__input" type="search" wire:model.live.debounce.200ms="search" placeholder="{{ __('audit.drawer.search') }}" aria-label="{{ __('audit.drawer.search') }}">

        @forelse ($visibleNames as $name)
            @php($status = $statuses[$name] ?? 'unchecked')
            @php($mine = collect($now[$name] ?? [])->where('t', $current['n']))
            @php($others = collect($now[$name] ?? [])->where('t', '!=', $current['n']))
            @php($unnamed = \App\Audit\Drawer\DrawerRecheck::isUnnamed($name))
            <div @class(['drawer__card', 'is-'.$status, 'is-unnamed' => $unnamed]) wire:key="card-{{ $name }}">
                <div class="drawer__card-head"><b>{{ $name }}</b><span @class(['drawer__badge', 'is-'.$status])>{{ __('audit.drawer.badge.'.$status) }}</span></div>
                @if ($unnamed)
                    <p class="drawer__unnamed">{{ __('audit.drawer.errors.unnamed') }}</p>
                @endif
                @foreach ($mine as $segment)
                    <div class="drawer__line"><b>{{ \App\Audit\Drawer\DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']) }}</b><span>{{ \App\Audit\Drawer\DrawerPlan::shortCable($segment['cable']) }} · T{{ \App\Audit\Drawer\DrawerPlan::pad($segment['tube']) }} {{ \App\Audit\Drawer\DrawerPlan::tubeName($segment['tube']) }} · F{{ \App\Audit\Drawer\DrawerPlan::pad($segment['f1']) }}{{ $segment['f2'] > $segment['f1'] ? '–F'.\App\Audit\Drawer\DrawerPlan::pad($segment['f2']) : '' }}</span></div>
                @endforeach
                @if ($others->isNotEmpty())
                    <div class="drawer__line is-dim">+ {{ $others->map(fn ($s) => \App\Audit\Drawer\DrawerPlan::code($s['t'], $s['L'], $s['a'], $s['b']))->implode(' · ') }}</div>
                @endif
                @if ($canEdit)
                    <div class="drawer__actions">
                        @if ($status === 'unchecked' && ! $unnamed) <button type="button" class="drawer__button is-ok" wire:click="confirm(@js($name))">✓ {{ __('audit.drawer.conform') }}</button> @endif
                        <button type="button" class="drawer__button" wire:click="openEdit(@js($name))">✎ {{ __('audit.drawer.edit') }}</button>
                        @if ($status === 'modified') <button type="button" class="drawer__button" wire:click="revert(@js($name))" wire:confirm="{{ __('audit.drawer.revert_question') }}">↺ {{ __('audit.drawer.revert') }}</button> @endif
                    </div>
                @endif
            </div>
        @empty
            <p class="recheck__empty">{{ __('audit.drawer.nothing') }}</p>
        @endforelse

        @php($free = collect($segments)->where('pb', ''))
        @if ($free->isNotEmpty())
            <div class="drawer__card">
                <div class="drawer__card-head"><b>{{ __('audit.drawer.free') }}</b><span class="drawer__badge is-unchecked">{{ $free->count() }}</span></div>
                @foreach ($free as $segment)
                    <div class="drawer__line"><b>{{ \App\Audit\Drawer\DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']) }}</b>
                        @if ($canEdit) <button type="button" class="drawer__button" wire:click="openFree({{ $segment['t'] }}, '{{ $segment['L'] }}', {{ $segment['a'] }}, {{ $segment['b'] }})">＋ {{ __('audit.drawer.assign') }}</button> @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($canEdit)
            <button type="button" class="drawer__button drawer__wide" wire:click="openEdit('')">＋ {{ __('audit.drawer.add') }}</button>
        @endif
    @endif

    @if ($canEdit && ! $planError)
        <div class="recheck__savebar">
            <button type="button" class="btn btn--primary" wire:click="save" @disabled(! $record->has_unsaved_changes)>💾 {{ __('audit.recheck.save') }}</button>
        </div>
    @endif

    @if ($edit !== null)
        <div class="drawer__sheet" role="dialog" aria-modal="true">
            <div class="drawer__sheet-box">
                <h3>{{ $edit['orig'] !== '' ? '✎ '.$edit['orig'] : '＋ '.__('audit.drawer.add') }}</h3>
                <div class="recheck__label">{{ __('audit.drawer.pbo') }}</div>
                <select class="recheck__select" wire:change="editName($event.target.value)">
                    <option value="">{{ __('audit.drawer.pbo_pick') }}</option>
                    @foreach ($pbos as $name) <option value="{{ $name }}" @selected($name === $edit['name'] && ! $edit['manual'])>{{ $name }}</option> @endforeach
                    @if ($edit['name'] !== '' && ! $edit['manual'] && ! in_array($edit['name'], $pbos, true)) <option value="{{ $edit['name'] }}" selected>{{ $edit['name'] }}</option> @endif
                    <option value="__manual" @selected($edit['manual'])>{{ __('audit.drawer.pbo_manual') }}</option>
                </select>
                @if ($edit['manual'])
                    <input class="field__input" maxlength="60" value="{{ $edit['name'] }}" placeholder="{{ __('audit.drawer.pbo_question') }}" wire:change="editManualName($event.target.value)">
                @endif

                @foreach ($edit['segs'] as $i => $segment)
                    @php($found = collect($drawers)->firstWhere('n', $segment['t']) ?? $drawers[0])
                    <div class="drawer__segment" wire:key="segment-{{ $i }}">
                        <div class="drawer__code">{{ \App\Audit\Drawer\DrawerPlan::code($segment['t'], $segment['L'], $segment['a'], $segment['b']) }}
                            @if (count($edit['segs']) > 1) <button type="button" class="drawer__remove" wire:click="removeSegment({{ $i }})" aria-label="{{ __('audit.drawer.segment_remove') }}">✕</button> @endif
                        </div>
                        <div class="drawer__fields">
                            <label>{{ __('audit.drawer.drawer') }}<select wire:change="editField('t', {{ $i }}, $event.target.value)">@foreach ($drawers as $d) <option value="{{ $d['n'] }}" @selected($d['n'] === $segment['t'])>{{ $d['n'] }}</option> @endforeach</select></label>
                            <label>{{ __('audit.drawer.module') }}<select wire:change="editField('L', {{ $i }}, $event.target.value)">@foreach ($found['mods'] as $m) <option @selected($m['L'] === $segment['L'])>{{ $m['L'] }}</option> @endforeach</select></label>
                            <label>{{ __('audit.drawer.from') }}<select wire:change="editField('a', {{ $i }}, $event.target.value)">@for ($n = 1; $n <= $found['npos']; $n++) <option value="{{ $n }}" @selected($n === $segment['a'])>{{ \App\Audit\Drawer\DrawerPlan::pad($n) }}</option> @endfor</select></label>
                            <label>{{ __('audit.drawer.to') }}<select wire:change="editField('b', {{ $i }}, $event.target.value)">@for ($n = 1; $n <= $found['npos']; $n++) <option value="{{ $n }}" @selected($n === $segment['b'])>{{ \App\Audit\Drawer\DrawerPlan::pad($n) }}</option> @endfor</select></label>
                        </div>
                        <div class="recheck__label">{{ __('audit.drawer.cable') }}</div>
                        <select class="recheck__select" wire:change="editField('cable', {{ $i }}, $event.target.value)">
                            <option value="">—</option>
                            @foreach ($cables as $label) <option value="{{ $label }}" title="{{ $label }}" @selected($label === $segment['cable'])>{{ \App\Audit\Drawer\DrawerPlan::shortCable($label) }}</option> @endforeach
                            <option value="__new">＋ {{ __('audit.drawer.cable_new') }}</option>
                        </select>
                        @if (($edit['newCable']['i'] ?? -1) === $i)
                            <div class="drawer__new-cable">
                                <label>{{ __('audit.drawer.capacity') }}<select wire:change="setNewCable('cap', $event.target.value)">@foreach ($capacities as $capacity) <option @selected($capacity === $edit['newCable']['cap'])>{{ $capacity }}</option> @endforeach</select></label>
                                <label>{{ __('audit.drawer.destination') }}<input list="drawer-destinations" maxlength="60" value="{{ $edit['newCable']['dest'] }}" wire:change="setNewCable('dest', $event.target.value)"></label>
                                <datalist id="drawer-destinations">@foreach ($destinations as $destination) <option value="{{ $destination }}"></option> @endforeach</datalist>
                                <button type="button" class="drawer__button is-ok" wire:click="createCable" @disabled($template === null || $edit['newCable']['dest'] === '')>{{ __('audit.drawer.cable_create') }}</button>
                            </div>
                        @endif
                        @for ($slot = \App\Audit\Drawer\DrawerPlan::slotOf($segment['a']); $slot <= \App\Audit\Drawer\DrawerPlan::slotOf($segment['b']); $slot++)
                            @php($tube = (int) ($segment['tubes'][$slot] ?? 0))
                            @php($start = max($segment['a'], $slot * 12 + 1))
                            @php($end = min($segment['b'], $slot * 12 + 12))
                            @php($first = (int) ($segment['fibres'][$slot] ?? \App\Audit\Drawer\DrawerPlan::fibreOf($start)))
                            @php($originalCell = $original[\App\Audit\Drawer\DrawerPlan::key($segment['t'], $segment['L'], $start)] ?? null)
                            <div class="recheck__label">{{ __('audit.drawer.tube_for', ['a' => \App\Audit\Drawer\DrawerPlan::pad($start), 'b' => \App\Audit\Drawer\DrawerPlan::pad($end)]) }}</div>
                            <div class="recheck__palette">
                                @foreach ($palette as $position => $colour)
                                    <button type="button" @class(['recheck__swatch', 'recheck-colour-'.($position + 1) => true, 'is-selected' => $tube > 0 && \App\Audit\Drawer\DrawerPlan::colourOfTube($tube) === $position + 1]) wire:click="pickTube({{ $i }}, {{ $slot }}, {{ $position + 1 }})" title="{{ __('enums.audit_recheck_colours.'.$colour['key']) }}">{{ $position + 1 }}</button>
                                @endforeach
                            </div>
                            <div class="drawer__rings">
                                <label>{{ __('audit.drawer.rings') }}<select wire:change="setRings({{ $i }}, {{ $slot }}, $event.target.value)">@for ($r = 0; $r <= 9; $r++) <option value="{{ $r }}" @selected(($tube > 0 ? \App\Audit\Drawer\DrawerPlan::ringsOfTube($tube) : (int) ($segment['rings'][$slot] ?? 0)) === $r)>{{ $r }}</option> @endfor</select></label>
                                <span>@if ($tube > 0) → <b>T{{ \App\Audit\Drawer\DrawerPlan::pad($tube) }}</b> {{ \App\Audit\Drawer\DrawerPlan::tubeName($tube) }} · F{{ \App\Audit\Drawer\DrawerPlan::pad($first) }}{{ $end > $start ? '–F'.\App\Audit\Drawer\DrawerPlan::pad($first + $end - $start) : '' }} @else <i>{{ __('audit.drawer.errors.tube') }}</i> @endif
                                    @if ($originalCell && $originalCell['tube'] > 0 && $originalCell['tube'] !== $tube) <em class="drawer__diff">{{ __('audit.drawer.differs') }} (T{{ \App\Audit\Drawer\DrawerPlan::pad($originalCell['tube']) }})</em> @endif</span>
                            </div>
                            <div class="recheck__label">{{ __('audit.drawer.fibres') }}</div>
                            <div class="drawer__fibres">
                                <label class="drawer__fibre">{{ __('audit.drawer.from') }}<select wire:change="setFibre({{ $i }}, {{ $slot }}, $event.target.value)">@for ($n = 1; $n <= max($first, 24 - ($end - $start)); $n++) <option value="{{ $n }}" @selected($n === $first)>{{ \App\Audit\Drawer\DrawerPlan::pad($n) }}</option> @endfor</select></label>
                                <label class="drawer__fibre">{{ __('audit.drawer.to') }}<select wire:change="setFibreEnd({{ $i }}, {{ $slot }}, $event.target.value)">@for ($n = 1; $n <= max($first + $end - $start, 24); $n++) <option value="{{ $n }}" @selected($n === $first + $end - $start)>{{ \App\Audit\Drawer\DrawerPlan::pad($n) }}</option> @endfor</select></label>
                            </div>
                            @if ($originalCell && $originalCell['fibre'] > 0 && $originalCell['fibre'] !== $first) <em class="drawer__diff">{{ __('audit.drawer.differs') }} (F{{ \App\Audit\Drawer\DrawerPlan::pad($originalCell['fibre']) }})</em> @endif
                        @endfor
                    </div>
                @endforeach
                <button type="button" class="drawer__button" wire:click="addSegment">＋ {{ __('audit.drawer.segment_add') }}</button>

                @if ($error !== '') <p class="field__error">{{ $error }}</p> @endif
                @if ($clash !== [])
                    <p class="field__error">{{ __('audit.drawer.clash', ['list' => implode(', ', $clash)]) }}</p>
                    <div class="form-actions">
                        <button type="button" class="btn btn--ghost" wire:click="cancelEdit">{{ __('audit.field.cancel') }}</button>
                        <button type="button" class="btn btn--primary" wire:click="applyEdit(true)">{{ __('audit.drawer.replace') }}</button>
                    </div>
                @else
                    <div class="form-actions">
                        <button type="button" class="btn btn--ghost" wire:click="cancelEdit">{{ __('audit.field.cancel') }}</button>
                        <button type="button" class="btn btn--primary" wire:click="applyEdit">✓ {{ __('audit.drawer.apply') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
