<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The media library tree (cabinets and folders): browsed, created, renamed, merged and deleted by whoever opens the
 * media library.
 */
class MediaFolderPolicy
{
    public function manage(User $user): bool
    {
        return Gate::forUser($user)->allows('open-feature', Feature::MediaLibrary);
    }
}
