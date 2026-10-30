<?php

namespace Tests\Feature\Platform;

use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_uses_the_app_name_until_a_company_name_is_set(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', config('app.name'))
            ->assertJsonPath('icons.2.purpose', 'maskable');

        $settings = app(CompanySettings::class);
        $settings->name = 'Fibre Works';
        $settings->save();

        $this->get('/manifest.webmanifest')->assertJsonPath('name', 'Fibre Works');
    }

    public function test_pages_link_the_generic_icons_and_social_image(): void
    {
        $this->get('/login')
            ->assertSee('favicon.ico', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('og-image.png', false)
            ->assertSee('manifest.webmanifest', false);
    }

    public function test_the_home_screen_title_comes_from_the_settings(): void
    {
        $this->get('/login')->assertSee('<meta name="apple-mobile-web-app-title" content="'.e(config('app.name')).'">', false);

        $settings = app(CompanySettings::class);
        $settings->name = 'Fibre Works';
        $settings->save();

        $this->get('/login')->assertSee('<meta name="apple-mobile-web-app-title" content="Fibre Works">', false);
    }

    public function test_logo_route_is_not_found_without_a_configured_logo(): void
    {
        $this->get(route('branding.logo'))->assertNotFound();
        $this->get('/login')->assertDontSee('login-card__logo', false);
    }

    public function test_configured_logo_is_served_and_shown_on_login(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('logo.png')->store('branding', 'local');

        $settings = app(CompanySettings::class);
        $settings->logo_path = $path;
        $settings->save();

        $this->get(route('branding.logo'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/login')->assertSee('login-card__logo', false);
    }
}
