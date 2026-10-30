<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Logs\AdministratorAlerts;
use App\Logs\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeUserRole
{
    /** Journal event of a role change (roles live in the permission tables, outside the user's own record). */
    public const string EVENT = 'role_changed';

    public function __construct(private UserRules $rules, private SecurityLog $security, private AdministratorAlerts $alerts) {}

    /**
     * Gives the person this role (one role each), keeping at least one administrator. The change is listed in
     * « Logs » (before → after, never restorable from there) and written to the security log; a new administrator is
     * e-mailed to the administrators.
     */
    public function handle(User $by, User $user, Role $role): void
    {
        $previous = $user->roles()->value('name');

        if ($previous === $role->value) {
            return;
        }

        if ($role !== Role::Administrator) {
            $this->rules->ensureAnotherAdministratorRemains($user);
        }

        DB::transaction(function () use ($by, $user, $role, $previous): void {
            $user->syncRoles([$role->value]);

            activity(User::activityLogName())
                ->performedOn($user)
                ->causedBy($by)
                ->event(self::EVENT)
                ->withChanges(['attributes' => ['role' => $role->value], 'old' => ['role' => $previous]])
                ->log(self::EVENT);
        });

        $this->security->warning(SecurityLog::ROLE_CHANGED, ['user_id' => $user->id, 'from' => $previous, 'to' => $role->value]);

        if ($role === Role::Administrator) {
            $this->alerts->send(SecurityLog::ROLE_CHANGED, ['by' => $by->name, 'name' => $user->name, 'username' => $user->username]);
        }
    }
}
