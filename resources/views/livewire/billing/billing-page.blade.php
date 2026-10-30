<div class="billing" wire:poll.5s.visible>
    <div class="billing__topbar">
        <div class="billing__who">
            <span class="billing__role-icon"><x-navigation.icon :name="match ($role) { 'administrator' => 'shield', 'supervisor' => 'eye', default => 'person' }" /></span>
            <div>
                <p class="billing__name">{{ $user->name }}</p>
                <p class="billing__meta">
                    {{ __('billing.spaces.'.$role) }}@if ($role === 'technician' && $defaultClient) · {{ __('billing.default_client', ['client' => $defaultClient->name]) }}@endif · {{ __('billing.synced', ['time' => $syncedAt]) }}
                </p>
            </div>
        </div>
    </div>

    @if ($role === 'technician' && $section === '')
        <div class="billing__home">
            <p class="billing__home-hello">{{ __('billing.home.hello', ['name' => $user->name]) }}</p>
            <div class="billing__home-cards">
                <button type="button" class="billing__home-card" wire:click="choose('entries')">
                    <span class="billing__home-icon"><x-navigation.icon name="clipboard" /></span>
                    <span>
                        <span class="billing__home-title">{{ __('billing.home.entries') }}</span>
                        <span class="billing__home-text">{{ __('billing.home.entries_text') }}</span>
                    </span>
                </button>
                <button type="button" class="billing__home-card" wire:click="choose('expenses')">
                    <span class="billing__home-icon"><x-navigation.icon name="receipt" /></span>
                    <span>
                        <span class="billing__home-title">{{ __('billing.home.expenses') }}</span>
                        <span class="billing__home-text">{{ __('billing.home.expenses_text') }}</span>
                    </span>
                </button>
            </div>
        </div>
    @else
        @if ($role === 'technician')
            <button type="button" class="billing__back" wire:click="choose('')">← {{ __('billing.home.back') }}</button>
        @else
            <div class="billing__tabs">
                @foreach ($tabs as $name)
                    <button type="button" @class(['billing__toggle-option', 'is-on' => $tab === $name]) wire:click="showTab('{{ $name }}')">{{ __('billing.tabs.'.$name) }}</button>
                @endforeach
            </div>
        @endif

        @php($current = $role === 'technician' ? $section : $tab)
        @if ($current === 'dashboard')
            <livewire:billing.dashboard :key="'dashboard'" />
        @elseif ($current === 'reviews')
            <livewire:billing.expense-reviews :key="'reviews'" />
        @elseif ($current === 'expenses' || $current === 'my-expenses')
            <livewire:billing.my-expenses :key="'my-expenses'" />
        @else
            <livewire:billing.my-entries :key="'my-entries'" />
        @endif
    @endif
</div>
