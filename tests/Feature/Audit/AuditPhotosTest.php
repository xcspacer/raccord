<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\Audit\AuditPhotoStore;
use App\Audit\AuditTree;
use App\Audit\OpenAudit;
use App\Enums\AuditMode;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditPhotosTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private User $partner;

    private Audit $audit;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);

        Storage::fake('local');
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice']);
        $this->partner = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Bruno']);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
        $site = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);
        $site->members()->attach($this->partner->id, ['role' => 'partner']);
        $this->url = route('audits.photos.store', ['cabinet' => 'PM153']);
        $this->operation($this->manager, ['op' => 'box_add', 'name' => 'BPE-0001']);
    }

    private function operation(User $user, array $op): void
    {
        $this->actingAs($user);
        app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function upload(User $user, array $fields = [], ?string $id = null)
    {
        return $this->actingAs($user)->post($this->url, $fields + [
            'operation_id' => $id ?? 'o_'.bin2hex(random_bytes(5)), 'box' => 'BPE-0001', 'folder' => 'closed',
            'file' => UploadedFile::fake()->image('shot.jpg', 3200, 2400),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_photo_is_stored_downscaled_with_its_position_and_author(): void
    {
        $revision = Audit::query()->find($this->audit->id)->revision;
        $response = $this->upload($this->technician, ['latitude' => 43.9298123456, 'longitude' => 2.148, 'captured_at' => now()->subMinute()->toIso8601String()])->assertOk()->assertJson(['ok' => true, 'duplicate' => false]);

        $photo = AuditPhoto::query()->findOrFail($response->json('photo'));
        $this->assertSame('closed', $photo->folder);
        $this->assertSame('', $photo->subfolder);
        $this->assertSame('43.9298123', $photo->latitude);
        $this->assertSame('Alice', $photo->user_name);
        $this->assertFalse($photo->is_imported);
        $this->assertSame(2560, $photo->file->width);
        Storage::disk('local')->assertExists([$photo->file->path, $photo->file->thumbnail_path]);
        $this->assertSame($revision + 1, Audit::query()->find($this->audit->id)->revision);
    }

    public function test_the_same_operation_does_not_store_the_photo_twice(): void
    {
        $this->upload($this->technician, [], 'o_photo1')->assertOk();
        $again = $this->upload($this->technician, [], 'o_photo1')->assertOk()->assertJson(['duplicate' => true]);

        $this->assertNull($again->json('photo'));
        $this->assertSame(1, AuditPhoto::query()->count());
        $this->assertSame(1, StoredFile::query()->sole()->reference_count);
    }

    public function test_an_operation_id_reused_for_another_photo_is_refused_and_ids_belong_to_each_person(): void
    {
        $this->upload($this->technician, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600)], 'o_photo2')->assertOk();
        $this->upload($this->technician, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600), 'queued' => 1], 'o_photo2')->assertOk()->assertJson(['duplicate' => true]);
        $this->upload($this->technician, ['file' => UploadedFile::fake()->image('b.jpg', 640, 480)], 'o_photo2')->assertStatus(422)->assertJson(['ok' => false, 'error' => 'operation_id_reused']);
        $this->assertSame(1, AuditPhoto::query()->count());

        $this->upload($this->partner, ['file' => UploadedFile::fake()->image('b.jpg', 640, 480)], 'o_photo2')->assertOk()->assertJson(['duplicate' => false]);
        $this->assertSame(2, AuditPhoto::query()->count());
    }

    public function test_photos_from_old_phone_code_or_too_large_are_refused(): void
    {
        $this->upload($this->technician, [], null)->assertOk();
        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, '0');
        $this->upload($this->technician)->assertStatus(426)->assertJson(['error' => 'update_required']);

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);
        $this->upload($this->technician, ['file' => UploadedFile::fake()->create('big.jpg', 12289, 'image/jpeg')])->assertStatus(422);
        $this->assertSame(1, AuditPhoto::query()->count());
    }

    public function test_folders_and_sub_folders_follow_the_mode_and_the_kind_of_box(): void
    {
        $this->upload($this->technician, ['folder' => 'nonsense'])->assertStatus(422)->assertJson(['error' => 'bad_folder']);
        $this->upload($this->technician, ['folder' => 'before'])->assertOk();
        $this->upload($this->technician, ['folder' => 'recheck'])->assertOk();
        $this->upload($this->technician, ['folder' => 'tco'])->assertStatus(422);
        $this->upload($this->technician, ['folder' => 'storage', 'subfolder' => 'IGNORED'])->assertOk();
        $this->assertSame('', AuditPhoto::query()->where('folder', 'storage')->sole()->subfolder);

        $this->operation($this->technician, ['op' => 'box_set', 'box' => 'BPE-0001', 'field' => 'support', 'value' => 'pole']);
        $this->upload($this->technician, ['folder' => 'pole'])->assertStatus(422)->assertJson(['error' => 'bad_subfolder']);
        $this->upload($this->technician, ['folder' => 'pole', 'subfolder' => 'roof'])->assertStatus(422);
        $this->upload($this->technician, ['folder' => 'pole', 'subfolder' => 'HEAD'])->assertOk();
        $this->assertSame('head', AuditPhoto::query()->where('folder', 'pole')->sole()->subfolder);

        $this->upload($this->technician, ['box' => 'BPE-9999'])->assertNotFound();
    }

    public function test_the_optical_mode_only_takes_its_own_folders_and_labels_by_cable(): void
    {
        $this->operation($this->manager, ['op' => 'setup', 'mode' => 'optical']);
        $this->assertSame(AuditMode::Optical, $this->audit->refresh()->mode);
        $this->upload($this->technician, ['folder' => 'entries'])->assertStatus(422);
        $this->upload($this->technician, ['folder' => 'labels', 'subfolder' => 'CAB1X'])->assertOk();
        $this->upload($this->technician, ['folder' => 'labels', 'subfolder' => 'x'])->assertOk();
        $this->assertSame(['', 'CAB1X'], AuditPhoto::query()->where('folder', 'labels')->orderBy('subfolder')->pluck('subfolder')->all());
    }

    public function test_control_point_photos_use_their_sub_folders(): void
    {
        $cabinet = $this->audit->controlPoints()->where('kind', 'cabinet')->first();
        $chamber = $this->audit->controlPoints()->where('name', 'Satellite chamber')->first();

        $this->upload($this->technician, ['box' => null, 'folder' => null, 'point' => $cabinet->id, 'subfolder' => 'before'])->assertOk();
        $this->upload($this->technician, ['box' => null, 'point' => $cabinet->id])->assertStatus(422)->assertJson(['error' => 'bad_subfolder']);
        $this->upload($this->technician, ['box' => null, 'point' => $chamber->id])->assertOk();
        $this->upload($this->technician, ['box' => null, 'point' => $chamber->id, 'subfolder' => 'before'])->assertStatus(422);
        $this->upload($this->technician, ['box' => null, 'point' => 99999])->assertNotFound();

        $this->assertSame(2, $cabinet->photos()->count() + $chamber->photos()->count());
        $this->assertSame('before', $cabinet->photos()->sole()->subfolder);
    }

    public function test_validated_audits_refuse_technician_photos_and_finished_ones_are_reopened(): void
    {
        $this->operation($this->technician, ['op' => 'finish']);
        $this->assertTrue($this->audit->refresh()->isFinished());
        $this->upload($this->partner)->assertOk();
        $this->assertFalse($this->audit->refresh()->isFinished());

        $this->operation($this->technician, ['op' => 'finish']);
        $this->operation($this->manager, ['op' => 'validate']);
        $this->upload($this->technician)->assertStatus(409)->assertJson(['error' => 'validated']);
        $this->upload($this->manager)->assertOk();
        $this->assertSame(1, AuditPhoto::query()->where('user_id', $this->technician->id)->orWhere('user_id', $this->partner->id)->count());
    }

    public function test_only_managers_import_photos_and_imports_carry_no_position(): void
    {
        $this->upload($this->technician, ['imported' => 1])->assertForbidden();

        $id = $this->upload($this->manager, ['imported' => 1, 'latitude' => 43.9, 'longitude' => 2.1])->assertOk()->json('photo');
        $photo = AuditPhoto::query()->findOrFail($id);
        $this->assertTrue($photo->is_imported);
        $this->assertNull($photo->latitude);
    }

    public function test_bad_uploads_and_dates_are_handled(): void
    {
        $this->upload($this->technician, ['file' => UploadedFile::fake()->create('doc.txt', 5, 'text/plain')])->assertStatus(415)->assertJson(['error' => 'invalid_image']);
        $this->upload($this->technician, ['file' => UploadedFile::fake()->createWithContent('shot.jpg', '<html><script>alert(1)</script></html>')])->assertStatus(415)->assertJson(['error' => 'invalid_image']);
        $this->upload($this->technician, ['file' => UploadedFile::fake()->image('shot.gif')])->assertStatus(415)->assertJson(['error' => 'invalid_image']);
        $this->upload($this->technician, ['file' => null])->assertStatus(422);
        $this->upload($this->technician, ['operation_id' => 'bad id!'])->assertStatus(422);
        $this->assertSame(0, AuditPhoto::query()->count());

        $this->freezeSecond();
        $future = AuditPhoto::query()->find($this->upload($this->technician, ['captured_at' => now()->addDay()->toIso8601String()])->json('photo'));
        $old = AuditPhoto::query()->find($this->upload($this->technician, ['captured_at' => now()->subYear()->toIso8601String()])->json('photo'));
        $tooOld = AuditPhoto::query()->find($this->upload($this->technician, ['captured_at' => now()->subDays(AuditPhotoStore::CAPTURE_TRUSTED_DAYS)->subMinute()->toIso8601String()])->json('photo'));
        $garbage = AuditPhoto::query()->find($this->upload($this->technician, ['captured_at' => 'yesterday-ish'])->json('photo'));

        foreach ([$future, $old, $tooOld, $garbage] as $photo) {
            $this->assertTrue($photo->captured_at->equalTo(now()));
            $this->assertTrue($photo->received_at->equalTo(now()));
        }
    }

    public function test_a_photo_sent_later_from_offline_keeps_the_time_the_phone_took_it(): void
    {
        config(['platform.timezone' => 'Europe/Paris']);
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:20:30', 'UTC'));
        $takenOffline = now()->subDays(6)->subHours(23)->startOfSecond();
        $photo = AuditPhoto::query()->find($this->upload($this->technician, ['captured_at' => $takenOffline->toIso8601String()])->json('photo'));

        $this->assertTrue($photo->captured_at->equalTo($takenOffline));
        $this->assertTrue($photo->received_at->equalTo(now()));
        $paths = collect(app(AuditTree::class)->boxEntries($this->audit->refresh(), $photo->box()->with('photos.file')->firstOrFail()))->pluck('path');
        $this->assertContains('20260715-102030_'.$photo->id.'.jpg', $paths->map(fn (string $path) => basename($path))->all(), 'The name keeps the server (UTC) time of reception.');
    }

    public function test_technicians_delete_only_their_own_photos_until_the_audit_is_finished(): void
    {
        $mine = $this->upload($this->technician)->json('photo');
        $theirs = $this->upload($this->partner)->json('photo');
        $delete = function (User $user, int $photo) {
            $this->actingAs($user);

            try {
                app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), ['op' => 'photo_del', 'photo' => $photo]);
            } catch (AuditOperationException $exception) {
                return $exception->reason.':'.$exception->status;
            }

            return 'ok';
        };

        $this->assertSame('forbidden:403', $delete($this->technician, $theirs));
        $this->assertSame('ok', $delete($this->technician, 0));

        $this->operation($this->technician, ['op' => 'finish']);
        $this->assertSame('forbidden:403', $delete($this->technician, $mine));

        $this->assertSame('ok', $delete($this->manager, $theirs));
        $this->assertNull(AuditPhoto::query()->find($theirs));
        $this->assertSame(1, AuditPhoto::query()->count());

        $this->operation($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'partial']);
        $this->assertSame('ok', $delete($this->technician, $mine));
        $this->assertSame(0, AuditPhoto::query()->count());
        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_a_manager_moves_photos_to_another_folder_of_the_same_box(): void
    {
        $first = $this->upload($this->technician)->json('photo');
        $second = $this->upload($this->technician)->json('photo');

        $this->operation($this->manager, ['op' => 'photo_move', 'box' => 'BPE-0001', 'folder' => 'open', 'photos' => [$first]]);
        $this->assertSame('open', AuditPhoto::query()->find($first)->folder);
        $this->assertSame('closed', AuditPhoto::query()->find($second)->folder);

        foreach ([['photos' => []], ['photos' => [$first], 'folder' => 'tco']] as $extra) {
            try {
                $this->operation($this->manager, $extra + ['op' => 'photo_move', 'box' => 'BPE-0001', 'folder' => 'open']);
                $this->fail('Expected a refusal');
            } catch (AuditOperationException $exception) {
                $this->assertContains($exception->reason, ['no_photos', 'bad_folder']);
            }
        }

        $this->expectException(AuditOperationException::class);
        $this->operation($this->technician, ['op' => 'photo_move', 'box' => 'BPE-0001', 'folder' => 'open', 'photos' => [$second]]);
    }

    public function test_photos_are_visible_only_to_the_people_working_on_the_audit(): void
    {
        $id = $this->upload($this->technician)->json('photo');
        $full = route('audits.photos.show', ['photo' => $id, 'variant' => 'full']);
        $thumb = route('audits.photos.show', ['photo' => $id, 'variant' => 'thumb']);

        $this->actingAs($this->partner)->get($full)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->manager)->get($thumb)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get($full)->assertNotFound();
        auth()->logout();
        $this->get($full)->assertRedirect(route('login'));

        $this->actingAs($this->technician)->post($this->url, ['operation_id' => 'o_x'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->post($this->url, ['operation_id' => 'o_y', 'box' => 'BPE-0001', 'folder' => 'closed', 'file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->technician)->post(route('audits.photos.store', ['cabinet' => 'PM999']), ['operation_id' => 'o_z'], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_imported_photos_keep_the_date_of_their_file_while_site_photos_are_clamped(): void
    {
        $old = now()->subMonths(8)->toIso8601String();

        $imported = $this->upload($this->manager, ['imported' => 1, 'captured_at' => $old])->json('photo');
        $onSite = $this->upload($this->technician, ['captured_at' => $old])->json('photo');

        $this->assertTrue(AuditPhoto::query()->find($imported)->captured_at->isBefore(now()->subMonths(7)));
        $this->assertTrue(AuditPhoto::query()->find($onSite)->captured_at->isAfter(now()->subMinute()));
    }
}
