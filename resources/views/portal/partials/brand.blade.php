@inject('company', 'App\Settings\CompanySettings')

<div class="portal-brand">
    @if ($company->logoUrl())
        <img class="portal-brand__logo" src="{{ $company->logoUrl() }}" alt="{{ $company->displayName() }}">
        <span class="portal-brand__times" aria-hidden="true">×</span>
    @endif
    @if ($client->logoUrl())
        <img class="portal-brand__client" src="{{ $client->logoUrl() }}" alt="{{ $client->name }}">
    @else
        <span class="portal-brand__client-name">{{ $client->name }}</span>
    @endif
</div>
