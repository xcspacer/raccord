<?php

namespace App\Policies;

use App\Enums\ClientRemarkTarget;
use App\Enums\Feature;
use App\Models\Audit;
use App\Models\ClientRemark;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Client portal notes. « Contrôle client » answers, closes and reopens them. Audit managers take any note into
 * account; a technician the box and control point notes of the audits they work on. A note on an audit the person
 * does not work on does not exist for them (404); an audit-level note they may see answers 403.
 */
class ClientRemarkPolicy
{
    public function manage(User $user): bool
    {
        return Gate::forUser($user)->allows('open-feature', Feature::ClientControl);
    }

    public function acknowledge(User $user, ClientRemark $remark): Response
    {
        $gate = Gate::forUser($user);

        if ($gate->allows('manage', Audit::class)) {
            return Response::allow();
        }

        if ($gate->denies('work', $remark->audit)) {
            return Response::denyAsNotFound();
        }

        return $remark->target !== ClientRemarkTarget::Audit ? Response::allow() : Response::deny();
    }
}
