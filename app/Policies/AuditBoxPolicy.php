<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditBox;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Design office workflow on a box. Without the workflow (module or « Gérer les accès » switch) the answer is 403; a
 * technician who is not planned on the audit does not see it (404, as for the audit itself); starting, handing over
 * and submitting a box are the technicians' (the service then checks they are its responsible), naming a responsible
 * is the supervisors'.
 */
class AuditBoxPolicy
{
    public function __construct(private FeatureAccess $access) {}

    /**
     * Read where the box stands in the workflow (state, request, decision).
     */
    public function viewWorkflow(User $user, AuditBox $box): Response
    {
        if (! $this->access->allowsDesignOfficeWorkflow($user)) {
            return Response::deny();
        }

        $box->loadMissing('audit');

        return Gate::forUser($user)->inspect('work', $box->audit);
    }

    public function claim(User $user, AuditBox $box): Response
    {
        return $this->technician($user, $box);
    }

    public function handOver(User $user, AuditBox $box): Response
    {
        return $this->technician($user, $box);
    }

    public function submit(User $user, AuditBox $box): Response
    {
        return $this->technician($user, $box);
    }

    /**
     * Write in the internal chat of the box (the kind of message is checked by the service).
     */
    public function postMessage(User $user, AuditBox $box): Response
    {
        return $this->viewWorkflow($user, $box);
    }

    public function submitRepairs(User $user, AuditBox $box): Response
    {
        return $this->technician($user, $box);
    }

    /**
     * Declare (and end) a pause on the box: a technician of its team (checked by the service).
     */
    public function declarePause(User $user, AuditBox $box): Response
    {
        return $this->technician($user, $box);
    }

    /**
     * Declare an occurrence on the box: a technician (the service checks they work on the box) or a supervisor; the
     * design office qualifies and validates, it never declares field anomalies.
     */
    public function createOccurrence(User $user, AuditBox $box): Response
    {
        $base = $this->viewWorkflow($user, $box);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isTechnician($user) || WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    /**
     * Declare an occurrence from the desktop, on a box chosen in the form (each box is then checked with
     * createOccurrence): supervisors only.
     */
    public function createAnyOccurrence(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    public function designateResponsible(User $user, AuditBox $box): Response
    {
        $base = $this->viewWorkflow($user, $box);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    /**
     * Propose a corrected position of the box (a technician working on the audit).
     */
    public function proposePosition(User $user, AuditBox $box): Response
    {
        return $this->technician($user, $box);
    }

    /**
     * Make a position official or reject a proposed one: the design office and the supervisors.
     */
    public function decidePosition(User $user, AuditBox $box): Response
    {
        $base = $this->viewWorkflow($user, $box);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isReviewer($user) || WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    private function technician(User $user, AuditBox $box): Response
    {
        $base = $this->viewWorkflow($user, $box);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isTechnician($user) ? Response::allow() : Response::deny();
    }
}
