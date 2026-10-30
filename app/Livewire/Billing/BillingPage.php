<?php

namespace App\Livewire\Billing;

use App\Billing\BillingAccess;
use App\Models\BillingEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * « Pointage / Facturation »: the person's space (technician, supervisor or administrator) with its sections.
 */
class BillingPage extends Component
{
    public const array TECHNICIAN_SECTIONS = ['entries', 'expenses'];

    /** Tabs of administrators and supervisors (« Mes frais » for administrators only). */
    public const array MANAGER_TABS = ['dashboard', 'entries', 'my-expenses', 'reviews'];

    /** Technicians choose what to do each time they open the page. */
    public string $section = '';

    public string $tab = 'entries';

    public function mount(BillingAccess $access): void
    {
        $this->authorize('create', BillingEntry::class);

        $this->tab = $this->tabs($access)[0] ?? 'entries';
    }

    public function choose(string $section): void
    {
        $this->section = in_array($section, self::TECHNICIAN_SECTIONS, true) ? $section : '';
    }

    public function showTab(string $tab, BillingAccess $access): void
    {
        $tabs = $this->tabs($access);
        $this->tab = in_array($tab, $tabs, true) ? $tab : $tabs[0];
    }

    public function render(BillingAccess $access): View
    {
        $user = $this->user();
        $role = match (true) {
            $access->isAdministrator($user) => 'administrator',
            $access->isSupervisor($user) => 'supervisor',
            default => 'technician',
        };

        return view('livewire.billing.billing-page', [
            'user' => $user,
            'role' => $role,
            'defaultClient' => $user->billingProfile?->defaultClient,
            'syncedAt' => CarbonImmutable::now(config('platform.timezone'))->format('H:i:s'),
            'tabs' => $role === 'technician' ? [] : $this->tabs($access),
        ])->layout('components.layouts.app', ['title' => __('billing.title'), 'page' => 'billing']);
    }

    /**
     * @return list<string>
     */
    private function tabs(BillingAccess $access): array
    {
        $user = $this->user();

        if ($access->isAdministrator($user)) {
            return self::MANAGER_TABS;
        }

        return $access->isSupervisor($user) ? ['dashboard', 'entries', 'reviews'] : [];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
