<div class="space">
    <div class="space__tabs" role="tablist">
        @foreach (['inventory' => 'inventory', 'vehicle' => 'vehicle', 'expense-reports' => 'expense_reports', 'timesheet' => 'timesheet', 'daily-return' => 'daily_return'] as $key => $label)
            @continue($key === 'expense-reports' && ! $canExpenseReports)
            @continue($key === 'inventory' && ! $canInventory)
            <button type="button" role="tab" @class(['btn', 'btn--ghost', 'is-active' => $tab === $key]) @disabled($blocked && $key !== 'vehicle') wire:click="setTab('{{ $key }}')">{{ __('technician_space.tabs.'.$label) }}</button>
        @endforeach
    </div>

    @if ($tab === 'timesheet')
        <section class="space__panel">
            <div class="field">
                <label class="field__label" for="space-month">{{ __('technician_space.timesheet.month') }}</label>
                <input class="field__input field__input--narrow" type="month" id="space-month" wire:model.live="month">
            </div>

            <p class="page-hint">{{ __('technician_space.timesheet.summary', ['month' => $monthLabel, 'worked' => $sheet['worked'], 'absent' => $sheet['absent'], 'leave' => $sheet['leave'], 'sick' => $sheet['sick']]) }}</p>

            @if ($sheet['rows'] === [])
                <div class="empty-state">{{ __('technician_space.timesheet.empty') }}</div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('technician_space.timesheet.date') }}</th>
                            <th>{{ __('technician_space.timesheet.state') }}</th>
                            <th>{{ __('technician_space.timesheet.task') }}</th>
                            <th>{{ __('technician_space.timesheet.detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sheet['rows'] as $row)
                            <tr wire:key="row-{{ $row['date']->toDateString() }}">
                                <td data-label="{{ __('technician_space.timesheet.date') }}">{{ $row['date']->translatedFormat('D d M') }}</td>
                                <td data-label="{{ __('technician_space.timesheet.state') }}">
                                    @if ($row['absence'])
                                        <span class="task-badge task-badge--{{ $row['absence']->value }}">{{ __('tasks.absence_badge.'.$row['absence']->value) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="{{ __('technician_space.timesheet.task') }}">{{ $row['label'] ?: '—' }}</td>
                                <td data-label="{{ __('technician_space.timesheet.detail') }}">{{ $row['detail'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @elseif ($tab === 'inventory')
        <section class="space__panel">
            <livewire:inventory.panel mode="mine" :user-id="null" key="panel-mine" />
            <livewire:inventory.item-sheet />
        </section>
    @elseif ($tab === 'expense-reports')
        <section class="space__panel">
            <div class="expenses__history">
                @forelse ($expenseReports as $report)
                    <article class="inspection__card" wire:key="report-{{ $report->id }}">
                        <h3 class="inspection__title">{{ mb_strtoupper(\Carbon\CarbonImmutable::createFromFormat('!Y-m', $report->month)->translatedFormat('F Y')) }}</h3>
                        <span>{{ __('expense_reports.history_line', ['date' => $report->created_at->setTimezone(config('platform.timezone'))->translatedFormat('d/m/Y'), 'count' => $report->lines_count, 'total' => \App\Support\Money::format($report->total_cents)]) }}</span>
                        <a class="btn btn--ghost" href="{{ route('expense-reports.download', $report) }}">⬇ {{ __('expense_reports.download') }}</a>
                    </article>
                @empty
                    <p class="task-empty">{{ __('expense_reports.history_empty') }}</p>
                @endforelse
            </div>
            <p class="expenses__hint">{{ __('expense_reports.history_hint') }}</p>
        </section>
    @elseif ($tab === 'vehicle')
        <section class="space__panel">
            <livewire:technician-space.vehicle-inspection />

            <form wire:submit="saveVehicle" class="settings-card" novalidate>
                <div class="field-row">
                    <div class="field">
                        <label class="field__label" for="vehicle-model">{{ __('technician_space.vehicle.model') }}</label>
                        <input class="field__input" id="vehicle-model" wire:model="vehicleModel" placeholder="{{ __('technician_space.vehicle.model_placeholder') }}" maxlength="80">
                        @error('vehicleModel') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field__label" for="vehicle-plate">{{ __('technician_space.vehicle.plate') }}</label>
                        <input class="field__input" id="vehicle-plate" wire:model="vehiclePlate" placeholder="{{ __('technician_space.vehicle.plate_placeholder') }}" maxlength="20" autocapitalize="characters">
                        @error('vehiclePlate') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">{{ __('technician_space.vehicle.save') }}</button>
                    <a class="btn btn--ghost" href="{{ route('vehicle-report') }}" target="_blank" rel="noopener">{{ __('technician_space.vehicle.report') }}</a>
                </div>
            </form>
        </section>
    @else
        <section class="space__panel space__panel--return">
            <div class="settings-card">
                <div class="field-row">
                    <div class="field">
                        <label class="field__label" for="return-date">{{ __('technician_space.return.date') }}</label>
                        <input class="field__input" type="date" id="return-date" wire:model.live="returnDate">
                    </div>
                    <div class="field">
                        <label class="field__label" for="return-team">{{ __('technician_space.return.team') }}</label>
                        <input class="field__input" id="return-team" wire:model.live.debounce.400ms="team" maxlength="200">
                    </div>
                </div>

                @foreach ($boxes as $index => $box)
                    <div class="box-row" wire:key="return-box-{{ $index }}">
                        <div class="field">
                            <label class="field__label" for="return-ref-{{ $index }}">{{ __('technician_space.return.box_n', ['n' => $index + 1]) }} — {{ __('technician_space.return.reference') }}</label>
                            <input class="field__input" id="return-ref-{{ $index }}" wire:model.live.debounce.400ms="boxes.{{ $index }}.reference" placeholder="{{ __('technician_space.return.reference_placeholder') }}">
                        </div>
                        <div class="field">
                            <label class="field__label" for="return-works-{{ $index }}">{{ __('technician_space.return.works') }}</label>
                            <textarea class="field__input" id="return-works-{{ $index }}" rows="3" wire:model.live.debounce.400ms="boxes.{{ $index }}.works"></textarea>
                            <p class="field__hint">{{ __('technician_space.return.works_hint') }}</p>
                        </div>
                        <div class="field">
                            <span class="field__label">{{ __('technician_space.return.cables') }}</span>
                            @foreach ($box['cables'] as $cableIndex => $cable)
                                <div class="repeat-row" wire:key="return-cable-{{ $index }}-{{ $cableIndex }}">
                                    <input class="field__input" wire:model.live.debounce.400ms="boxes.{{ $index }}.cables.{{ $cableIndex }}.description" placeholder="{{ __('technician_space.return.cable_placeholder') }}" aria-label="{{ __('technician_space.return.cable') }}">
                                    <input class="field__input field__input--narrow" type="number" min="0" wire:model.live.debounce.400ms="boxes.{{ $index }}.cables.{{ $cableIndex }}.tubes" placeholder="{{ __('technician_space.return.tubes') }}" aria-label="{{ __('technician_space.return.tubes') }}">
                                    @if (count($box['cables']) > 1)
                                        <button type="button" class="chip-delete" wire:click="removeCable({{ $index }}, {{ $cableIndex }})" aria-label="{{ __('technician_space.return.remove') }}">✕</button>
                                    @endif
                                </div>
                            @endforeach
                            <button type="button" class="btn btn--ghost" wire:click="addCable({{ $index }})">+ {{ __('technician_space.return.add_cable') }}</button>
                        </div>
                        <div class="field">
                            <label class="field__label" for="return-welds-{{ $index }}">{{ __('technician_space.return.welds') }}</label>
                            <input class="field__input field__input--narrow" type="number" min="0" id="return-welds-{{ $index }}" wire:model.live.debounce.400ms="boxes.{{ $index }}.welds" placeholder="{{ collect($box['cables'])->sum(fn ($cable) => (int) $cable['tubes']) }}">
                        </div>
                        @if (count($boxes) > 1)
                            <button type="button" class="btn btn--ghost btn--danger" wire:click="removeBox({{ $index }})">✕ {{ __('technician_space.return.remove_box', ['n' => $index + 1]) }}</button>
                        @endif
                    </div>
                @endforeach

                <button type="button" class="btn btn--ghost" wire:click="addBox">+ {{ __('technician_space.return.add_box') }}</button>
            </div>

            <div class="settings-card">
                <h2 class="settings-card__title">{{ __('technician_space.return.preview') }}</h2>
                <textarea class="field__input email-preview" id="return-email" rows="16" wire:model.live.debounce.600ms="emailText"></textarea>
                <div class="form-actions">
                    <button type="button" class="btn btn--ghost" wire:click="regenerate">{{ __('technician_space.return.regenerate') }}</button>
                    <button type="button" class="btn btn--primary" data-copy-target="return-email" data-copied="{{ __('technician_space.return.copied') }}" data-copy-failed="{{ __('technician_space.return.copy_failed') }}">{{ __('technician_space.return.copy') }}</button>
                </div>
            </div>
        </section>
    @endif
</div>
