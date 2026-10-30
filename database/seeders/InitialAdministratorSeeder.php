<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates the first administrator of an installation from the ADMIN_* environment values, only when
 * no administrator exists yet. The password has to be changed at the first sign-in.
 */
class InitialAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        if (User::role(Role::Administrator->value)->exists()) {
            return;
        }

        /** @var array{name: ?string, username: ?string, email: ?string, password: ?string} $admin */
        $admin = config('platform.initial_admin');

        $validator = Validator::make($admin, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash:ascii'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->command?->warn('Initial administrator not created: '.implode(' ', $validator->errors()->all()));

            return;
        }

        $user = User::query()->create([
            'name' => $admin['name'],
            'username' => Str::lower((string) $admin['username']),
            'email' => $admin['email'],
            'password' => $admin['password'],
            'must_change_password' => true,
        ]);

        $user->assignRole(Role::Administrator->value);

        $this->command?->info("Initial administrator \"{$user->username}\" created; the password must be changed at the first sign-in.");
    }
}
