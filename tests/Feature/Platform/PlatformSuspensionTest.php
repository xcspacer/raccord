<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.enabled' => false]);
    }

    public function test_every_page_shows_the_contact_support_notice(): void
    {
        $this->get('/')
            ->assertStatus(503)
            ->assertSeeText('Platform unavailable')
            ->assertSeeText('Please contact support.');
    }

    public function test_notice_follows_the_browser_language(): void
    {
        $this->get('/', ['Accept-Language' => 'fr-FR'])
            ->assertStatus(503)
            ->assertSeeText('Plateforme indisponible');
    }

    public function test_login_is_blocked(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->post('/login', ['username' => 'jdoe', 'password' => 'password'])->assertStatus(503);

        $this->assertGuest();
    }

    public function test_json_requests_get_a_json_notice(): void
    {
        $this->getJson('/')->assertStatus(503)->assertJsonStructure(['message']);
    }

    public function test_language_can_still_be_changed(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'uk'])->assertRedirect('/');

        $this->get('/')->assertStatus(503)->assertSeeText('Платформа недоступна');
    }

    public function test_notice_does_not_reveal_support_contact_details(): void
    {
        $content = (string) $this->get('/')->getContent();

        $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/', $content);
        $this->assertStringNotContainsString('mailto:', $content);
        $this->assertStringNotContainsString('tel:', $content);
        $this->assertDoesNotMatchRegularExpression('#https?://(?!localhost)#', $content);
    }
}
