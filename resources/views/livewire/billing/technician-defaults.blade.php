<div class="billing__card">
    <p class="billing__card-title">{{ __('billing.settings.people_title') }}</p>
    <p class="billing__muted billing__settings-note">{{ __('billing.settings.people_hint') }}</p>
    <div class="billing__people">
        @foreach ($people as $person)
            <div class="billing__person" wire:key="person-{{ $person->id }}">
                <span>{{ $person->name }}</span>
                @if ($person->hasRole(\App\Enums\Role::Technician->value))
                    <select class="billing__input billing__input--auto" wire:model.live="defaults.{{ $person->id }}" aria-label="{{ __('billing.settings.default_client_for', ['name' => $person->name]) }}">
                        <option value="">{{ __('billing.settings.no_default') }}</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="billing__muted">· {{ $person->hasRole(\App\Enums\Role::Administrator->value) ? __('billing.settings.role_manager') : __('billing.settings.role_supervisor') }}</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
