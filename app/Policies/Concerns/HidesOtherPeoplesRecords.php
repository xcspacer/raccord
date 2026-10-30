<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Personal records (documents, vehicle, expenses) belong to one person: they and the managers see them, and for
 * anyone else the record does not exist (404, AUTZ-16).
 */
trait HidesOtherPeoplesRecords
{
    protected function ownerOrManager(User $user, ?int $ownerId): Response
    {
        return $ownerId === $user->id || $user->isManager() ? Response::allow() : Response::denyAsNotFound();
    }
}
