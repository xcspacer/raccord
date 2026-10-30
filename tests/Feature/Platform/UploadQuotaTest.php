<?php

namespace Tests\Feature\Platform;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Profile\ProfileModal;
use App\Models\Agency;
use App\Models\MediaFile;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Daily upload quota per person (UPL-11): over it, files are refused with a translated message until the next day.
 */
class UploadQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private string $date = '2026-10-29';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->lead = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $site = WorkSite::factory()->create(['agency_id' => $agency->id, 'cabinet_ref' => 'PM401', 'project_type' => null, 'lead_technician_id' => $this->lead->id]);
        Task::factory()->create(['user_id' => $this->lead->id, 'date' => $this->date, 'work_site_id' => $site->id]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function send(User $user, ?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($user)->postJson(route('task-photos.store'), [
            'date' => $this->date, 'target' => 'PM', 'phase' => 'before', 'file' => $file ?? UploadedFile::fake()->image('p.jpg', 400, 300),
        ]);
    }

    public function test_photos_over_the_daily_count_are_refused_until_the_next_day(): void
    {
        config(['platform.upload_daily_files' => 2]);

        $this->send($this->lead)->assertOk();
        $this->send($this->lead)->assertOk();
        $this->send($this->lead)->assertStatus(429)
            ->assertJson(['ok' => false, 'error' => 'upload_quota', 'message' => __('errors.upload_quota')])
            ->assertHeader('Retry-After', (string) (14 * 3600));
        $this->assertSame(2, MediaFile::query()->count());

        $this->send(User::factory()->withRole(Role::Technician)->create())->assertForbidden();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 00:01', 'Europe/Paris'));
        $this->date = '2026-10-30';
        Task::query()->update(['date' => $this->date]);
        $this->send($this->lead)->assertOk();
    }

    public function test_the_daily_volume_is_counted_per_person(): void
    {
        config(['platform.upload_daily_megabytes' => 1]);

        $this->send($this->lead, UploadedFile::fake()->create('p.jpg', 1100))->assertStatus(429)->assertJsonPath('error', 'upload_quota');
        $this->send($this->lead)->assertOk();
    }

    public function test_livewire_uploads_over_the_quota_show_the_message_under_the_field(): void
    {
        config(['platform.upload_daily_files' => 1]);
        Storage::fake('tmp-for-tests');
        $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);

        $this->actingAs($this->lead)->post($url, ['files' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'paths');

        $response = $this->actingAs($this->lead)->post($url, ['files' => [UploadedFile::fake()->image('b.jpg')]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame([__('errors.upload_quota')], $response->json('errors')['files.0']);

        Livewire::actingAs($this->lead)->test(ProfileModal::class)
            ->call('_uploadErrored', 'btpCard', $response->getContent(), false)
            ->assertHasErrors(['btpCard' => __('errors.upload_quota')]);
    }
}
