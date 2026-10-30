<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_choice_is_kept_in_the_session(): void
    {
        $this->post('/locale', ['locale' => 'es'])->assertRedirect();

        $this->assertSame('es', session('locale'));
    }

    public function test_signed_in_choice_is_saved_on_the_profile(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $this->actingAs($user)->post('/locale', ['locale' => 'pt'])->assertRedirect();

        $this->assertSame('pt', $user->refresh()->locale);
    }

    public function test_unsupported_language_is_rejected(): void
    {
        $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');

        $this->assertNull(session('locale'));
    }
}
