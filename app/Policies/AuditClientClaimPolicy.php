<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Enums\AuditClientClaimState;
use App\Models\AuditClientClaim;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Claims of the clients on design office audits (reserve disputes, new occurrences). The design office and the
 * supervisors read and answer them; a second dispute of a reserve (escalated) is a supervisor's only. Technicians and
 * anyone without the workflow never reach them (403).
 */
class AuditClientClaimPolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))
            ? Response::allow()
            : Response::deny();
    }

    public function decide(User $user, AuditClientClaim $claim): Response
    {
        $base = $this->viewAny($user);

        if ($base->denied() || WorkflowRoles::isSupervisor($user)) {
            return $base;
        }

        return $claim->state === AuditClientClaimState::Escalated ? Response::deny() : Response::allow();
    }
}
