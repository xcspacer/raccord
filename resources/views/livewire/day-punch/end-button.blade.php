<span class="day-punch-bar" @if ($running) data-day-punch data-timezone="{{ config('platform.timezone') }}" data-labels="{{ json_encode(__('day_punch.js'), JSON_UNESCAPED_UNICODE) }}" data-endpoint="{{ url('/day-punch') }}" @endif>
    @if ($running)
        <button type="button" class="btn btn--ghost btn--end-day" data-punch-end data-started="{{ $running->startLocal() }}" title="{{ __('day_punch.end.started_at', ['time' => $running->startLocal()]) }}">■ {{ __('day_punch.end.button') }}</button>
    @endif

    @if ($previous)
        <button type="button" class="btn btn--ghost btn--danger" wire:click="openDeclaration('{{ $previous->date->toDateString() }}')">⚠ {{ __('day_punch.end.declare_reminder', ['date' => $previous->date->translatedFormat('d M')]) }}</button>
    @endif
</span>
