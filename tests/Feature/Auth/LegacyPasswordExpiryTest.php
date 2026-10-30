<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyPasswordExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_legacy_passwords_get_ninety_days(): void
    {
        $user = User::factory()->withLegacyPassword('old-secret')->create();

        $this->artisan('users:expire-legacy-passwords')->assertSuccessful();

        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), $user->fresh()?->legacy_password_expires_at?->getTimestamp(), 5);
        $this->assertNotNull($user->fresh()?->legacy_password_hash);
    }

    public function test_unused_legacy_passwords_are_erased_after_ninety_days(): void
    {
        $expired = User::factory()->withLegacyPassword('old-secret')->create(['username' => 'late']);
        $expired->forceFill(['legacy_password_expires_at' => now()->subMinute()])->save();
        $valid = User::factory()->withLegacyPassword('old-secret')->create();
        $valid->forceFill(['legacy_password_expires_at' => now()->addDay()])->save();

        $this->artisan('users:expire-legacy-passwords')->assertSuccessful();

        $this->assertNull($expired->fresh()?->legacy_password_hash);
        $this->assertNull($expired->fresh()?->legacy_password_expires_at);
        $this->assertNotNull($valid->fresh()?->legacy_password_hash);

        $this->post('/login', ['username' => 'late', 'password' => 'old-secret'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_expired_legacy_password_is_refused_even_before_the_daily_clean_up(): void
    {
        $user = User::factory()->withLegacyPassword('old-secret')->create(['username' => 'late']);
        $user->forceFill(['legacy_password_expires_at' => now()->subMinute()])->save();

        $this->post('/login', ['username' => 'late', 'password' => 'old-secret'])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_legacy_password_still_valid_is_upgraded(): void
    {
        $user = User::factory()->withLegacyPassword('old-secret')->create(['username' => 'early']);
        $user->forceFill(['legacy_password_expires_at' => now()->addDays(10)])->save();

        $this->post('/login', ['username' => 'early', 'password' => 'old-secret'])->assertRedirect('/');

        $this->assertNull($user->fresh()?->legacy_password_hash);
        $this->assertNull($user->fresh()?->legacy_password_expires_at);
    }
}
