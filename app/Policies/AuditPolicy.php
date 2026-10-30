<?php

namespace App\Policies;

use App\Audit\BoxName;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Audit;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\EffectiveAgency;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Managers (administrators, supervisors and the design office) handle every audit; a technician works only on the
 * audits of the cabinets they are planned on, as lead or partner. For a technician who is not, the audit does not
 * exist (404, AUTZ-09/16); without the audits access at all the refusal is a plain 403.
 */
class AuditPolicy
{
    public function __construct(private FeatureAccess $access, private EffectiveAgency $agency) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAudits($user, $this->agency);
    }

    public function manage(User $user, ?Audit $audit = null): bool
    {
        return $this->viewAny($user) && $user->hasAnyRole([Role::Administrator->value, Role::Supervisor->value, Role::DesignOffice->value]);
    }

    /**
     * Take part in the audit: read it and record what is done on site.
     */
    public function work(User $user, Audit $audit): Response
    {
        if ($this->manage($user)) {
            return Response::allow();
        }

        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $user->hasRole(Role::Technician->value) && $this->isPlannedOn($user, $audit) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Open a cabinet the office has not configured yet: the managers (to configure it) and the technicians planned on
     * it (they are told the audit is not ready yet); anyone else is told it does not exist.
     */
    public function awaitSetup(User $user, string $cabinetKey): Response
    {
        if ($this->manage($user)) {
            return Response::allow();
        }

        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $user->hasRole(Role::Technician->value) && $this->isPlannedOnCabinet($user, $cabinetKey) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Correct the recheck of a box or of the drawers: whoever works on the audit while it is open; once validated,
     * only the managers. A closed audit of the design office workflow no longer changes (only a formal reopening).
     */
    public function recheck(User $user, Audit $audit): Response
    {
        $work = $this->work($user, $audit);

        if ($work->allowed() && $audit->usesDesignOfficeWorkflow() && $audit->isClosed()) {
            return Response::deny();
        }

        if ($work->denied() || $this->manage($user) || ! $audit->isValidated()) {
            return $work;
        }

        return Response::deny();
    }

    /**
     * Follow the closure of the audits of the design office workflow (closing checks, reserves, dossier): the design
     * office and the supervisors.
     */
    public function viewClosure(User $user, ?Audit $audit = null): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && (WorkflowRoles::isSupervisor($user) || WorkflowRoles::isReviewer($user))
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Close an audit of the design office workflow, or reopen it formally: supervisors only.
     */
    public function close(User $user, Audit $audit): Response
    {
        return $this->access->allowsDesignOfficeWorkflow($user) && WorkflowRoles::isSupervisor($user) ? Response::allow() : Response::deny();
    }

    public function reopen(User $user, Audit $audit): Response
    {
        return $this->close($user, $audit);
    }

    private function isPlannedOnCabinet(User $user, string $cabinetKey): bool
    {
        return WorkSite::query()->where('project_type', ProjectType::Audit->value)->whereNotNull('cabinet_ref')
            ->where(fn ($query) => $query->where('lead_technician_id', $user->id)->orWhereHas('members', fn ($members) => $members->whereKey($user->id)))
            ->pluck('cabinet_ref')
            ->contains(fn (string $reference) => BoxName::cabinetKey($reference) === $cabinetKey);
    }

    private function isPlannedOn(User $user, Audit $audit): bool
    {
        return WorkSite::query()->where('project_type', ProjectType::Audit->value)
            ->where(fn ($query) => $query->where('audit_id', $audit->id)->orWhereNotNull('cabinet_ref'))
            ->where(fn ($query) => $query->where('lead_technician_id', $user->id)->orWhereHas('members', fn ($members) => $members->whereKey($user->id)))
            ->get(['id', 'audit_id', 'cabinet_ref'])
            ->contains(fn (WorkSite $site) => $site->audit_id === $audit->id || BoxName::cabinetKey((string) $site->cabinet_ref) === $audit->cabinet_key);
    }
}
