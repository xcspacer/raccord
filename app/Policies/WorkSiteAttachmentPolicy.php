<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkSiteAttachment;
use Illuminate\Auth\Access\Response;

/**
 * A work site file (KMZ, synoptic…): for managers and the people working on that work site (crew, box technicians
 * and partners); for anyone else it does not exist (404).
 */
class WorkSiteAttachmentPolicy
{
    public function view(User $user, WorkSiteAttachment $attachment): Response
    {
        $site = $attachment->workSite()->with(['members', 'boxes.partners'])->first();

        if ($site === null) {
            return Response::denyAsNotFound();
        }

        $isOnSite = $site->members->contains('id', $user->id)
            || $site->boxes->contains(fn ($box) => $box->technician_id === $user->id || $box->partners->contains('id', $user->id));

        return $user->isManager() || $isOnSite ? Response::allow() : Response::denyAsNotFound();
    }
}
