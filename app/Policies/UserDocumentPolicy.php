<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserDocument;
use App\Policies\Concerns\HidesOtherPeoplesRecords;
use Illuminate\Auth\Access\Response;

/**
 * A personal document (BTP card, driving licence): for its owner and the managers; anyone else gets a 404.
 */
class UserDocumentPolicy
{
    use HidesOtherPeoplesRecords;

    public function view(User $user, UserDocument $userDocument): Response
    {
        return $this->ownerOrManager($user, $userDocument->user_id);
    }
}
