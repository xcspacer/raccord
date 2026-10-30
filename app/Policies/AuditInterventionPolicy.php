<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\Audit;
use App\Models\AuditIntervention;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Phase 2 of the design office workflow (repairs). The supervisors open the phase, see the boxes to repair and plan
 * or cancel interventions; technicians read the interventions they belong to (the phone). Without the workflow
 * (module or « Gérer les accès » switch) or with another role, the answer is 403.
 */
class AuditInterventionPolicy
{
    public function __construct(private FeatureAccess $access) {}

    /**
     * The « Phase 2 — Reprises » screen: pool of boxes to repair and interventions.
     */
    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    public function openPhase(User $user, Audit $audit): Response
    {
        return $this->viewAny($user);
    }

    public function plan(User $user, Audit $audit): Response
    {
        return $this->viewAny($user);
    }

    public function cancel(User $user, AuditIntervention $intervention): Response
    {
        return $this->viewAny($user);
    }

    /**
     * The interventions a technician belongs to.
     */
    public function viewOwn(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && WorkflowRoles::isTechnician($user) ? Response::allow() : Response::deny();
    }
}
