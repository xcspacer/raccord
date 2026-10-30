<?php

namespace Tests\Feature\MediaLibrary;

use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Planning\TaskDetailModal;
use App\Models\Agency;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class TaskPhotosTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $boxTechnician;

    private WorkSite $site;

    private string $date = '2026-10-29';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->lead = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Lucas Moreau']);
        $this->boxTechnician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $this->site = WorkSite::factory()->create(['agency_id' => $agency->id, 'cabinet_ref' => 'PM401', 'project_type' => null, 'lead_technician_id' => $this->lead->id]);
        $this->site->boxes()->create(['reference' => 'BPE-41', 'technician_id' => $this->boxTechnician->id, 'position' => 0]);

        foreach ([$this->lead, $this->boxTechnician] as $user) {
            Task::factory()->create(['user_id' => $user->id, 'date' => $this->date, 'work_site_id' => $this->site->id]);
        }
    }

    private function send(User $user, string $target, string $phase = 'before'): TestResponse
    {
        return $this->actingAs($user)->postJson(route('task-photos.store'), [
            'date' => $this->date, 'target' => $target, 'phase' => $phase, 'file' => UploadedFile::fake()->image('p.jpg', 1200, 900),
        ]);
    }

    public function test_the_lead_sends_to_the_cabinet_and_the_box_technician_to_their_box_only(): void
    {
        config(['platform.timezone' => 'Europe/Paris']);
        $this->travelTo(CarbonImmutable::parse($this->date.' 10:20:30', 'UTC'));
        $this->send($this->lead, 'PM')->assertOk();
        $this->send($this->lead, 'BPE-41')->assertForbidden();
        $this->send($this->boxTechnician, 'BPE-41', 'after')->assertOk();
        $this->send($this->boxTechnician, 'PM')->assertForbidden();

        $cabinet = MediaFolder::query()->whereNull('parent_id')->sole();
        $this->assertSame('PM401', $cabinet->name);
        $this->assertSame(['BPE-41', 'PM'], $cabinet->children()->orderBy('name')->pluck('name')->all());
        $this->assertMatchesRegularExpression('/^20261029-102030_lucas_moreau_[a-z0-9]{8}\.jpg$/', MediaFile::query()->where('uploaded_by', $this->lead->id)->sole()->file_name);
    }

    public function test_only_photos_are_accepted_whatever_their_name(): void
    {
        $this->actingAs($this->lead)->postJson(route('task-photos.store'), [
            'date' => $this->date, 'target' => 'PM', 'phase' => 'before', 'file' => UploadedFile::fake()->createWithContent('photo.jpg', '<html><script>alert(1)</script></html>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, MediaFile::query()->count());
    }

    public function test_no_photos_on_audit_sites_or_detailed_planning_agencies(): void
    {
        $this->site->update(['project_type' => ProjectType::Audit]);
        $this->send($this->lead, 'PM')->assertForbidden();

        $this->site->update(['project_type' => null]);
        $this->site->agency->update(['planning_mode' => PlanningMode::Detailed]);
        $this->send($this->lead, 'PM')->assertForbidden();
    }

    public function test_a_technician_sees_their_folders_and_deletes_only_their_own_photos_within_fifteen_minutes(): void
    {
        $this->send($this->lead, 'PM')->assertOk();
        $own = MediaFile::query()->sole();
        $this->send($this->boxTechnician, 'BPE-41')->assertOk();
        $other = MediaFile::query()->whereKeyNot($own->id)->sole();

        $this->actingAs($this->lead)->get(route('task-photos.show', [$own->id, 'thumb']))->assertOk();
        $this->actingAs($this->lead)->get(route('task-photos.show', [$other->id, 'full']))->assertNotFound();

        $modal = Livewire::actingAs($this->lead)->test(TaskDetailModal::class)->call('openFor', $this->date)
            ->assertSee(__('media_library.cabinet_folder'));

        $this->travel(16)->minutes();
        $modal->call('deletePhoto', $own->id)->assertDispatched('toast');
        $this->assertModelExists($own);
        $this->travelBack();

        $modal->call('deletePhoto', $own->id);
        $this->assertModelMissing($own);

        Livewire::actingAs($this->lead)->test(TaskDetailModal::class)->call('openFor', $this->date)->call('deletePhoto', $other->id)->assertNotFound();
    }
}
