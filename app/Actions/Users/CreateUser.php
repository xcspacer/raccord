<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Logs\AdministratorAlerts;
use App\Logs\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateUser
{
    public function __construct(private SecurityLog $security, private AdministratorAlerts $alerts) {}

    /**
     * The person must choose their own password at the first sign-in. A new administrator is a warning in the
     * security log and is e-mailed to the administrators.
     *
     * @param  array{name: string, username: string, role: Role, agency_id: ?int, email: ?string, phone: ?string, vehicle: ?string, password: string}  $data
     */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => Str::lower($data['username']),
                'email' => $data['email'],
                'phone' => $data['phone'],
                'agency_id' => $data['agency_id'],
                'password' => $data['password'],
                'must_change_password' => true,
            ]);

            $user->assignRole($data['role']->value);

            if ($data['vehicle'] !== null) {
                [$model, $plate] = self::splitVehicle($data['vehicle']);
                $user->vehicle()->create(['model' => $model, 'plate' => $plate]);
            }

            return $user;
        });

        $context = ['user_id' => $user->id, 'role' => $data['role']->value];

        if ($data['role'] === Role::Administrator) {
            $this->security->warning(SecurityLog::USER_CREATED, $context);
            $this->alerts->send(SecurityLog::USER_CREATED, ['by' => (string) Auth::user()?->name, 'name' => $user->name, 'username' => $user->username]);
        } else {
            $this->security->info(SecurityLog::USER_CREATED, $context);
        }

        return $user;
    }

    /**
     * "Clio - AB-123-CD" → ["Clio", "AB-123-CD"], split on the last " - " (legacy R17).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitVehicle(string $vehicle): array
    {
        $vehicle = trim($vehicle);
        $position = strrpos($vehicle, ' - ');

        if ($position === false) {
            return [$vehicle === '' ? null : $vehicle, null];
        }

        return [trim(substr($vehicle, 0, $position)) ?: null, trim(substr($vehicle, $position + 3)) ?: null];
    }
}
