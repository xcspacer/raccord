<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditDecisionType;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * The decisions of the design office catalogue: kept by the supervisors only (403 for anyone else, and without the
 * workflow). The design office reads the active ones on its decision screen.
 */
class AuditDecisionTypePolicy
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

    public function update(User $user, AuditDecisionType $type): Response
    {
        return $this->viewAny($user);
    }
}
