<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Livewire\Admin\CompanyIndex;
use App\Models\Company;
use App\Models\StoredFile;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\DocumentBrand;
use App\Support\PdfImage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
    }

    private function png(int $width, int $height, string $name = 'logo.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 20));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    public function test_only_administrators_manage_companies(): void
    {
        $company = Company::factory()->create();
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();

        $this->actingAs($this->admin)->get(route('companies.index'))->assertOk();
        $this->actingAs($supervisor)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('companies.logo', $company))->assertForbidden();
        Livewire::actingAs($supervisor)->test(CompanyIndex::class)->assertForbidden();
    }

    public function test_a_company_is_created_with_its_logo_and_a_unique_code(): void
    {
        Livewire::actingAs($this->admin)->test(CompanyIndex::class)
            ->set('name', 'North Networks')->set('logo', $this->png(400, 100))
            ->call('save')->assertHasNoErrors()
            ->set('name', 'North  Networks!')->call('save')->assertHasNoErrors();

        [$first, $second] = Company::query()->with('logo')->orderBy('id')->get()->all();
        $this->assertSame(['north-networks', 'north-networks-2'], [$first->code, $second->code]);
        $this->assertNotNull($first->logo);
        Storage::disk('local')->assertExists($first->logo->path);
        $this->assertNull($second->logo_file_id);

        $this->actingAs($this->admin)->get(route('companies.logo', $first))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->admin)->get(route('companies.logo', $second))->assertNotFound();
    }

    public function test_replacing_and_removing_the_logo_releases_the_old_file(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CompanyIndex::class)
            ->set('name', 'Acme Fibre')->set('logo', $this->png(300, 300))->call('save');

        $company = Company::query()->sole();
        $old = $company->logo;

        $component->call('edit', $company->id)->assertSet('name', 'Acme Fibre')
            ->set('logo', $this->png(600, 120, 'new.png'))->call('save')->assertHasNoErrors();

        $this->assertNotSame($old->id, $company->fresh()->logo_file_id);
        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing($old->path);

        $current = $company->fresh()->logo;
        $component->call('removeLogo', $company->id);

        $this->assertNull($company->fresh()->logo_file_id);
        $this->assertModelMissing($current);
    }

    public function test_invalid_input_is_refused(): void
    {
        Company::factory()->create(['name' => 'Taken']);

        Livewire::actingAs($this->admin)->test(CompanyIndex::class)
            ->set('name', '')->call('save')->assertHasErrors('name')
            ->set('name', 'Taken')->call('save')->assertHasErrors('name')
            ->set('name', 'New')->set('logo', UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))->call('save')->assertHasErrors('logo')
            ->set('logo', $this->png(50, 20))->call('save')->assertHasErrors('logo');

        $this->assertSame(1, Company::query()->count());
    }

    public function test_a_company_still_assigned_to_users_cannot_be_deleted(): void
    {
        $company = Company::factory()->create(['name' => 'Busy']);
        $user = User::factory()->withRole(Role::Technician)->create(['company_id' => $company->id]);

        Livewire::actingAs($this->admin)->test(CompanyIndex::class)->call('delete', $company->id)->assertHasErrors('delete');
        $this->assertNotSoftDeleted($company);

        $user->update(['company_id' => null]);
        Livewire::actingAs($this->admin)->test(CompanyIndex::class)->call('delete', $company->id)->assertHasNoErrors();
        $this->assertSoftDeleted($company);
    }

    public function test_documents_use_the_company_logo_with_the_platform_logo_as_fallback(): void
    {
        $platformLogo = $this->png(700, 183);
        Storage::disk('local')->put('branding/platform.png', $platformLogo->getContent());
        $settings = app(CompanySettings::class);
        $settings->logo_path = 'branding/platform.png';
        $settings->save();

        $logo = $this->png(573, 700);
        Storage::disk('local')->put('files/company.png', $logo->getContent());
        $file = StoredFile::query()->create(['sha256' => hash('sha256', 'company'), 'path' => 'files/company.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => 10]);
        $company = Company::factory()->create(['logo_file_id' => $file->id]);

        $brand = app(DocumentBrand::class);
        $withCompany = $brand->for(User::factory()->create(['company_id' => $company->id]));
        $withoutLogo = $brand->for(User::factory()->create(['company_id' => Company::factory()->create()->id]));

        $this->assertSame(PdfImage::logoDataUri('files/company.png'), $withCompany['logo']);
        $this->assertFalse($withCompany['wideLogo']);
        $this->assertSame(PdfImage::logoDataUri('branding/platform.png'), $withoutLogo['logo']);
        $this->assertTrue($withoutLogo['wideLogo']);
        $this->assertSame($settings->displayName(), $withCompany['company']);
    }
}
