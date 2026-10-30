<?php

namespace Tests\Feature\Platform;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Livewire end points: rate limited, uploads never previewed as SVG, and the state sent to the browser with every
 * screen (the component snapshots) never carries password hashes, tokens or colleagues' contact details (LW-04,
 * LW-07, TST-07).
 */
class LivewireSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages opened by every role; the ones a role may not open are skipped.
     *
     * @var list<string>
     */
    private const array PAGES = [
        'home', 'planning', 'planning.tasks', 'work-sites.index', 'work-sites.create', 'absences.index', 'technician-space',
        'equipment.index', 'expense-reports', 'audits.full', 'audits.optical', 'audits.catalog', 'audits.weekly-timesheet',
        'media-library', 'media-library.audits', 'billing', 'client-control', 'fibre-code', 'otdr',
        'users.index', 'access.index', 'agencies.index', 'companies.index', 'holidays.index', 'logs.index', 'platform-settings',
    ];

    public function test_svg_files_are_never_previewed_and_uploads_have_their_own_rate_limit(): void
    {
        $this->assertNotContains('svg', config('livewire.temporary_file_upload.preview_mimes'));
        $this->assertSame(['throttle:livewire-uploads', 'upload.quota'], config('livewire.temporary_file_upload.middleware'));
        $this->assertContains('throttle:livewire-uploads', Route::getRoutes()->getByName('livewire.upload-file')?->gatherMiddleware() ?? []);
        $this->assertContains('upload.quota', Route::getRoutes()->getByName('livewire.upload-file')?->gatherMiddleware() ?? []);
    }

    public function test_the_update_end_point_is_rate_limited_per_person(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->withRole(Role::Technician)->create();
        $other = User::factory()->withRole(Role::Technician)->create();
        RateLimiter::for('livewire-updates', fn ($request) => Limit::perMinute(2)->by('user:'.$request->user()?->id));

        $this->assertContains('throttle:livewire-updates', Route::getRoutes()->getByName('livewire.update')?->middleware() ?? []);

        $status = fn (User $as): int => $this->actingAs($as)->withHeader('X-Livewire', '1')->postJson(route('livewire.update'), ['components' => []])->getStatusCode();

        $this->assertNotSame(429, $status($user));
        $this->assertNotSame(429, $status($user));
        $this->assertSame(429, $status($user));
        $this->assertNotSame(429, $status($other));
    }

    public function test_screens_never_send_password_hashes_tokens_or_colleagues_contact_details(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);

        $colleague = User::factory()->withRole(Role::Technician)->create([
            'agency_id' => $agency->id, 'name' => 'Colleague', 'email' => 'colleague.private@example.test', 'phone' => '+33 6 99 88 77 66',
            'legacy_password_hash' => 'legacysalt:'.str_repeat('ab', 32), 'is_plannable' => true,
        ]);
        $colleague->forceFill(['remember_token' => 'leaky-remember-token-0123456789'])->save();

        $viewers = [];

        foreach (Role::cases() as $role) {
            $viewers[$role->value] = User::factory()->withRole($role)->create(['agency_id' => $agency->id, 'is_plannable' => $role === Role::Technician]);
        }

        Task::factory()->create(['user_id' => $colleague->id, 'date' => '2026-11-04', 'label' => 'Splicing']);
        Task::factory()->create(['user_id' => $viewers[Role::Technician->value]->id, 'date' => '2026-11-04', 'label' => 'Survey']);

        $secrets = User::query()->get()->flatMap(fn (User $user) => array_filter([$user->getAuthPassword(), $user->getRememberToken(), $user->legacy_password_hash]))->all();
        $opened = 0;

        foreach ($viewers as $role => $viewer) {
            foreach (self::PAGES as $page) {
                $response = $this->actingAs($viewer)->get(route($page));

                if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                    continue;
                }

                $opened++;
                $html = (string) $response->getContent();
                $snapshots = $this->snapshots($html);

                foreach ($secrets as $secret) {
                    $this->assertStringNotContainsString($secret, $html, "« {$page} » shows a secret to the {$role}.");
                }

                $this->assertStringNotContainsString('$2y$', $snapshots, "« {$page} » sends a password hash to the {$role}.");

                if ($role === Role::Technician->value) {
                    $this->assertStringNotContainsString('colleague.private@example.test', $snapshots, "« {$page} » sends a colleague's e-mail to a technician.");
                    $this->assertStringNotContainsString('99 88 77 66', $snapshots, "« {$page} » sends a colleague's phone number to a technician.");
                }
            }
        }

        $this->assertGreaterThan(20, $opened);
    }

    /**
     * Every component snapshot written into the page, decoded.
     */
    private function snapshots(string $html): string
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        return implode("\n", array_map(fn (string $snapshot) => html_entity_decode($snapshot, ENT_QUOTES | ENT_HTML5), $matches[1]));
    }
}
