<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditBox;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Indicators and production times of the design office workflow. Everyone in the workflow opens the indicators (each
 * one then sees only their own figures, supervisors all of them); only a supervisor corrects the production time of a
 * box. Without the workflow: 403.
 */
class AuditDurationAdjustmentPolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) ? Response::allow() : Response::deny();
    }

    public function create(User $user, AuditBox $box): Response
    {
        $base = $this->viewAny($user);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }
}
