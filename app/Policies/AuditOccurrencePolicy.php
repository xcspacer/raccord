<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditOccurrence;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Occurrences of the design office workflow. The lists and the detail screen are the design office's and the
 * supervisors' (technicians read the occurrences of their boxes from the box). On the field side the audit must be
 * the person's (404 otherwise, as for the audit itself): technicians and supervisors link photos, put on hold and
 * correct (the service then checks the technician works on the box); anyone of the workflow proposes an impacted box;
 * only the design office and the supervisors confirm or exclude one. Without the workflow the answer is 403.
 */
class AuditOccurrencePolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, AuditOccurrence $occurrence): Response
    {
        return $this->viewAny($user);
    }

    /**
     * Link a photo, put on hold, correct.
     */
    public function update(User $user, AuditOccurrence $occurrence): Response
    {
        $base = $this->field($user, $occurrence);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isTechnician($user) || WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    public function proposeImpactedBox(User $user, AuditOccurrence $occurrence): Response
    {
        return $this->field($user, $occurrence);
    }

    public function decideImpactedBox(User $user, AuditOccurrence $occurrence): Response
    {
        return $this->viewAny($user);
    }

    public function setImpactedResult(User $user, AuditOccurrence $occurrence): Response
    {
        return $this->field($user, $occurrence);
    }

    private function field(User $user, AuditOccurrence $occurrence): Response
    {
        if (! $this->access->allowsDesignOfficeWorkflow($user)) {
            return Response::deny();
        }

        $occurrence->loadMissing('audit');

        return Gate::forUser($user)->inspect('work', $occurrence->audit);
    }
}
