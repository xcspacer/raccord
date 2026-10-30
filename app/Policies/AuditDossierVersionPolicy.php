<?php

namespace App\Policies;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\Audit;
use App\Models\AuditDossierVersion;
use App\Models\User;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * The official dossier of a design office audit: the design office and the supervisors follow its versions and
 * download the published ones; only a supervisor relaunches a failed generation. Technicians and anyone without the
 * workflow are refused (403); a standard audit has no dossier and a version not published yet has nothing to
 * download (404).
 */
class AuditDossierVersionPolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user, Audit $audit): Response
    {
        if (! $this->access->allowsDesignOfficeWorkflow($user) || ! (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))) {
            return Response::deny();
        }

        return $audit->usesDesignOfficeWorkflow() ? Response::allow() : Response::denyAsNotFound();
    }

    public function download(User $user, AuditDossierVersion $version): Response
    {
        $view = $this->viewAny($user, $version->audit);

        if ($view->denied()) {
            return $view;
        }

        return $version->isPublished() ? Response::allow() : Response::denyAsNotFound();
    }

    public function relaunch(User $user, AuditDossierVersion $version): Response
    {
        $view = $this->viewAny($user, $version->audit);

        if ($view->denied()) {
            return $view;
        }

        return WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }
}
