<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/locale', fn () => App::getLocale());
    }

    public function test_default_locale_is_british_english(): void
    {
        $this->get('/_test/locale', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertSeeText('en_GB');
    }

    public function test_browser_language_is_detected(): void
    {
        $this->get('/_test/locale', ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5'])->assertSeeText('fr');
        $this->get('/_test/locale', ['Accept-Language' => 'pt-BR'])->assertSeeText('pt');
        $this->get('/_test/locale', ['Accept-Language' => 'uk-UA'])->assertSeeText('uk');
    }

    public function test_user_choice_wins_over_browser_language(): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user)->get('/_test/locale', ['Accept-Language' => 'fr-FR'])->assertSeeText('es');
    }

    public function test_the_language_switcher_names_every_language_in_the_current_one(): void
    {
        $this->get(route('login'), ['Accept-Language' => 'fr-FR'])
            ->assertSeeInOrder(['Anglais', 'Français', 'Espagnol', 'Ukrainien', 'Portugais']);

        $this->get(route('login'), ['Accept-Language' => 'pt-PT'])
            ->assertSeeInOrder(['Inglês', 'Francês', 'Espanhol', 'Ucraniano', 'Português']);
    }

    public function test_the_chosen_language_is_remembered_by_a_cookie_also_on_pages_of_unknown_urls(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr'])->assertCookie('locale', 'fr', false);

        $this->withUnencryptedCookie('locale', 'fr')
            ->get('/admin/unknown-page', ['Accept-Language' => 'en-GB'])
            ->assertNotFound()
            ->assertSee(__('errors.404.title', [], 'fr'));

        $this->withUnencryptedCookie('locale', 'xx')
            ->get('/admin/unknown-page', ['Accept-Language' => 'es-ES'])
            ->assertSee(__('errors.404.title', [], 'es'));
    }

    public function test_the_signed_in_choice_refreshes_the_language_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'uk']);

        $this->actingAs($user)->withUnencryptedCookie('locale', 'fr')->get('/_test/locale')
            ->assertSeeText('uk')
            ->assertCookie('locale', 'uk', false);
    }

    public function test_unsupported_saved_locale_is_ignored(): void
    {
        $user = User::factory()->create(['locale' => 'xx']);

        $this->actingAs($user)->get('/_test/locale', ['Accept-Language' => 'fr-FR'])->assertSeeText('fr');
    }
}
