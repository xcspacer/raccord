<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Livewire\Profile\ProfileModal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FileViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_every_app_page_carries_the_translated_viewers(): void
    {
        $user = User::factory()->withRole(Role::Supervisor)->create(['locale' => 'fr']);

        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('data-file-viewer-template', false)
            ->assertSee('data-photo-viewer-template', false)
            ->assertSee('data-file-sheet-template', false)
            ->assertSee('← Retour')
            ->assertSee('⬆ Partager / Ouvrir dans…')
            ->assertSee("Enregistrer sur l'appareil");
    }

    public function test_a_current_document_can_be_viewed_or_downloaded(): void
    {
        Storage::fake('local');
        $technician = User::factory()->withRole(Role::Technician)->create(['must_change_password' => false]);

        $component = Livewire::actingAs($technician)->test(ProfileModal::class)->call('openModal')
            ->set('btpCard', UploadedFile::fake()->createWithContent('card.pdf', "%PDF-1.4\nbody"))->call('save')
            ->call('openModal');

        $url = route('user-documents.show', $technician->documents()->sole());
        $component->assertSeeHtml('data-file-view-open data-file-url="'.$url.'" data-file-name="card.pdf"')
            ->assertSeeHtml('<a href="'.$url.'" download="card.pdf">'.__('file_viewer.download').'</a>');
    }
}
