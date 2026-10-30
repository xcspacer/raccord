<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditReserve;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Reserves of the design office workflow: the design office and the supervisors list and propose them; only a
 * supervisor accepts a reserve or closes a follow-up one. Technicians and anyone without the workflow get a 403.
 */
class AuditReservePolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))
            ? Response::allow()
            : Response::deny();
    }

    public function propose(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function accept(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    public function close(User $user, AuditReserve $reserve): Response
    {
        return $this->accept($user);
    }
}
