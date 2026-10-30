<?php

namespace App\Livewire\Admin;

use App\Enums\Feature;
use App\Enums\Role;
use App\Livewire\Concerns\ConfirmsPassword;
use App\Models\RoleFeatureOverride;
use App\Support\FeatureAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * "Gérer les accès": switches each feature on or off per role. Stored as overrides of the catalogue
 * defaults; an override equal to the default is removed.
 */
class AccessManagement extends Component
{
    use ConfirmsPassword;

    /** @var list<Role> */
    public const array ROLES = [Role::Supervisor, Role::Technician, Role::DesignOffice];

    public function mount(): void
    {
        $this->authorize('manage-platform');
    }

    public function toggle(string $feature, string $role, FeatureAccess $access): void
    {
        $this->authorize('manage-platform');

        $feature = Feature::from($feature);
        $role = Role::from($role);
        abort_unless(in_array($role, $feature->configurableRoles(), true), 422);

        if ($this->passwordConfirmationNeeded(__FUNCTION__, [$feature->value, $role->value])) {
            return;
        }

        $roleId = RoleModel::findByName($role->value)->id;
        $enabled = ! $access->enabledForRole($feature, $role);

        if ($enabled === in_array($role, $feature->defaultRoles(), true)) {
            RoleFeatureOverride::query()->where('feature', $feature)->where('role_id', $roleId)->delete();
        } else {
            RoleFeatureOverride::query()->updateOrCreate(['feature' => $feature, 'role_id' => $roleId], ['is_enabled' => $enabled]);
        }

        $access->forgetOverrides();

        $this->dispatch('toast', message: __('access.saved'));
    }

    public function resetToDefaults(FeatureAccess $access): void
    {
        $this->authorize('manage-platform');

        if (! RoleFeatureOverride::query()->exists()) {
            $this->dispatch('toast', message: __('access.nothing_to_reset'));

            return;
        }

        if ($this->passwordConfirmationNeeded(__FUNCTION__)) {
            return;
        }

        RoleFeatureOverride::query()->delete();
        $access->forgetOverrides();
    }

    public function render(FeatureAccess $access): View
    {
        $rows = collect(Feature::cases())->map(fn (Feature $feature) => [
            'feature' => $feature,
            'administrator' => $feature->isGrantedToAdministrators(),
            'roles' => collect(self::ROLES)->mapWithKeys(fn (Role $role) => [$role->value => in_array($role, $feature->configurableRoles(), true) ? [
                'enabled' => $access->enabledForRole($feature, $role),
                'changed' => $access->enabledForRole($feature, $role) !== in_array($role, $feature->defaultRoles(), true),
            ] : null])->all(),
        ])->groupBy(fn (array $row) => $row['feature']->group());

        return view('livewire.admin.access-management', ['groups' => $rows, 'roles' => self::ROLES])
            ->layout('components.layouts.app', ['title' => __('access.title'), 'page' => 'access']);
    }
}
