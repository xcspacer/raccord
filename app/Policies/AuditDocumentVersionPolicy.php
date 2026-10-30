<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\Audit;
use App\Models\AuditDocumentVersion;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Versioned documents of a PM in the design office workflow (synoptic, map file). The design office and the supervisors
 * publish them and see every version; a technician working on the PM downloads the current ones only (an archived
 * version does not exist for them: 404). Without the workflow the answer is 403; for a technician not planned on the
 * PM, it does not exist (404, as the audit itself).
 */
class AuditDocumentVersionPolicy
{
    public function __construct(private FeatureAccess $access) {}

    /**
     * See every version of the PM's documents.
     */
    public function viewAny(User $user, Audit $audit): Response
    {
        return $this->office($user, $audit);
    }

    /**
     * See the current documents of the PM (whoever works on it in the workflow).
     */
    public function viewCurrent(User $user, Audit $audit): Response
    {
        return $this->base($user, $audit);
    }

    public function publish(User $user, Audit $audit): Response
    {
        return $this->office($user, $audit);
    }

    public function view(User $user, AuditDocumentVersion $version): Response
    {
        $version->loadMissing('audit');
        $base = $this->base($user, $version->audit);

        if ($base->denied() || WorkflowRoles::isReviewer($user) || WorkflowRoles::isSupervisor($user)) {
            return $base;
        }

        return $version->is_current ? Response::allow() : Response::denyAsNotFound();
    }

    private function office(User $user, Audit $audit): Response
    {
        $base = $this->base($user, $audit);

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
