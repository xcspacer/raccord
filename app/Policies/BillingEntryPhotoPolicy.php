<?php

namespace App\Policies;

use App\Models\BillingEntry;
use App\Models\BillingEntryPhoto;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A photo of an entry: for the person who sent it and whoever may see the entry (even deleted); anyone else gets a
 * 404.
 */
class BillingEntryPhotoPolicy
{
    public function view(User $user, BillingEntryPhoto $photo): Response
    {
        $entry = BillingEntry::query()->withTrashed()->find($photo->billing_entry_id);

        if ($photo->uploaded_by === $user->id) {
            return Gate::forUser($user)->inspect('create', BillingEntry::class)->allowed() ? Response::allow() : Response::deny();
        }

        return $entry === null ? Response::denyAsNotFound() : Gate::forUser($user)->inspect('view', $entry);
    }
}
