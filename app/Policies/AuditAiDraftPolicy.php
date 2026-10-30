<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditAiDraft;
use App\Models\AuditOccurrence;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * AI drafts of occurrences: the design office and the supervisors ask for one, read them, correct, approve or reject
 * them (internal history). Technicians and anyone without the workflow never reach them (403); an occurrence of a
 * standard audit does not exist here (404).
 */
class AuditAiDraftPolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user, AuditOccurrence $occurrence): Response
    {
        if (! $this->access->allowsDesignOfficeWorkflow($user) || ! (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))) {
            return Response::deny();
        }

        return $occurrence->audit?->usesDesignOfficeWorkflow() === true ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, AuditOccurrence $occurrence): Response
    {
        return $this->viewAny($user, $occurrence);
    }

    public function update(User $user, AuditAiDraft $draft): Response
    {
        return $this->viewAny($user, $draft->occurrence);
    }
}
