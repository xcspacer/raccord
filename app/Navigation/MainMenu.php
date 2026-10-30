<?php

namespace App\Navigation;

use App\Enums\Feature;
use App\Enums\Module;
use App\Enums\Role;
use App\Models\Audit;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\EffectiveAgency;
use App\Support\FeatureAccess;
use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Builds the "Fonctions" menu of a user. An entry appears only when the user may open it (the same rule the server
 * applies, FeatureAccess::opens()) and its page exists, so the menu grows by itself as modules are built.
 */
class MainMenu
{
    public function __construct(
        private FeatureAccess $access,
        private EffectiveAgency $agency,
    ) {}

    /**
     * @return list<MenuGroup>
     */
    public function for(User $user): array
    {
        $role = $this->access->roleOf($user);
        $isTechnician = $role === Role::Technician;
        $isAdministrator = $role === Role::Administrator;
        $isManager = in_array($role, [Role::Administrator, Role::Supervisor], true);
        $dailyMode = $this->agency->isDailyMode($user);
        $can = fn (Feature $feature): bool => $this->access->opens($user, $feature);
        $tasksAsPlanning = $dailyMode || $isTechnician;

        $groups = [
            'planning' => ['#4C8FBF', [
                $this->item('planning', $tasksAsPlanning ? 'planning.tasks' : 'planning', $tasksAsPlanning ? 'tasks' : 'timeline', $tasksAsPlanning ? 'planning_tasks' : 'planning_timeline', fn () => $isManager || $isTechnician),
                $this->item('work_sites', 'work-sites.index', 'list', $dailyMode ? 'work_sites_production' : 'work_sites', fn () => $isManager),
                $this->item('create_work_site', 'work-sites.create', 'add', 'create_work_site', fn () => $can(Feature::CreateWorkSite)),
                $this->item('absences', 'absences.index', 'absences', 'absences', fn () => $can(Feature::Absences)),
            ]],
            'field' => ['#F47C10', [
                $this->item('technician_space', 'technician-space', 'person', 'technician_space', fn () => $can(Feature::TechnicianSpace)),
                $this->item('billing', 'billing', 'clock', 'billing', fn () => $can(Feature::Billing)),
                $this->item('otdr_acceptance', 'otdr', 'otdr', 'otdr_acceptance', fn () => $can(Feature::OtdrAcceptance)),
            ]],
            'audits' => ['#3DD68C', [
                $this->item('audits_optical', 'audits.optical', 'eye', 'audits_optical', fn () => $can(Feature::Audits)),
                $this->item('audits_full', 'audits.full', 'clipboard', 'audits_full', fn () => $can(Feature::Audits)),
                $this->item('weekly_timesheet', 'audits.weekly-timesheet', 'chart', 'weekly_timesheet', fn () => $can(Feature::WeeklyTimesheet)),
                $this->item('audit_catalog', 'audits.catalog', 'list', 'audit_catalog', fn () => $can(Feature::Audits) && $user->can('manage', Audit::class)),
                $this->item('design_office_queue', 'design-office.queue', 'clock', 'design_office_queue', fn () => $can(Feature::DesignOfficeWorkflow)),
                $this->item('design_office_occurrences', 'design-office.occurrences', 'eye', 'design_office_occurrences', fn () => $can(Feature::DesignOfficeWorkflow)),
                $this->item('design_office_catalogues', 'design-office.catalogues', 'list', 'design_office_catalogues', fn () => $isManager && $can(Feature::DesignOfficeWorkflow)),
                $this->item('design_office_claims', 'design-office.claims', 'shield', 'design_office_claims', fn () => $can(Feature::DesignOfficeWorkflow) && app(ModuleSettings::class)->isEnabled(Module::ClientPortal)),
                $this->item('design_office_indicators', 'design-office.indicators', 'chart', 'design_office_indicators', fn () => $this->access->allowsDesignOfficeWorkflow($user)),
            ]],
            'tools' => ['#F5D400', [
                $this->item('expense_reports', 'expense-reports', 'receipt', 'expense_reports', fn () => $can(Feature::ExpenseReports)),
                $this->item('client_control', 'client-control', 'shield', 'client_control', fn () => $can(Feature::ClientControl)),
                $this->item('media_library', 'media-library', 'image', 'media_library', fn () => $can(Feature::MediaLibrary)),
                $this->item('fibre_code', 'fibre-code', 'fibre', 'fibre_code', fn () => $can(Feature::FibreCode)),
            ]],
            'administration' => ['#B69CFF', [
                $this->item('users', 'users.index', 'users', 'users', fn () => $isAdministrator),
                $this->item('access', 'access.index', 'shield', 'access', fn () => $isAdministrator),
                $this->item('equipment', 'equipment.index', 'equipment', 'equipment', fn () => $can(Feature::EquipmentManagement)),
                $this->item('agencies', 'agencies.index', 'building', 'agencies', fn () => $isAdministrator),
                $this->item('companies', 'companies.index', 'building', 'companies', fn () => $isAdministrator),
                $this->item('holidays', 'holidays.index', 'calendar', 'holidays', fn () => $isAdministrator),
                $this->item('platform_settings', 'platform-settings', 'cog', 'platform_settings', fn () => $isAdministrator),
                $this->item('logs', 'logs.index', 'history', 'logs', fn () => $isAdministrator),
            ]],
        ];

        $menu = [];

        foreach ($groups as $key => [$colour, $candidates]) {
            $items = array_values(array_filter($candidates));

            if ($items !== []) {
                $menu[] = new MenuGroup($key, __('navigation.groups.'.$key), $colour, $items);
            }
        }

        return $menu;
    }

    /**
     * @param  Closure(): bool  $visible
     */
    private function item(string $key, string $route, string $icon, string $text, Closure $visible): ?MenuItem
    {
        if (! Route::has($route) || ! $visible()) {
            return null;
        }

        return new MenuItem($key, $route, $icon, __('navigation.items.'.$text.'.label'), __('navigation.items.'.$text.'.description'));
    }
}
