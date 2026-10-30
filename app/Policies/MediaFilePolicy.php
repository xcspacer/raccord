<?php

namespace App\Policies;

use App\Enums\Feature;
use App\MediaLibrary\TaskMediaTargets;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Media library files: whoever opens the media library sees and deletes them; a technician only sees the photos of
 * the targets of their own tasks. Any other file does not exist for them (404).
 */
class MediaFilePolicy
{
    public function __construct(private TaskMediaTargets $targets) {}

    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('open-feature', Feature::MediaLibrary);
    }

    public function view(User $user, MediaFile $file): Response
    {
        return $this->viewAny($user) || $this->targets->mayView($user, $file) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * A photo of one of the targets of the person's own tasks, as reached from the task detail.
     */
    public function viewFromTask(User $user, MediaFile $file): Response
    {
        return $this->targets->mayView($user, $file) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, MediaFile $file): bool
    {
        return $this->viewAny($user);
    }
}
