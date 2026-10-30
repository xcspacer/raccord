<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditAnomalyType;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * The anomaly types of the design office workflow: kept by the supervisors only (403 for anyone else, and without
 * the workflow). Technicians read the active ones with the occurrences of their box.
 */
class AuditAnomalyTypePolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function update(User $user, AuditAnomalyType $type): Response
    {
        return $this->viewAny($user);
    }
}
