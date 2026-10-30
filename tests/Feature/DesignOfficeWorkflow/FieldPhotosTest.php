<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Actions\Files\ProcessImage;
use App\Actions\Files\StoreUploadedFile;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditGeoMissingReason;
use App\Enums\AuditInspectionState;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Jobs\GenerateThumbnail;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Photos of a PM in the design office workflow (spec 13 §36–38, 40, decision 4): who sends them, the required location
 * or its declared absence, original and reception times, no photo while the box is reviewed or once the PM is closed,
 * the same content received once, supervisor-only imports marked without GPS, and thumbnails made again in the
 * background when they could not be made at once.
 */
class FieldPhotosTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private AuditBox $target;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);
        $this->setUpWorkflow();
        $this->target = $this->box();
        app(BoxTeam::class)->claim($this->technician, $this->target);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function upload(User $user, array $fields = [], ?string $operationId = null, ?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($user)->post(route('audits.photos.store', ['cabinet' => $this->audit->cabinet_key]), $fields + [
            'operation_id' => $operationId ?? $this->operationId(), 'box' => $this->target->name, 'folder' => 'before',
            'latitude' => 45.12, 'longitude' => 1.5, 'captured_at' => now()->subMinutes(3)->toIso8601String(),
            'file' => $file ?? UploadedFile::fake()->image('shot.jpg', 1200, 900),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_team_photo_keeps_its_position_its_two_times_and_is_journaled(): void
    {
        $response = $this->upload($this->technician)->assertOk()->assertJson(['ok' => true, 'duplicate' => false]);

        $photo = AuditPhoto::query()->findOrFail($response->json('photo'));
        $this->assertSame('45.1200000', $photo->latitude);
        $this->assertFalse($photo->is_geo_missing);
        $this->assertNull($photo->geo_missing_reason);
        $this->assertTrue($photo->captured_at->lt($photo->received_at));
        $this->assertSame(1, Activity::query()->where('log_name', WorkflowJournal::LOG_NAME)->where('event', 'photo_received')->where('subject_id', $photo->id)->count());
        $this->assertTrue(app(WorkflowJournal::class)->verify()['intact']);
    }

    public function test_the_location_is_required_unless_the_phone_declares_no_signal(): void
    {
        $this->upload($this->technician, ['latitude' => '', 'longitude' => ''])->assertStatus(422)->assertJsonPath('error', 'location_required');
        $this->upload($this->technician, ['latitude' => '', 'longitude' => '', 'geo_missing_reason' => 'denied'])->assertStatus(422)->assertJsonPath('error', 'location_required');

        $response = $this->upload($this->technician, ['latitude' => '', 'longitude' => '', 'geo_missing_reason' => 'timeout'])->assertOk();

        $photo = AuditPhoto::query()->findOrFail($response->json('photo'));
        $this->assertTrue($photo->is_geo_missing);
        $this->assertSame(AuditGeoMissingReason::Timeout, $photo->geo_missing_reason);
        $this->assertNull($photo->latitude);
    }

    public function test_a_shooting_time_more_than_a_day_ahead_is_refused(): void
    {
        $this->upload($this->technician, ['captured_at' => now()->addHours(25)->toIso8601String()])->assertStatus(422)->assertJsonPath('error', 'capture_in_future');
        $this->upload($this->technician, ['captured_at' => now()->addHours(2)->toIso8601String()])->assertOk();
    }

    public function test_field_photos_come_from_the_team_of_the_box_or_a_supervisor_never_from_the_design_office(): void
    {
        $this->upload($this->reviewer)->assertForbidden()->assertJsonPath('error', 'reviewer_no_field_photos');
        $this->upload($this->partner)->assertForbidden()->assertJsonPath('error', 'not_in_team');

        app(BoxTeam::class)->claim($this->partner, $this->target);
        $this->upload($this->partner)->assertOk();
        $this->upload($this->supervisor)->assertOk();

        $stranger = $this->person(Role::Technician);
        $this->upload($stranger)->assertNotFound();
    }

    public function test_no_photo_enters_a_box_under_review_nor_a_closed_pm(): void
    {
        $this->target->forceFill(['inspection_state' => AuditInspectionState::Submitted])->save();
        $this->upload($this->technician)->assertStatus(409)->assertJsonPath('error', 'box_under_review');

        $this->target->forceFill(['inspection_state' => AuditInspectionState::Refused])->save();
        $this->upload($this->technician)->assertOk();

        $this->audit->forceFill(['closed_at' => now(), 'closed_by' => $this->supervisor->id])->save();
        $this->upload($this->technician)->assertStatus(409)->assertJsonPath('error', 'audit_closed');
    }

    public function test_the_same_content_is_received_once_and_a_resent_operation_answers_the_first(): void
    {
        $this->freezeTime();
        $file = UploadedFile::fake()->image('shot.jpg', 800, 600);
        $first = $this->upload($this->technician, [], 'op_same', $file)->assertOk()->json('photo');

        $this->upload($this->technician, [], 'op_same', $file)->assertOk()->assertJson(['duplicate' => true]);
        $this->upload($this->technician, [], 'op_other', $file)->assertOk()->assertJson(['duplicate' => true, 'photo' => $first]);

        $this->assertSame(1, AuditPhoto::query()->where('audit_box_id', $this->target->id)->count());
        $this->assertSame(1, StoredFile::query()->sole()->reference_count);
    }

    public function test_only_a_supervisor_imports_photos_and_they_are_marked_imported_without_gps(): void
    {
        $this->upload($this->technician, ['imported' => 1])->assertForbidden();
        $this->upload($this->reviewer, ['imported' => 1])->assertForbidden()->assertJsonPath('error', 'reviewer_no_field_photos');

        $response = $this->upload($this->supervisor, ['imported' => 1, 'captured_at' => now()->subYear()->toIso8601String()])->assertOk();

        $photo = AuditPhoto::query()->findOrFail($response->json('photo'));
        $this->assertTrue($photo->is_imported);
        $this->assertTrue($photo->is_geo_missing);
        $this->assertSame(AuditGeoMissingReason::Imported, $photo->geo_missing_reason);
        $this->assertNull($photo->latitude);
        $this->assertSame('Imported — no GPS', $photo->geo_missing_reason->label());
    }

    public function test_a_standard_audit_keeps_its_own_rules(): void
    {
        $standard = $this->plannedAudit([$this->technician], designOffice: false);
        $box = $this->box($standard);

        $this->actingAs($this->technician)->post(route('audits.photos.store', ['cabinet' => $standard->cabinet_key]), [
            'operation_id' => $this->operationId(), 'box' => $box->name, 'folder' => 'before', 'file' => UploadedFile::fake()->image('shot.jpg', 400, 300),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertFalse(AuditPhoto::query()->where('audit_box_id', $box->id)->sole()->is_geo_missing);
    }

    public function test_a_thumbnail_that_cannot_be_made_is_made_again_in_the_background(): void
    {
        Queue::fake();
        $this->mock(ProcessImage::class, function ($mock): void {
            $mock->shouldReceive('handle')->andReturn(['jpeg' => $this->jpeg(), 'thumbnail' => null, 'width' => 64, 'height' => 48]);
        });

        $stored = app(StoreUploadedFile::class)->handleImage(UploadedFile::fake()->image('shot.jpg', 64, 48));

        $this->assertNull($stored->thumbnail_path);
        Storage::disk('local')->assertExists($stored->path);
        Queue::assertPushed(GenerateThumbnail::class, fn (GenerateThumbnail $job) => $job->storedFileId === $stored->id);

        (new GenerateThumbnail($stored->id))->handle(new ProcessImage);

        $stored->refresh();
        $this->assertNotNull($stored->thumbnail_path);
        Storage::disk('local')->assertExists($stored->thumbnail_path);
        $this->assertSame(3, (new GenerateThumbnail($stored->id))->tries);
    }

    public function test_a_photo_without_thumbnail_is_shown_full_size_meanwhile(): void
    {
        $photo = AuditPhoto::query()->findOrFail($this->upload($this->technician)->json('photo'));
        Storage::disk('local')->delete((string) $photo->file->thumbnail_path);
        $photo->file->forceFill(['thumbnail_path' => null])->save();

        $this->actingAs($this->technician)->get(route('audits.photos.show', [$photo->id, 'thumb']))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
