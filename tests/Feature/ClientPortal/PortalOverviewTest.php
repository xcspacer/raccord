<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\OpenAudit;
use App\ClientPortal\PortalCharts;
use App\ClientPortal\PortalOverview;
use App\Enums\Role;
use App\Livewire\ClientPortal\PortalPage;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPresence;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\StoredFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PortalOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Audit $audit;

    private User $technician;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->now = CarbonImmutable::parse('2026-10-29 10:00:00');
        $this->travelTo($this->now);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'ALICE martin']);
        $this->audit = app(OpenAudit::class)->handle(User::factory()->withRole(Role::Administrator)->create(), 'PM1');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function box(string $name, array $attributes = []): AuditBox
    {
        return $this->audit->boxes()->create($attributes + ['name' => $name, 'kind' => 'bpe', 'source' => 'manager', 'support' => 'chamber', 'latitude' => 43.9298, 'longitude' => 2.148]);
    }

    private function photo(AuditBox $box, CarbonImmutable $at, ?float $latitude = 43.9298, bool $imported = false, ?CarbonImmutable $receivedAt = null): void
    {
        $file = StoredFile::query()->create(['sha256' => hash('sha256', uniqid('', true)), 'path' => 'p/'.uniqid(), 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);

        $box->photos()->create(['audit_id' => $this->audit->id, 'folder' => 'closed', 'stored_file_id' => $file->id, 'captured_at' => $at, 'latitude' => $latitude, 'longitude' => $latitude === null ? null : 2.148, 'user_name' => 'ALICE martin', 'is_imported' => $imported, 'received_at' => $receivedAt ?? $at]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $audits = Audit::query()->whereKey($this->audit->id)->get();
        app(PortalOverview::class)->load($audits);

        return app(PortalOverview::class)->of($audits->first(), $this->now);
    }

    public function test_the_portal_works_the_overview_out_once_per_poll_interval_unless_the_audit_changed(): void
    {
        $client = Client::factory()->create(['code' => 'ACME']);
        $account = ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane', 'has_all_audits' => false]);
        $account->audits()->sync([$this->audit->id]);
        $this->box('BPE-1');
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
        $this->app['auth']->forgetGuards();

        $page = Livewire::test(PortalPage::class, ['portalClient' => $client]);
        $total = fn (): int => $page->call('$refresh')->viewData('overviews')->first()['total'];
        $this->assertSame(1, $total());

        $this->box('BPE-2');
        $this->assertSame(1, $total());

        $this->audit->increment('revision');
        $this->assertSame(2, $total());

        $this->box('BPE-3');
        $this->travel(PortalPage::OVERVIEW_CACHE_SECONDS + 1)->seconds();
        $this->assertSame(3, $total());
    }

    public function test_the_cached_overview_keeps_its_dates_in_a_cache_store_that_serialises_values(): void
    {
        config(['cache.default' => 'database']);
        $client = Client::factory()->create(['code' => 'ACME']);
        $account = ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane', 'has_all_audits' => false]);
        $account->audits()->sync([$this->audit->id]);
        $this->photo($this->box('BPE-1'), $this->now->subHour());
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
        $this->app['auth']->forgetGuards();

        $page = Livewire::test(PortalPage::class, ['portalClient' => $client])->call('$refresh')->assertOk();

        $this->assertEquals($this->now->subHour(), $page->viewData('overviews')->first()['last_activity']);
    }

    public function test_statuses_supports_and_families_are_counted_as_the_client_sees_them(): void
    {
        $this->box('BPE-1', ['status' => 'ok', 'status_at' => $this->now->subDay()]);
        $this->box('BPE-2', ['status' => 'anomaly', 'reason' => 'Broken lid', 'support' => 'pole', 'status_at' => $this->now]);
        $this->box('BPE-3', ['status' => 'ok', 'returned_at' => $this->now, 'support' => null]);
        $this->box('BPE-4', ['status' => 'not_installed', 'kind' => 'pbo', 'status_at' => $this->now]);
        $this->box('BPE-5', ['removed_at' => $this->now]);
        $this->box('BPE-6', ['kind' => 'jd']);

        $overview = $this->overview();

        $this->assertSame(5, $overview['total']);
        $this->assertSame(3, $overview['done']);
        $this->assertSame(['ok' => 1, 'anomaly' => 1, 'not_installed' => 1, 'partial' => 1, 'todo' => 1], $overview['by_status']);
        $this->assertSame(1, $overview['by_support']['aerial']['total']);
        $this->assertSame(1, $overview['by_support']['unknown']['partial']);
        $this->assertSame(['total' => 3, 'done' => 2], array_intersect_key($overview['by_kind']['bpe'], ['total' => 0, 'done' => 0]));
        $this->assertSame(['2026-10-28' => 1, '2026-10-29' => 2], $overview['per_day']);
        $this->assertSame('Broken lid', $overview['anomalies'][0]['reason']);
    }

    public function test_a_crew_is_live_only_on_site_with_a_fresh_precise_position(): void
    {
        $near = $this->box('BPE-1');
        $far = $this->box('BPE-2');
        $stale = $this->box('BPE-3');
        $presence = fn (AuditBox $box, array $attributes) => AuditPresence::query()->create($attributes + ['audit_id' => $this->audit->id, 'user_id' => User::factory()->create()->id, 'audit_box_id' => $box->id, 'state' => 'on_box', 'latitude' => 43.9299, 'longitude' => 2.148, 'accuracy' => 20, 'since' => $this->now->subHour(), 'seen_at' => $this->now->subMinutes(5)]);

        $presence($near, ['user_id' => $this->technician->id]);
        $presence($far, ['latitude' => 43.95]);
        $presence($stale, ['seen_at' => $this->now->subMinutes(25)]);
        $presence($this->box('BPE-4'), ['accuracy' => 400]);
        $presence($this->box('BPE-5'), ['state' => 'en_route']);

        $live = $this->overview()['live'];

        $this->assertCount(1, $live);
        $this->assertSame(['box' => 'BPE-1', 'state' => 'on_box', 'name' => 'Alice'], array_intersect_key($live[0], ['box' => 0, 'state' => 0, 'name' => 0]));
    }

    public function test_a_recent_photo_taken_near_an_unfinished_box_shows_the_crew_on_site(): void
    {
        $this->photo($this->box('BPE-1'), $this->now->subMinutes(10));
        $this->photo($this->box('BPE-2', ['status' => 'ok']), $this->now->subMinutes(10));
        $this->photo($this->box('BPE-3'), $this->now->subMinutes(45), receivedAt: $this->now->subMinute());
        $this->photo($this->box('BPE-4'), $this->now->subMinutes(5), imported: true);
        $this->photo($this->box('BPE-5'), $this->now->subMinutes(5), latitude: null);

        $overview = $this->overview();

        $this->assertSame(['BPE-1'], array_column($overview['live'], 'box'));
        $this->assertSame('photo', $overview['live'][0]['state']);
        $this->assertCount(4, $overview['activity']);
        $this->assertNotContains('BPE-4', array_column($overview['activity'], 'box'));
    }

    public function test_production_chart_hides_idle_weekends_and_keeps_the_last_fourteen_days(): void
    {
        $chart = app(PortalCharts::class)->days(['2026-06-01' => 2, '2026-10-24' => 1], CarbonImmutable::parse('2026-10-29 10:00'));

        $this->assertCount(14, $chart['bars']);
        $this->assertSame('2026-10-29', end($chart['bars'])['day']);
        $this->assertContains('2026-10-24', array_column($chart['bars'], 'day'));
        $this->assertNotContains('2026-10-25', array_column($chart['bars'], 'day'));
        $this->assertNull(app(PortalCharts::class)->days([], CarbonImmutable::now()));
    }
}
