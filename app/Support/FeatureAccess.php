<?php

namespace App\Support;

use App\Enums\Feature;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Settings\ModuleSettings;

/**
 * Decides whether a user may use a feature: its module must be enabled on the installation, then
 * administrators always may, and other roles follow the stored "Gérer les accès" override or the
 * catalogue default.
 */
class FeatureAccess
{
    /** @var array<string, array<string, bool>>|null */
    private ?array $overrides = null;

    public function __construct(private ModuleSettings $modules) {}

    public function allows(User $user, Feature $feature): bool
    {
        if (! $this->modules->isEnabled($feature->module())) {
            return false;
        }

        if ($user->hasRole(Role::Administrator->value)) {
            return $feature->isGrantedToAdministrators();
        }

        $role = $this->roleOf($user);

        if ($role === null) {
            return false;
        }

        return $this->enabledForRole($feature, $role);
    }

    /**
     * Whether a (non-administrator) role has the feature: stored override, else catalogue default. A role the
     * feature cannot be switched on for keeps the default, whatever is stored.
     */
    public function enabledForRole(Feature $feature, Role $role): bool
    {
        if (! in_array($role, $feature->configurableRoles(), true)) {
            return in_array($role, $feature->defaultRoles(), true);
        }

        return $this->overrides()[$feature->value][$role->value]
            ?? in_array($role, $feature->defaultRoles(), true);
    }

    /**
     * Drop the overrides memoised for this request (after they are changed).
     */
    public function forgetOverrides(): void
    {
        $this->overrides = null;
    }

    /**
     * Whether the user may open the screens of a feature right now: the switch, plus the agency rules the menu
     * applies. Absences, and the media library of administrators and supervisors, need a daily-mode agency; client
     * control and the audits' weekly timesheet need the audits.
     */
    public function opens(User $user, Feature $feature): bool
    {
        if ($feature === Feature::Audits) {
            return $this->allowsAudits($user, app(EffectiveAgency::class));
        }

        if ($feature === Feature::DesignOfficeWorkflow) {
            return $this->roleOf($user) !== Role::Technician && $this->allowsDesignOfficeWorkflow($user);
        }

        if (! $this->allows($user, $feature)) {
            return false;
        }

        return match ($feature) {
            Feature::Absences => app(EffectiveAgency::class)->isDailyMode($user),
            Feature::MediaLibrary => match ($this->roleOf($user)) {
                Role::DesignOffice => true,
                Role::Technician, null => false,
                default => app(EffectiveAgency::class)->isDailyMode($user),
            },
            Feature::ClientControl, Feature::WeeklyTimesheet => $this->allowsAudits($user, app(EffectiveAgency::class)),
            default => true,
        };
    }

    /**
     * Audits: the design office has them while its switch is on; administrators, and supervisors while their switch
     * is on, only on a daily-mode agency; technicians (no switch) when attached to a daily-mode agency or to every
     * agency.
     */
    public function allowsAudits(User $user, EffectiveAgency $agency): bool
    {
        if (! $this->modules->isEnabled(Feature::Audits->module())) {
            return false;
        }

        return match ($this->roleOf($user)) {
            null => false,
            Role::Administrator => $agency->isDailyMode($user),
            Role::Supervisor => $this->enabledForRole(Feature::Audits, Role::Supervisor) && $agency->isDailyMode($user),
            Role::DesignOffice => $this->enabledForRole(Feature::Audits, Role::DesignOffice),
            Role::Technician => $user->all_agencies || $user->agency?->planning_mode === PlanningMode::Daily,
        };
    }

    /**
     * Design office workflow: its switch, on top of the audits themselves (technicians work in it from the box screen,
     * the others also open the review queue).
     */
    public function allowsDesignOfficeWorkflow(User $user): bool
    {
        return $this->allows($user, Feature::DesignOfficeWorkflow) && $this->allowsAudits($user, app(EffectiveAgency::class));
    }

    public function roleOf(User $user): ?Role
    {
        return Role::tryFrom((string) $user->getRoleNames()->first());
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function overrides(): array
    {
        return $this->overrides ??= RoleFeatureOverride::query()
            ->join('roles', 'roles.id', '=', 'role_feature_overrides.role_id')
            ->get(['role_feature_overrides.feature', 'role_feature_overrides.is_enabled', 'roles.name as role_name'])
            ->groupBy(fn (RoleFeatureOverride $override) => $override->feature->value)
            ->map(fn ($rows) => $rows->mapWithKeys(fn (RoleFeatureOverride $override) => [$override->getAttribute('role_name') => $override->is_enabled])->all())
            ->all();
    }
}
