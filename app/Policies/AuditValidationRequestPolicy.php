<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Enums\Module;
use App\Enums\Role;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * The design office queue. Reviewers see and decide only the requests given to them (any other one does not exist
 * for them: 404); supervisors see all of them, the central queue included, and give them to a reviewer. Technicians
 * never reach the queue (403), nor does anyone without the workflow.
 */
class AuditValidationRequestPolicy
{
    public function __construct(private FeatureAccess $access, private ModuleSettings $modules) {}

    public function viewAny(User $user): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, AuditValidationRequest $request): Response
    {
        $queue = $this->viewAny($user);

        if ($queue->denied() || WorkflowRoles::isSupervisor($user)) {
            return $queue;
        }

        return $request->assigned_to === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function decide(User $user, AuditValidationRequest $request): Response
    {
        return $this->view($user, $request);
    }

    public function reassign(User $user, AuditValidationRequest $request): Response
    {
        return $this->viewAny($user)->allowed() && WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    /**
     * A reviewer says whether they are available; a supervisor may say it for any reviewer, and an administrator also
     * from the user management page (whatever agency they look at).
     */
    public function setAvailability(User $user, User $reviewer): Response
    {
        if ($user->hasRole(Role::Administrator->value) && $this->modules->isEnabled(Module::DesignOfficeWorkflow)) {
            return Response::allow();
        }

        if (! $this->access->allowsDesignOfficeWorkflow($user)) {
            return Response::deny();
        }

        return WorkflowRoles::isSupervisor($user) || (WorkflowRoles::isReviewer($user) && $user->id === $reviewer->id) ? Response::allow() : Response::deny();
    }
}
