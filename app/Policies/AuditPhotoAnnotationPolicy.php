<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\AuditPhoto;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Drawings over a photo in the design office workflow: seen by whoever works on its audit (a technician not planned on
 * it does not see the photo: 404), drawn by the design office and the supervisors only. Without the workflow (module
 * or « Gérer les accès » switch) the answer is 403.
 */
class AuditPhotoAnnotationPolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function view(User $user, AuditPhoto $photo): Response
    {
        if (! $this->access->allowsDesignOfficeWorkflow($user)) {
            return Response::deny();
        }

        return Gate::forUser($user)->inspect('view', $photo);
    }

    public function annotate(User $user, AuditPhoto $photo): Response
    {
        $base = $this->view($user, $photo);

        if ($base->denied()) {
            return $base;
        }

        return WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user) ? Response::allow() : Response::deny();
    }
}
