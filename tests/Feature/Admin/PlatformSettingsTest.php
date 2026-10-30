<?php

namespace Tests\Feature\Admin;

use App\Enums\Module;
use App\Enums\Role;
use App\Livewire\Admin\PlatformSettings;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
    }

    public function test_only_administrators_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('platform-settings'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('platform-settings'))->assertForbidden();
    }

    public function test_company_identity_is_saved_and_blank_fields_stay_empty(): void
    {
        Livewire::actingAs($this->admin)->test(PlatformSettings::class)
            ->set('name', 'Fibre Works')
            ->set('email', 'contact@example.test')
            ->set('phone', '   ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $company = app(CompanySettings::class);
        $this->assertSame('Fibre Works', $company->name);
        $this->assertSame('contact@example.test', $company->email);
        $this->assertNull($company->phone);
        $this->assertSame('Fibre Works', $company->displayName());
    }

    public function test_invalid_values_are_rejected(): void
    {
        Livewire::actingAs($this->admin)->test(PlatformSettings::class)
            ->set('email', 'not-an-email')
            ->set('website', 'http://insecure.example.test')
            ->call('save')
            ->assertHasErrors(['email', 'website']);
    }

    public function test_logo_is_stored_privately_and_replaces_the_previous_one(): void
    {
        Storage::fake('local');

        $component = Livewire::actingAs($this->admin)->test(PlatformSettings::class)
            ->set('logo', UploadedFile::fake()->image('first.png', 300, 100))
            ->call('save');
        $first = app(CompanySettings::class)->logo_path;

        $component->set('logo', UploadedFile::fake()->image('second.png', 300, 100))->call('save');
        $second = app(CompanySettings::class)->refresh()->logo_path;

        Storage::disk('local')->assertMissing((string) $first);
        Storage::disk('local')->assertExists((string) $second);
    }

    public function test_svg_logo_is_refused(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->admin)->test(PlatformSettings::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
            ->call('save')
            ->assertHasErrors('logo');
    }

    public function test_switching_a_module_off_blocks_its_pages(): void
    {
        Route::middleware(['web', 'module:fibre_code'])->get('/_test/fibre', fn () => 'ok');

        Livewire::actingAs($this->admin)->test(PlatformSettings::class)->call('toggleModule', Module::FibreCode->value);

        $this->assertFalse(app(ModuleSettings::class)->isEnabled(Module::FibreCode));
        $this->get('/_test/fibre')->assertNotFound();
    }

    public function test_non_administrator_cannot_call_the_actions(): void
    {
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())
            ->test(PlatformSettings::class)
            ->assertForbidden();
    }
}
