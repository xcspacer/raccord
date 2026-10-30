<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => null,
            'agency_id' => null,
            'company_id' => null,
            'departure_date' => null,
        ];
    }

    /**
     * Account imported from the legacy platform: only the legacy "salt:sha256" password is known.
     */
    public function withLegacyPassword(string $plainPassword): static
    {
        $salt = Str::random(16);

        return $this->state(fn (array $attributes) => [
            'password' => null,
            'legacy_password_hash' => $salt.':'.hash('sha256', $salt.':'.$plainPassword),
        ]);
    }

    public function departed(): static
    {
        return $this->state(fn (array $attributes) => [
            'departure_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }
}
