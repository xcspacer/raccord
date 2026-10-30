<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\Audit;
use App\Models\AuditSynopticCorrection;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Corrections of the synoptic in the design office workflow: a technician working on the PM proposes them and follows
 * their own; the design office and the supervisors see all of them, include them in a consolidated synoptic or reject
 * them. Without the workflow the answer is 403; for a technician not planned on the PM, it does not exist (404).
 */
class AuditSynopticCorrectionPolicy
{
    public function __construct(private FeatureAccess $access) {}

    /**
     * List the corrections of the PM (a technician sees their own only).
     */
    public function viewAny(User $user, Audit $audit): Response
    {
        return $this->base($user, $audit);
    }

    public function create(User $user, Audit $audit): Response
    {
        $base = $this->base($user, $audit);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isTechnician($user) ? Response::allow() : Response::deny();
    }

    public function decide(User $user, AuditSynopticCorrection $correction): Response
    {
        $correction->loadMissing('audit');
        $base = $this->base($user, $correction->audit);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isReviewer($user) || WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    private function base(User $user, Audit $audit): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) ? Gate::forUser($user)->inspect('work', $audit) : Response::deny();
    }
}
