<?php

namespace Tests\Feature\Platform;

use App\Actions\Files\StoreUploadedFile;
use App\Actions\Users\AnonymiseUser;
use App\Enums\Role;
use App\Enums\UserDocumentType;
use App\Enums\VehiclePhotoSlot;
use App\Models\ExpenseReport;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\User;
use App\Models\UserDocument;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Retention of personal data (D4, RGPD-04/05/07): people who left are anonymised 12 months after their departure,
 * their personal documents erased after 3 months, presences on boxes after 7 days, the journal after 24 months.
 */
class PersonalDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
    }

    private function document(User $user, string $contents = 'card'): UserDocument
    {
        $file = app(StoreUploadedFile::class)->handleContents($contents, 'application/pdf', 'pdf');

        return UserDocument::query()->create(['user_id' => $user->id, 'type' => UserDocumentType::BtpCard, 'stored_file_id' => $file->id, 'original_name' => 'card.pdf']);
    }

    private function vehiclePhoto(User $user, CarbonInterface $takenAt): VehiclePhoto
    {
        $file = app(StoreUploadedFile::class)->handleContents('photo-'.$takenAt->getTimestamp(), 'image/jpeg', 'jpg');

        return VehiclePhoto::query()->create([
            'user_id' => $user->id, 'slot' => VehiclePhotoSlot::Front, 'stored_file_id' => $file->id,
            'month' => $takenAt->format('Y-m'), 'taken_at' => $takenAt, 'latitude' => 43.9298, 'longitude' => 2.148,
        ]);
    }

    public function test_the_retention_commands_are_scheduled_with_the_configured_periods(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

        $this->assertStringContainsString('users:purge-departed', $commands);
        $this->assertStringContainsString('audits:purge-presences', $commands);
        $this->assertStringContainsString('punches:purge-locations', $commands);
        $this->assertStringContainsString('vehicle-photos:purge-locations', $commands);
        $this->assertStringContainsString('ai:purge-usage', $commands);
        $this->assertMatchesRegularExpression('/activitylog:clean.*--days=730/', $commands);

        $this->assertSame(12, config('platform.departed_user_anonymisation_months'));
        $this->assertSame(3, config('platform.departed_user_documents_months'));
        $this->assertSame(7, config('platform.presence_retention_days'));
        $this->assertSame(12, config('platform.location_retention_months'));
        $this->assertSame(90, config('platform.ai_usage_retention_days'));
    }

    public function test_people_who_left_a_year_ago_are_anonymised_and_keep_their_work_history(): void
    {
        $left = User::factory()->withRole(Role::Technician)->create(['name' => 'Jane Leaver', 'username' => 'jleaver', 'email' => 'jane@example.test', 'phone' => '0600000000', 'locale' => 'fr']);
        $left->update(['departure_date' => '2025-11-03']);
        $recent = User::factory()->withRole(Role::Technician)->create(['name' => 'Recent Leaver', 'departure_date' => '2025-11-05']);
        $task = Task::factory()->create(['user_id' => $left->id]);
        Vehicle::query()->create(['user_id' => $left->id, 'model' => 'Van', 'plate' => 'AB-123-CD']);
        $photo = $this->vehiclePhoto($left, now()->subMonth());
        $this->document($left);
        $report = ExpenseReport::query()->create(['user_id' => $left->id, 'month' => '2025-10', 'name' => 'Jane Leaver', 'total_cents' => 1200, 'lines_count' => 1, 'stored_file_id' => app(StoreUploadedFile::class)->handleContents('pdf', 'application/pdf', 'pdf')->id, 'created_at' => now()]);

        $this->artisan('users:purge-departed')->assertSuccessful();

        $left->refresh();
        $label = __('users.anonymised_name', ['number' => $left->id], config('app.locale'));
        $this->assertSame($label, $left->name);
        $this->assertSame('former-'.$left->id, $left->username);
        $this->assertNull($left->email);
        $this->assertNull($left->phone);
        $this->assertNull($left->locale);
        $this->assertNull($left->password);
        $this->assertNotNull($left->anonymised_at);
        $this->assertSame(0, Vehicle::query()->withTrashed()->where('user_id', $left->id)->count());
        $this->assertSame(0, UserDocument::query()->withTrashed()->where('user_id', $left->id)->count());
        $this->assertModelExists($photo);
        $this->assertNull($photo->fresh()?->latitude);
        $this->assertNull($photo->fresh()?->longitude);

        $this->assertSame($left->id, $task->fresh()?->user_id);
        $this->assertModelExists($report);
        $this->assertSame('Jane Leaver', $report->fresh()?->name);

        $this->assertNull($recent->fresh()?->anonymised_at);
        $this->assertSame('Recent Leaver', $recent->fresh()?->name);

        $this->post(route('login'), ['username' => 'jleaver', 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_copies_of_the_name_and_the_journal_lose_the_person(): void
    {
        $actor = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Jane Leaver', 'email' => 'jane@example.test']);
        $actor->update(['email' => 'jane.new@example.test']);
        $other = User::factory()->withRole(Role::Technician)->create(['name' => 'Other Person']);

        $item = DB::table('inventory_items')->insertGetId(['label' => 'Splicer', 'status' => 'assigned', 'anomaly' => json_encode(['kind' => 'lost', 'at' => now()->toIso8601String(), 'by' => $actor->id, 'by_name' => 'Jane Leaver', 'comment' => '']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_movements')->insert([
            ['inventory_item_id' => $item, 'type' => 'transfer', 'user_id' => $actor->id, 'user_name' => 'Jane Leaver', 'label' => 'Splicer', 'from_user_id' => $actor->id, 'from_name' => 'Jane Leaver', 'to_user_id' => $other->id, 'to_name' => 'Other Person', 'created_at' => now()],
        ]);
        $audit = DB::table('audits')->insertGetId(['cabinet_key' => 'PM1', 'validated_by' => $actor->id, 'validated_by_name' => 'Jane Leaver', 'finished_by' => $other->id, 'finished_by_name' => 'Other Person', 'created_at' => now(), 'updated_at' => now()]);

        $actor->update(['departure_date' => '2025-10-01']);
        $activitiesBefore = Activity::query()->count();

        $this->artisan('users:purge-departed')->assertSuccessful();

        $label = __('users.anonymised_name', ['number' => $actor->id], config('app.locale'));
        $movement = DB::table('inventory_movements')->first();
        $this->assertNotNull($movement);
        $this->assertSame($label, $movement->user_name);
        $this->assertSame($label, $movement->from_name);
        $this->assertSame('Other Person', $movement->to_name);
        $this->assertSame($label, json_decode((string) DB::table('inventory_items')->value('anomaly'), true)['by_name']);
        $this->assertSame($label, DB::table('audits')->where('id', $audit)->value('validated_by_name'));
        $this->assertSame('Other Person', DB::table('audits')->where('id', $audit)->value('finished_by_name'));

        $this->assertSame($activitiesBefore, Activity::query()->count(), 'The anonymisation itself is not journaled.');
        $journal = Activity::query()->where('subject_type', $actor->getMorphClass())->where('subject_id', $actor->id)->get()->toJson();
        $this->assertStringNotContainsString('Jane Leaver', $journal);
        $this->assertStringNotContainsString('jane@example.test', $journal);
        $this->assertStringNotContainsString('jane.new@example.test', $journal);
    }

    public function test_every_name_copy_points_at_existing_columns(): void
    {
        foreach (AnonymiseUser::NAME_COPIES as $table => $columns) {
            foreach ($columns as $nameColumn => $userColumn) {
                $this->assertTrue(Schema::hasColumns($table, [$nameColumn, $userColumn]), "{$table}.{$nameColumn} / {$userColumn}");
            }
        }
    }

    public function test_a_deleted_account_without_departure_counts_from_its_deletion(): void
    {
        $deleted = User::factory()->withRole(Role::Technician)->create(['name' => 'Deleted Person']);
        $deleted->delete();
        $deleted->forceFill(['deleted_at' => now()->subMonths(13)])->saveQuietly();
        $recentlyDeleted = User::factory()->withRole(Role::Technician)->create(['name' => 'Recently Deleted']);
        $recentlyDeleted->delete();

        $this->artisan('users:purge-departed')->assertSuccessful();

        $this->assertNotNull(User::withTrashed()->find($deleted->id)?->anonymised_at);
        $this->assertNull(User::withTrashed()->find($recentlyDeleted->id)?->anonymised_at);
    }

    public function test_personal_documents_are_erased_three_months_after_the_departure_and_shared_contents_stay(): void
    {
        $left = User::factory()->withRole(Role::Technician)->create(['departure_date' => '2026-08-03']);
        $staying = User::factory()->withRole(Role::Technician)->create(['departure_date' => '2026-08-05']);
        $colleague = User::factory()->withRole(Role::Technician)->create();
        $document = $this->document($left, 'same scan');
        $trashed = $this->document($left, 'old scan');
        $trashed->delete();
        $shared = $this->document($colleague, 'same scan');
        $kept = $this->document($staying, 'kept scan');

        $this->artisan('users:purge-departed')->assertSuccessful();

        $this->assertModelMissing($document);
        $this->assertModelMissing($trashed);
        $this->assertModelExists($kept);
        $this->assertModelExists($shared);
        $this->assertNull($left->fresh()?->anonymised_at, 'The account itself is only anonymised after 12 months.');

        $this->travel(3)->days();
        $this->artisan('files:purge-orphans')->assertSuccessful();

        $this->assertModelExists($shared->file);
        $this->assertSame(1, $shared->file->fresh()?->reference_count);
        $this->assertNull(StoredFile::query()->find($trashed->stored_file_id));
        Storage::disk('local')->assertExists($shared->file->path);
    }

    public function test_presences_on_boxes_are_erased_after_seven_days(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();
        $audit = DB::table('audits')->insertGetId(['cabinet_key' => 'PM1', 'created_at' => now(), 'updated_at' => now()]);
        $box = DB::table('audit_boxes')->insertGetId(['audit_id' => $audit, 'name' => 'BOX-1', 'kind' => 'pbo', 'source' => 'plan']);
        $other = User::factory()->withRole(Role::Technician)->create();
        DB::table('audit_presences')->insert([
            ['audit_id' => $audit, 'user_id' => $user->id, 'audit_box_id' => $box, 'state' => 'on_box', 'latitude' => 43.9, 'longitude' => 2.1, 'since' => now()->subDays(9), 'seen_at' => now()->subDays(8)],
            ['audit_id' => $audit, 'user_id' => $other->id, 'audit_box_id' => $box, 'state' => 'on_box', 'latitude' => 43.9, 'longitude' => 2.1, 'since' => now()->subDays(2), 'seen_at' => now()->subDays(2)],
        ]);

        $this->artisan('audits:purge-presences')->assertSuccessful();

        $this->assertSame([$other->id], DB::table('audit_presences')->pluck('user_id')->all());
    }

    public function test_vehicle_photo_positions_are_erased_after_twelve_months_and_the_photos_stay(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();
        $old = $this->vehiclePhoto($user, now()->subMonths(12)->subDay());
        $recent = $this->vehiclePhoto($user, now()->subMonths(11));

        $this->artisan('vehicle-photos:purge-locations')->assertSuccessful();

        $this->assertModelExists($old);
        $this->assertNull($old->fresh()?->latitude);
        $this->assertNull($old->fresh()?->longitude);
        $this->assertSame($old->stored_file_id, $old->fresh()?->stored_file_id);
        $this->assertNotNull($recent->fresh()?->latitude);
    }

    public function test_ai_usage_rows_are_erased_after_ninety_days(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();
        DB::table('ai_usage')->insert([
            ['user_id' => $user->id, 'feature' => 'receipt_read', 'created_at' => now()->subDays(91)],
            ['user_id' => $user->id, 'feature' => 'receipt_read', 'created_at' => now()->subDays(89)],
            ['user_id' => $user->id, 'feature' => 'receipt_read', 'created_at' => now()->subMinutes(5)],
        ]);

        $this->artisan('ai:purge-usage')->assertSuccessful();
        $this->assertSame(2, DB::table('ai_usage')->count());

        config(['platform.ai_usage_retention_days' => 0]);
        $this->artisan('ai:purge-usage')->assertSuccessful();
        $this->assertSame(1, DB::table('ai_usage')->count(), 'The rows the running quotas count are never erased.');
    }
}
