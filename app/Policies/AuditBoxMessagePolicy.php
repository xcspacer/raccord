<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditBoxMessage;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Internal messages of a box in the design office workflow: blocking ones are resolved by the design office or a
 * supervisor, never by a technician.
 */
class AuditBoxMessagePolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function resolve(User $user, AuditBoxMessage $message): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))
            ? Response::allow()
            : Response::deny();
    }
}
