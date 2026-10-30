<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\Role;
use App\Models\User;

/**
 * The three parts of the design office workflow mapped on the platform roles: administrators and supervisors act as
 * the workflow's supervisors, the design office reviews, technicians work on site.
 */
class WorkflowRoles
{
    public static function isSupervisor(User $user): bool
    {
        return $user->hasAnyRole([Role::Administrator->value, Role::Supervisor->value]);
    }

    public static function isReviewer(User $user): bool
    {
        return $user->hasRole(Role::DesignOffice->value);
    }

    public static function isTechnician(User $user): bool
    {
        return $user->hasRole(Role::Technician->value);
    }
}
