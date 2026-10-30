<?php

namespace App\Policies;

use App\Models\DayPunch;
use App\Models\User;
use App\Support\DayPunchRules;

/**
 * Only technicians punch, and always for themselves (the person comes from the session, AUTZ-12).
 */
class DayPunchPolicy
{
    public function __construct(private DayPunchRules $rules) {}

    /**
     * Start, end or declare the end of one's own day.
     */
    public function create(User $user): bool
    {
        return $this->rules->appliesTo($user);
    }

    /**
     * Change one's own day that is still open (declare a forgotten end).
     */
    public function update(User $user, DayPunch $punch): bool
    {
        return $punch->user_id === $user->id && $this->create($user);
    }
}
