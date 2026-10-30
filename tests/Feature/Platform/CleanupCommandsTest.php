<?php

namespace Tests\Feature\Platform;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\UserDocumentType;
use App\Models\GeocodeCache;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\UserDocument;
use App\Models\WorkSite;
use App\Planning\Geocoder;
use App\Planning\ReverseGeocoder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Scheduled clean-ups: stored files nothing uses (UPL-12), deleted records past their retention, and the bounded
 * geocoding caches (SSRF-02).
 */
class CleanupCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_clean_ups_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

        $this->assertStringContainsString('files:purge-orphans', $commands);
        $this->assertStringContainsString('records:purge-deleted', $commands);
        $this->assertStringContainsString('geocoding:prune', $commands);
    }

    public function test_stored_files_nothing_uses_are_removed_after_the_grace_period_only(): void
    {
        $user = User::factory()->create();
        $files = app(StoreUploadedFile::class);

        $used = $files->handleContents('used', 'application/pdf', 'pdf');
        UserDocument::query()->create(['user_id' => $user->id, 'type' => UserDocumentType::BtpCard, 'stored_file_id' => $used->id, 'original_name' => 'card.pdf']);
        $orphan = $files->handleContents('orphan', 'application/pdf', 'pdf');
        $recent = $files->handleContents('recent', 'application/pdf', 'pdf');
        StoredFile::query()->whereKey([$used->id, $orphan->id])->update(['created_at' => now()->subDays(3)]);

        Storage::disk('local')->put('files/zz/stray.pdf', 'stray');
        touch(Storage::disk('local')->path('files/zz/stray.pdf'), now()->subDays(3)->getTimestamp());
        Storage::disk('local')->put('files/zz/fresh.pdf', 'fresh');
        Storage::disk('local')->put('branding/logo.png', 'logo');
        touch(Storage::disk('local')->path('branding/logo.png'), now()->subDays(3)->getTimestamp());
        touch(Storage::disk('local')->path($used->path), now()->subDays(3)->getTimestamp());

        $this->artisan('files:purge-orphans')->assertSuccessful();

        $this->assertModelExists($used);
        $this->assertModelMissing($orphan);
        $this->assertModelExists($recent);
        Storage::disk('local')->assertExists([$used->path, $recent->path, 'files/zz/fresh.pdf', 'branding/logo.png']);
        Storage::disk('local')->assertMissing([$orphan->path, 'files/zz/stray.pdf']);
    }

    public function test_deleted_records_stay_recoverable_twelve_months_by_default(): void
    {
        $this->assertSame(365, config('platform.deleted_records_retention_days'));

        $expired = WorkSite::factory()->create();
        $expired->delete();
        WorkSite::withTrashed()->whereKey($expired->id)->update(['deleted_at' => now()->subDays(366)]);
        $recoverable = WorkSite::factory()->create();
        $recoverable->delete();
        WorkSite::withTrashed()->whereKey($recoverable->id)->update(['deleted_at' => now()->subDays(360)]);

        $this->artisan('records:purge-deleted')->assertSuccessful();

        $this->assertNull(WorkSite::withTrashed()->find($expired->id));
        $this->assertNotNull(WorkSite::withTrashed()->find($recoverable->id));
    }

    public function test_deleted_records_are_kept_when_the_retention_is_switched_off_then_erased_after_it(): void
    {
        $old = WorkSite::factory()->create();
        $old->delete();
        WorkSite::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(40)]);
        $recent = WorkSite::factory()->create();
        $recent->delete();
        $kept = WorkSite::factory()->create();

        config(['platform.deleted_records_retention_days' => null]);
        $this->artisan('records:purge-deleted')->assertSuccessful();
        $this->assertSame(3, WorkSite::withTrashed()->count());

        config(['platform.deleted_records_retention_days' => 30]);
        $this->artisan('records:purge-deleted')->assertSuccessful();

        $this->assertNull(WorkSite::withTrashed()->find($old->id));
        $this->assertNotNull(WorkSite::withTrashed()->find($recent->id));
        $this->assertNotNull(WorkSite::query()->find($kept->id));
    }

    public function test_town_lookups_expire_and_the_cache_keeps_a_maximum_number_of_entries(): void
    {
        Http::fake();
        config(['services.geocoder.cache_days' => 100, 'services.geocoder.cache_max_entries' => 2]);

        GeocodeCache::query()->create(['query_key' => 'dijon', 'found' => true, 'latitude' => 43.9, 'longitude' => 2.1, 'checked_at' => now()->subDays(101)]);
        GeocodeCache::query()->create(['query_key' => 'nowhere', 'found' => false, 'checked_at' => now()->subDays(31)]);
        GeocodeCache::query()->create(['query_key' => 'nancy', 'found' => true, 'latitude' => 43.6, 'longitude' => 2.2, 'checked_at' => now()->subDays(50)]);
        GeocodeCache::query()->create(['query_key' => 'brest', 'found' => true, 'latitude' => 43.9, 'longitude' => 1.9, 'checked_at' => now()->subDays(20)]);
        GeocodeCache::query()->create(['query_key' => 'tours', 'found' => true, 'latitude' => 44.3, 'longitude' => 2.5, 'checked_at' => now()->subDays(1)]);

        $this->assertNull(app(Geocoder::class)->cached('Dijon'), 'An expired answer is looked up again.');
        $this->assertNotNull(app(Geocoder::class)->cached('Nancy'));

        $this->artisan('geocoding:prune')->assertSuccessful();

        $this->assertSame(['brest', 'tours'], GeocodeCache::query()->orderBy('query_key')->pluck('query_key')->all());
    }

    public function test_places_under_photos_expire_and_are_kept_per_language(): void
    {
        Http::fake(['*' => Http::response(['address' => ['city' => 'Dijon'], 'display_name' => 'Dijon, France'])]);
        config(['services.geocoder.cache_days' => 30, 'services.geocoder.cache_max_entries' => 10]);
        $places = app(ReverseGeocoder::class);

        $places->lookup(43.92, 2.14, 'fr');
        $places->lookup(43.92, 2.14, 'en_GB');
        $this->assertSame(2, DB::table('reverse_geocode_cache')->count());

        DB::table('reverse_geocode_cache')->where('locale', 'fr')->update(['checked_at' => now()->subDays(31)]);
        $places->lookup(43.92, 2.14, 'fr');
        Http::assertSentCount(3);

        DB::table('reverse_geocode_cache')->where('locale', 'fr')->update(['checked_at' => now()->subDays(31)]);
        $this->artisan('geocoding:prune')->assertSuccessful();
        $this->assertSame(['en_GB'], DB::table('reverse_geocode_cache')->pluck('locale')->all());
    }

    public function test_only_coarse_positions_are_sent_to_the_place_service(): void
    {
        Http::fake(['*' => Http::response(['address' => ['city' => 'Dijon'], 'display_name' => 'Dijon, France'])]);
        $places = app(ReverseGeocoder::class);

        $places->lookup(43.929812, 2.148367, 'fr');
        $places->lookup(43.929977, 2.148021, 'fr');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['lat'] === '43.930' && $request['lon'] === '2.148');
        $this->assertSame(['43.930,2.148'], DB::table('reverse_geocode_cache')->pluck('position_key')->all());
    }
}
