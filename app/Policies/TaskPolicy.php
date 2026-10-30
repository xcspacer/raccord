<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * The daily planning (tâches): managers plan everyone's days (tasks, crews, weeks shown); a technician only reads
 * their own days from the task grid.
 */
class TaskPolicy
{
    /**
     * Plan tasks, copy and move them, and choose who shows on which week.
     */
    public function manage(User $user, ?Task $task = null): bool
    {
        return $user->isManager();
    }
}
