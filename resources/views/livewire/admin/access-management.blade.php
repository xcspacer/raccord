<div class="settings-page access-page">
    <div class="access-page__intro">
        <p class="page-hint">{{ __('access.hint') }}</p>
        <button type="button" class="btn btn--ghost btn--danger" wire:click="resetToDefaults" wire:confirm="{{ __('access.confirm_reset') }}">{{ __('access.reset') }}</button>
    </div>

    @foreach ($groups as $group => $rows)
        <section class="settings-card access-card" wire:key="group-{{ $group }}">
            <h2 class="settings-card__title">{{ __('navigation.groups.'.$group) }}</h2>

            <div class="access-row access-row--head" role="row">
                <span>{{ __('access.feature') }}</span>
                <span title="{{ __('access.admin_always') }}">{{ __('access.admin') }}</span>
                @foreach ($roles as $role)
                    <span title="{{ $role->label() }}">{{ __('access.roles.'.$role->value) }}</span>
                @endforeach
            </div>

            @foreach ($rows as $row)
                <div class="access-row" role="row" wire:key="feature-{{ $row['feature']->value }}">
                    <span class="access-row__feature">
                        <strong>{{ $row['feature']->label() }}</strong>
                        <small>{{ __('access.descriptions.'.$row['feature']->value) }}</small>
                    </span>
                    <span>
                        @if ($row['administrator'])
                            <span class="access-row__locked" title="{{ __('access.admin_always') }}">✓</span>
                        @else
                            <span class="access-row__na">—</span>
                        @endif
                    </span>

                    @foreach ($roles as $role)
                        <span>
                            @if ($state = $row['roles'][$role->value])
                                <label @class(['toggle-switch', 'is-changed' => $state['changed']]) wire:key="toggle-{{ $row['feature']->value }}-{{ $role->value }}-{{ $pendingConfirmation === null ? 'idle' : 'confirming' }}" @if ($state['changed']) title="{{ __('access.changed') }}" @endif>
                                    <input type="checkbox" @checked($state['enabled']) wire:click="toggle('{{ $row['feature']->value }}', '{{ $role->value }}')" aria-label="{{ $row['feature']->label() }} — {{ $role->label() }}">
                                    <span></span>
                                </label>
                            @else
                                <span class="access-row__na" title="{{ __('access.not_applicable') }}">—</span>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endforeach

    <p class="page-hint access-page__footer">{{ __('access.summary') }}</p>

    <x-password-confirmation :open="$pendingConfirmation !== null" id="confirm-password-access" />
</div>
