<div class="expenses" @if ($reading) wire:poll.3s @endif>
    @if ($report)
        <section class="settings-card expenses__done expenses__desktop">
            <h2 class="settings-card__title">✅ {{ __('expense_reports.done_title') }}</h2>
            <p class="expenses__hint">{{ __('expense_reports.done_hint') }}</p>
            <div class="form-actions form-actions--left">
                <a class="btn btn--primary" href="{{ route('expense-reports.download', $report) }}">⬇ {{ __('expense_reports.download') }}</a>
                @if ($email['recipient'])
                    <a class="btn btn--ghost" href="mailto:{{ $email['recipient'] }}?subject={{ rawurlencode($email['subject']) }}&body={{ rawurlencode($email['body']) }}">✉ {{ __('expense_reports.send') }}</a>
                @endif
                <button type="button" class="btn btn--ghost" data-share-file data-share-url="{{ route('expense-reports.download', $report) }}" data-share-title="{{ $email['subject'] }}" data-share-text="{{ $email['body'] }}">{{ __('expense_reports.share') }}</button>
            </div>
            @if ($email['recipient'])
                <div class="field">
                    <label class="field__label" for="expense-recipient">{{ __('expense_reports.recipient') }}</label>
                    <div class="expenses__copy">
                        <input class="field__input" id="expense-recipient" value="{{ $email['recipient'] }}" readonly>
                        <button type="button" class="btn btn--ghost" data-copy-target="expense-recipient" data-copied="{{ __('expense_reports.copied') }}" data-copy-failed="{{ __('expense_reports.copy_failed') }}">{{ __('expense_reports.copy') }}</button>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- Desktop layout (wider than 640 px), as on the previous platform. --}}
    <div class="expenses__desktop">
        <div class="expenses__head">
            <div class="field">
                <label class="field__label" for="expense-name">{{ __('expense_reports.name') }}</label>
                <input class="field__input" id="expense-name" wire:model.blur="name" maxlength="120">
                @error('name') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label class="field__label" for="expense-month">{{ __('expense_reports.month') }}</label>
                <input class="field__input" type="month" id="expense-month" wire:model.change="month">
                @error('month') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="expenses__actions">
            <label class="file-picker">
                <input class="file-picker__input" type="file" wire:model="photos" accept="image/jpeg,image/png,image/webp" capture="environment">
                <span class="btn btn--primary">📷 {{ __('expense_reports.camera') }}</span>
            </label>
            <label class="file-picker">
                <input class="file-picker__input" type="file" wire:model="photos" accept="image/jpeg,image/png,image/webp" multiple>
                <span class="btn btn--ghost">🖼 {{ __('expense_reports.files') }}</span>
            </label>
            <button type="button" class="btn btn--ghost" wire:click="addLine">＋ {{ __('expense_reports.manual_line') }}</button>
            <button type="button" class="btn btn--primary" wire:click="generate(false, 'download')" wire:loading.attr="disabled" wire:target="generate">⬇ {{ __('expense_reports.download') }}</button>
            <button type="button" class="btn btn--primary" wire:click="generate(false, 'mail')" wire:loading.attr="disabled" wire:target="generate">✉ {{ __('expense_reports.send') }}</button>
            <button type="button" class="btn btn--ghost btn--danger" wire:click="clear" @if ($lines->isNotEmpty()) wire:confirm="{{ __('expense_reports.confirm_clear') }}" @endif>{{ __('expense_reports.clear') }}</button>
        </div>
        <label class="expenses__check">
            <input type="checkbox" wire:model.change="autocrop">
            <span>{{ __('expense_reports.autocrop') }}</span>
        </label>
        <p class="expenses__hint">{{ __('expense_reports.hint') }}</p>
    </div>

    {{-- Phone layout: the person and the current month, big buttons and the steps to follow. --}}
    <div class="expenses__phone">
        <div class="expenses__title">
            <div class="expenses__title-main">{{ __('expense_reports.phone_title', ['month' => $phoneMonth]) }}</div>
            <div class="expenses__title-sub">{{ auth()->user()->name }}</div>
        </div>
        <div class="expenses__tiles">
            <label class="file-picker expenses__tile expenses__tile--primary">
                <input class="file-picker__input" type="file" wire:model="photos" accept="image/jpeg,image/png,image/webp" capture="environment">
                <span class="expenses__tile-icon">📷</span><span>{{ __('expense_reports.camera') }}</span>
            </label>
            <label class="file-picker expenses__tile">
                <input class="file-picker__input" type="file" wire:model="photos" accept="image/jpeg,image/png,image/webp" multiple>
                <span class="expenses__tile-icon">🖼</span><span>{{ __('expense_reports.files') }}</span>
            </label>
        </div>
        <label class="expenses__check expenses__check--phone">
            <input type="checkbox" wire:model.change="autocrop">
            <span>{{ __('expense_reports.autocrop_phone') }}</span>
        </label>
        @if ($lines->isEmpty())
            <ol class="expenses__steps">
                <li>{{ __('expense_reports.step_1') }}</li>
                <li>{{ __('expense_reports.step_2') }}</li>
                <li>{{ __('expense_reports.step_3') }}</li>
            </ol>
        @endif
    </div>

    <x-ai-notice />
    @error('photos') <p class="field__error">{{ $message }}</p> @enderror
    @error('photos.*') <p class="field__error">{{ $message }}</p> @enderror

    @if ($lines->isEmpty())
        <div class="empty-state">{{ __('expense_reports.empty') }}</div>
    @endif

    <div class="expenses__lines">
        @foreach ($lines as $line)
            <article class="expenses__line" wire:key="line-{{ $line->id }}">
                @if ($line->stored_file_id)
                    <a href="{{ route('vehicle-images.show', ['type' => 'expense', 'id' => $line->id, 'variant' => 'full']) }}" target="_blank" rel="noopener">
                        <img class="expenses__thumb" src="{{ route('vehicle-images.show', ['type' => 'expense', 'id' => $line->id, 'variant' => 'thumb']) }}" alt="{{ __('expense_reports.ticket_alt') }}" loading="lazy">
                    </a>
                @endif
                <div class="expenses__fields">
                    <div class="field">
                        <label class="field__label" for="line-date-{{ $line->id }}">{{ __('expense_reports.date') }}</label>
                        <input class="field__input" type="date" id="line-date-{{ $line->id }}" wire:model.change="edits.{{ $line->id }}.date">
                    </div>
                    <div class="field">
                        <label class="field__label" for="line-nature-{{ $line->id }}">{{ __('expense_reports.nature') }}</label>
                        <select class="field__input" id="line-nature-{{ $line->id }}" wire:model.change="edits.{{ $line->id }}.nature">
                            @foreach ($natures as $nature)
                                <option value="{{ $nature->value }}">{{ $nature->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="line-comment-{{ $line->id }}">{{ __('expense_reports.comment') }}</label>
                        <input class="field__input" id="line-comment-{{ $line->id }}" wire:model.blur="edits.{{ $line->id }}.comment" maxlength="255">
                    </div>
                    <div class="field">
                        <label class="field__label" for="line-amount-{{ $line->id }}">{{ __('expense_reports.amount') }}</label>
                        <input class="field__input field__input--narrow" inputmode="decimal" id="line-amount-{{ $line->id }}" wire:model.blur="edits.{{ $line->id }}.amount" placeholder="0,00">
                        @error('edits.'.$line->id.'.amount') <p class="field__error">{{ __('expense_reports.errors.amount') }}</p> @enderror
                    </div>
                </div>
                <div class="expenses__meta">
                    @if ($line->status !== \App\Enums\ExpenseLineStatus::Manual || $line->message)
                        <span @class(['equipment__status', 'equipment__status--'.$line->status->value])>{{ $line->status->label() }}@if ($line->merchant) · {{ $line->merchant }}@endif</span>
                    @endif
                    @if ($line->message)
                        <span class="equipment__status equipment__status--manual">{{ __('expense_reports.errors.'.$line->message) }}</span>
                    @endif
                    @isset($duplicates[$line->id])
                        <span class="equipment__status equipment__status--check">⚠ {{ __('expense_reports.duplicate.'.$duplicates[$line->id]) }}</span>
                        <button type="button" class="btn btn--ghost" wire:click="confirmNotDuplicate({{ $line->id }})">{{ __('expense_reports.not_duplicate') }}</button>
                    @endisset
                    @if ($line->stored_file_id)
                        <button type="button" class="btn btn--ghost" wire:click="reread({{ $line->id }})" title="{{ __('expense_reports.reread') }}">↻ {{ __('expense_reports.reread') }}</button>
                    @endif
                    <button type="button" class="chip-delete" wire:click="removeLine({{ $line->id }})" aria-label="{{ __('expense_reports.remove') }}">✕</button>
                </div>
            </article>
        @endforeach
    </div>

    <section @class(['settings-card', 'expenses__totals', 'expenses__totals--empty' => $lines->isEmpty()])>
        <div class="expenses__total-line expenses__total-line--main">
            <span>{{ __('expense_reports.total') }}</span>
            <strong>{{ \App\Support\Money::format($totals['total']) }}</strong>
        </div>
        @if ($totals['total'] > 0)
            @foreach ($totals['recap'] as $group => $cents)
                @if ($cents > 0)
                    <div class="expenses__total-line"><span>{{ __('expense_reports.recap.'.$group) }}</span><span>{{ \App\Support\Money::format($cents) }}</span></div>
                @endif
            @endforeach
        @else
            <div class="expenses__total-line"><span>{{ __('expense_reports.no_expense') }}</span><span>-</span></div>
        @endif
    </section>

    @foreach ($warnings as $warning)
        <div class="field-warning" role="alert">⚠ {{ $warning }}</div>
    @endforeach
    @error('generate') <p class="field__error">{{ $message }}</p> @enderror

    @if ($warnings !== [])
        <div class="form-actions form-actions--left">
            <button type="button" class="btn btn--primary" wire:click="generate(true, '{{ $intent }}')" wire:loading.attr="disabled" wire:target="generate">{{ __('expense_reports.generate_anyway') }}</button>
        </div>
    @endif

    @if ($lines->isNotEmpty())
        <div class="expenses__phone expenses__tiles">
            <button type="button" class="expenses__tile expenses__tile--wide" wire:click="addLine"><span class="expenses__tile-icon">＋</span><span>{{ __('expense_reports.manual_line') }}</span></button>
            <button type="button" class="expenses__clear-link" wire:click="clear" wire:confirm="{{ __('expense_reports.confirm_clear') }}">{{ __('expense_reports.clear') }}</button>
            @if ($report === null && $warnings === [])
                <button type="button" class="expenses__tile expenses__tile--primary expenses__tile--wide" wire:click="generate(false, 'phone')" wire:loading.attr="disabled" wire:target="generate"><span class="expenses__tile-icon">✅</span><span>{{ __('expense_reports.generate') }}</span></button>
            @endif
        </div>
    @endif

    @if ($report)
        <div class="expenses__phone expenses__tiles">
            <p class="expenses__ready">{{ __('expense_reports.ready_hint') }}</p>
            <a class="expenses__tile expenses__tile--primary" href="{{ route('expense-reports.download', $report) }}"><span class="expenses__tile-icon">⬇</span><span>{{ __('expense_reports.download') }}</span></a>
            @if ($email['recipient'])
                <a class="expenses__tile expenses__tile--outline" href="mailto:{{ $email['recipient'] }}?subject={{ rawurlencode($email['subject']) }}&body={{ rawurlencode($email['body']) }}"><span class="expenses__tile-icon">✉</span><span>{{ __('expense_reports.send') }}</span></a>
            @else
                <button type="button" class="expenses__tile expenses__tile--outline" data-share-file data-share-url="{{ route('expense-reports.download', $report) }}" data-share-title="{{ $email['subject'] }}" data-share-text="{{ $email['body'] }}"><span class="expenses__tile-icon">✉</span><span>{{ __('expense_reports.send') }}</span></button>
            @endif
        </div>
    @endif
</div>
