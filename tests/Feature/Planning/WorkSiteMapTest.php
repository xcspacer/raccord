<?php

namespace Tests\Feature\Planning;

use App\Audit\OpenAudit;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Enums\WorkSiteStatus;
use App\Models\Agency;
use App\Models\GeocodeCache;
use App\Models\User;
use App\Models\WorkSite;
use App\Planning\Geocoder;
use App\Planning\MapCountries;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkSiteMapTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        config(['services.geocoder.driver' => 'address-fr']);
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Detailed]);
        $this->supervisor = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id]);
    }

    private function feature(string $city, float $lat, float $lon, float $score = 0.9): array
    {
        return ['features' => [['geometry' => ['coordinates' => [$lon, $lat]], 'properties' => ['city' => $city, 'score' => $score]]]];
    }

    private function site(string $city, string $from = '2026-11-02', string $to = '2026-11-06', WorkSiteStatus $status = WorkSiteStatus::Confirmed): WorkSite
    {
        return WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => $city, 'starts_on' => $from, 'ends_on' => $to, 'status' => $status]);
    }

    public function test_town_keys_ignore_accents_case_and_parentheses(): void
    {
        $this->assertSame('saint etienne', Geocoder::key('Saint-Étienne (42)'));
    }

    public function test_lookup_accepts_a_similar_name_and_remembers_it(): void
    {
        Http::fake(['*' => Http::response($this->feature('Dijon', 43.93, 2.14))]);

        $found = app(Geocoder::class)->lookup('Dijon');
        $this->assertTrue($found?->found);

        app(Geocoder::class)->lookup('dijon');
        Http::assertSentCount(1);
    }

    public function test_lookup_refuses_low_scores_and_unrelated_names_and_remembers_the_failure(): void
    {
        Http::fake(['*' => Http::response($this->feature('Dijon', 43.93, 2.14, 0.3))]);
        $this->assertFalse(app(Geocoder::class)->lookup('Dijon')?->found);

        Http::fake(['*' => Http::response($this->feature('Marseille', 43.3, 5.4))]);
        $this->assertFalse(app(Geocoder::class)->lookup('Sans nom')?->found);
        $this->assertSame(2, GeocodeCache::query()->where('found', false)->count());
    }

    public function test_composite_names_are_tried_part_by_part(): void
    {
        Http::fake([
            '*' => Http::sequence()->push(['features' => []])->push($this->feature('Tours', 44.35, 2.57)),
        ]);

        $this->assertTrue(app(Geocoder::class)->lookup('Tours et Dijon')?->found);
    }

    public function test_network_failures_are_not_remembered(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertNull(app(Geocoder::class)->lookup('Dijon'));
        $this->assertDatabaseCount('geocode_cache', 0);
    }

    public function test_map_groups_work_sites_by_place_and_splits_unplaced_ones(): void
    {
        Http::fake([
            '*q=Dijon*' => Http::response($this->feature('Dijon', 43.93, 2.14)),
            '*q=Ajaccio*' => Http::response($this->feature('Ajaccio', 41.92, 8.74)),
            '*q=Nowhereville*' => Http::response(['features' => []]),
        ]);
        $this->site('Dijon');
        $this->site('Dijon', '2026-12-01', '2026-12-02', WorkSiteStatus::Forecast);
        $this->site('Ajaccio');
        $this->site('Nowhereville');
        $this->site('Cancelled Town', status: WorkSiteStatus::Cancelled);
        $this->site('Past Town', '2026-09-01', '2026-09-05');

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites'))->assertOk()
            ->assertJsonPath('pending', 0)
            ->assertJsonCount(1, 'pins')
            ->assertJsonPath('pins.0.name', 'Dijon')
            ->assertJsonCount(2, 'pins.0.items')
            ->assertJsonPath('pins.0.running', true)
            ->assertJsonPath('pins.0.confirmed', true)
            ->assertJsonPath('offMap.0.city', 'Ajaccio')
            ->assertJsonPath('unlocated.0.city', 'Nowhereville');
    }

    public function test_the_all_mode_includes_finished_work_sites_but_never_cancelled_ones(): void
    {
        Http::fake(['*' => Http::response($this->feature('Dijon', 43.93, 2.14))]);
        $this->site('Dijon', '2026-09-01', '2026-09-05');
        $this->site('Dijon', status: WorkSiteStatus::Cancelled);

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites', ['mode' => 'current']))->assertJsonPath('total', 0);
        $this->actingAs($this->supervisor)->getJson(route('map.work-sites', ['mode' => 'all']))->assertJsonPath('total', 1);
    }

    public function test_audits_are_labelled_by_cabinet(): void
    {
        Http::fake(['*' => Http::response($this->feature('Dijon', 43.93, 2.14))]);
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => 'Dijon', 'project_type' => ProjectType::Audit, 'cabinet_ref' => 'PM319', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'status' => WorkSiteStatus::Confirmed]);

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites'))->assertJsonPath('pins.0.items.0.label', 'Audit PM319');
    }

    public function test_audits_without_a_known_town_are_placed_on_their_cabinet(): void
    {
        Http::fake(['*' => Http::response(['features' => []])]);
        $audit = fn (string $city, string $cabinet) => WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => $city, 'project_type' => ProjectType::Audit, 'cabinet_ref' => $cabinet, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'status' => WorkSiteStatus::Confirmed]);
        $audit('', 'pm319');
        $audit('', 'PM319');
        $audit('Nowhereville', 'PM319');
        $audit('', 'PM400');
        app(OpenAudit::class)->handle($this->supervisor, 'PM319')->forceFill(['cabinet_latitude' => 43.93, 'cabinet_longitude' => 2.14])->save();
        app(OpenAudit::class)->handle($this->supervisor, 'PM400');

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites'))->assertOk()
            ->assertJsonCount(1, 'pins')
            ->assertJsonPath('pins.0.lat', 43.93)
            ->assertJsonCount(3, 'pins.0.items')
            ->assertJsonPath('pins.0.items.0.label', 'Audit pm319')
            ->assertJsonCount(1, 'unlocated')
            ->assertJsonPath('unlocated.0.city', 'Audit PM400');
    }

    public function test_audits_without_a_town_nor_a_known_cabinet_are_listed_as_not_located(): void
    {
        Http::fake(['*' => Http::response(['features' => []])]);
        $audit = fn (string $city, ?string $cabinet) => WorkSite::factory()->create(['agency_id' => $this->agency->id, 'city' => $city, 'project_type' => ProjectType::Audit, 'cabinet_ref' => $cabinet, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'status' => WorkSiteStatus::Confirmed]);
        $audit('', null);
        $audit('', 'PM500');
        $this->site('');

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites'))->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(0, 'pins')
            ->assertJsonCount(2, 'unlocated')
            ->assertJsonFragment(['city' => 'Audit', 'count' => 1])
            ->assertJsonFragment(['city' => 'Audit PM500', 'count' => 1]);
    }

    public function test_only_managers_get_the_map_data_and_the_home_map(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);

        $this->actingAs($technician)->getJson(route('map.work-sites'))->assertForbidden();
        $this->actingAs($technician)->get(route('home'))->assertOk()->assertDontSee('data-work-site-map', false);
        $this->actingAs($this->supervisor)->get(route('home'))->assertOk()->assertSee('data-work-site-map', false);
    }

    public function test_guests_are_redirected(): void
    {
        $this->getJson(route('map.work-sites'))->assertUnauthorized();
    }

    public function test_the_map_follows_the_country_of_the_work_sites(): void
    {
        config(['services.geocoder.driver' => 'nominatim']);
        Http::fake([
            '*q=Porto*' => Http::response([['name' => 'Porto', 'lat' => '41.1496', 'lon' => '-8.6110']]),
            '*q=Lisboa*' => Http::response([['name' => 'Lisboa', 'lat' => '38.7167', 'lon' => '-9.1333']]),
            '*q=Dijon*' => Http::response([['name' => 'Dijon', 'lat' => '43.9298', 'lon' => '2.1480']]),
        ]);
        $this->site('Porto');
        $this->site('Lisboa');
        $this->site('Dijon');

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites'))->assertOk()
            ->assertJsonPath('country', 'pt')
            ->assertJsonCount(2, 'pins')
            ->assertJsonPath('offMap.0.city', 'Dijon');
    }

    public function test_every_country_map_places_its_own_cities_and_follows_the_data(): void
    {
        $countries = app(MapCountries::class);

        $this->assertSame('fr', $countries->countryOf(48.8566, 2.3522));
        $this->assertSame('pt', $countries->countryOf(38.7223, -9.1393));
        $this->assertSame('es', $countries->countryOf(40.4168, -3.7038));
        $this->assertSame('ua', $countries->countryOf(50.4501, 30.5234));
        $this->assertNull($countries->countryOf(41.9192, 8.7386));
        $this->assertNull($countries->countryOf(28.1235, -15.4363));
        $this->assertSame('es', $countries->detect([['lat' => 40.4168, 'lon' => -3.7038, 'weight' => 3], ['lat' => 38.7223, 'lon' => -9.1393, 'weight' => 1]]));
        $this->assertSame('fr', $countries->detect([]));
    }

    public function test_running_work_sites_follow_the_paris_date_just_after_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 00:30', 'Europe/Paris'));
        Http::fake(['*' => Http::response($this->feature('Dijon', 43.93, 2.14))]);
        $this->site('Dijon', '2026-11-04', '2026-11-04');

        $this->actingAs($this->supervisor)->getJson(route('map.work-sites'))->assertJsonPath('pins.0.running', true);
    }
}
