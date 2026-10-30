<?php

namespace App\Livewire\Billing;

use App\Billing\BillingAccess;
use App\Billing\BillingJournal;
use App\Enums\Role;
use App\Models\BillingClient;
use App\Models\BillingProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * « Gérer les techniciens »: who uses billing (accounts and roles come from the users and « Gérer les accès ») and
 * the client each technician's form opens on.
 */
class TechnicianDefaults extends Component
{
    /** @var array<int, string> default client id by user id */
    public array $defaults = [];

    public function mount(): void
    {
        $this->guard();

        $this->defaults = BillingProfile::query()->get()->mapWithKeys(fn (BillingProfile $profile) => [$profile->user_id => (string) ($profile->default_billing_client_id ?? '')])->all();
    }

    /**
     * @param  mixed  $value
     */
    public function updatedDefaults($value, string $userId): void
    {
        $this->guard();

        $person = app(BillingAccess::class)->partnerCandidates($this->user())->push($this->user())->firstWhere('id', (int) $userId);
        abort_if($person === null || ! $person->hasRole(Role::Technician->value), 404);

        $client = $value === '' ? null : BillingClient::query()->where('is_active', true)->findOrFail((int) $value);
        $profile = BillingProfile::query()->updateOrCreate(['user_id' => $person->id], ['default_billing_client_id' => $client?->id]);
        app(BillingJournal::class)->record(BillingJournal::DEFAULT_CLIENT_SET, $profile, $this->user());
    }

    public function render(BillingAccess $access): View
    {
        return view('livewire.billing.technician-defaults', [
            'people' => $access->partnerCandidates($this->user())->push($this->user())->sortBy('name')->values(),
            'clients' => BillingClient::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    private function guard(): void
    {
        $this->authorize('manage', BillingProfile::class);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
