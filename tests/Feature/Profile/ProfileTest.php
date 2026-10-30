<?php

namespace Tests\Feature\Profile;

use App\Enums\Module;
use App\Enums\Role;
use App\Enums\UserDocumentType;
use App\Livewire\Profile\ProfileModal;
use App\Models\StoredFile;
use App\Models\User;
use App\Settings\ModuleSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['password' => 'old-password-1', 'must_change_password' => false]);
    }

    public function test_person_updates_own_contact_details(): void
    {
        Livewire::actingAs($this->technician)->test(ProfileModal::class)
            ->call('openModal')
            ->set('email', ' new@example.test ')->set('phone', '0102030405')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('toast');

        $this->technician->refresh();
        $this->assertSame('new@example.test', $this->technician->email);
        $this->assertSame('0102030405', $this->technician->phone);
    }

    public function test_the_profile_explains_the_use_of_personal_data(): void
    {
        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->assertSee(__('profile.privacy.title'))
            ->assertSee(__('profile.privacy.location', ['months' => 12]))
            ->assertSee(__('profile.privacy.copy'));

        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(ProfileModal::class)->call('openModal')
            ->assertSee(__('profile.privacy.copy'))
            ->assertDontSee(__('profile.privacy.location', ['months' => 12]));
    }

    public function test_the_profile_tells_what_the_ai_and_the_client_portal_receive(): void
    {
        config(['services.anthropic.key' => '']);
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_diff($settings->enabled, [Module::ClientPortal->value]));
        $settings->save();

        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->assertDontSee(__('profile.privacy.ai'))
            ->assertDontSee(__('profile.privacy.portal'));

        config(['services.anthropic.key' => 'test-key']);
        $settings->enabled = [...$settings->enabled, Module::ClientPortal->value];
        $settings->save();

        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->assertSee(__('profile.privacy.ai'))
            ->assertSee(__('profile.privacy.portal'));
    }

    public function test_the_ai_notice_opens_the_profile_privacy_block(): void
    {
        Livewire::actingAs($this->technician)->test(ProfileModal::class)
            ->assertSet('open', false)
            ->dispatch('open-profile')
            ->assertSet('open', true)
            ->assertSee(__('profile.privacy.title'));
    }

    public function test_email_must_stay_unique(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.test']);

        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->set('email', $other->email)->call('save')->assertHasErrors('email');
    }

    public function test_new_password_needs_the_current_one_and_the_minimum_length(): void
    {
        $component = Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->set('newPassword', 'a-brand-new-pass')->set('currentPassword', 'wrong')->call('save')->assertHasErrors('currentPassword')
            ->set('currentPassword', 'old-password-1')->set('newPassword', 'short')->call('save')->assertHasErrors('newPassword');

        $component->set('newPassword', 'a-brand-new-pass')->call('save')->assertHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-pass', (string) $this->technician->fresh()?->password));
    }

    public function test_other_sessions_are_signed_out_after_a_password_change(): void
    {
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->technician->id, 'payload' => '', 'last_activity' => time()]);

        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->set('currentPassword', 'old-password-1')->set('newPassword', 'a-brand-new-pass')->call('save');

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    public function test_documents_are_stored_once_and_replaced(): void
    {
        $component = Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->set('btpCard', UploadedFile::fake()->createWithContent('card.pdf', "%PDF-1.4\nfirst"))
            ->set('drivingLicence', UploadedFile::fake()->image('licence.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->technician->documents()->count());
        $this->assertSame(2, StoredFile::query()->count());

        $component->call('openModal')->set('btpCard', UploadedFile::fake()->createWithContent('card2.pdf', "%PDF-1.4\nsecond"))->call('save');

        $this->assertSame(2, $this->technician->documents()->count());
        $this->assertSame('card2.pdf', $this->technician->documents()->where('type', UserDocumentType::BtpCard->value)->sole()->original_name);
        $this->assertSame(2, StoredFile::query()->count());
    }

    public function test_dangerous_uploads_are_refused(): void
    {
        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->set('btpCard', UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'))
            ->call('save')
            ->assertHasErrors('btpCard')
            ->set('btpCard', UploadedFile::fake()->createWithContent('card.pdf', '<html><script>alert(1)</script></html>'))
            ->call('save')
            ->assertHasErrors('btpCard');

        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_documents_are_private_to_owner_and_managers(): void
    {
        Livewire::actingAs($this->technician)->test(ProfileModal::class)->call('openModal')
            ->set('btpCard', UploadedFile::fake()->createWithContent('card.pdf', "%PDF-1.4\nbody"))->call('save');

        $document = $this->technician->documents()->sole();

        $this->actingAs($this->technician)->get(route('user-documents.show', $document))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('user-documents.show', $document))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('user-documents.show', $document))->assertNotFound();
        auth()->logout();
        $this->get(route('user-documents.show', $document))->assertRedirect();
    }
}
