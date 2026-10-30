<?php

namespace App\Policies;

use App\Models\AuditPhoto;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * An audit photo: for whoever works on its audit (AuditPolicy::work); for anyone else it does not exist (404,
 * AUTZ-09).
 */
class AuditPhotoPolicy
{
    public function view(User $user, AuditPhoto $photo): Response
    {
        return Gate::forUser($user)->allows('work', $photo->audit) ? Response::allow() : Response::denyAsNotFound();
    }
}
