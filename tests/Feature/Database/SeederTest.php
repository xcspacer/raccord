<?php

namespace Tests\Feature\Database;

use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Enums\Role as RoleEnum;
use App\Models\Audit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.initial_admin' => [
            'name' => 'Owner Admin',
            'username' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-initial-password',
        ]]);
    }

    public function test_production_seed_creates_roles_and_the_initial_administrator_only(): void
    {
        $this->seedAs('production');

        $this->assertSame(4, Role::query()->count());
        $this->assertSame(1, User::query()->count());

        $admin = User::query()->where('username', 'owner')->firstOrFail();
        $this->assertTrue($admin->hasRole(RoleEnum::Administrator->value));
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check('a-long-initial-password', (string) $admin->password));
    }

    public function test_initial_administrator_is_not_created_twice(): void
    {
        $this->seedAs('production');
        $this->seedAs('production');

        $this->assertSame(1, User::query()->count());
    }

    public function test_initial_administrator_is_skipped_when_an_administrator_exists(): void
    {
        $this->seed(RoleSeeder::class);
        User::factory()->withRole(RoleEnum::Administrator)->create(['username' => 'existing']);

        $this->seedAs('production');

        $this->assertFalse(User::query()->where('username', 'owner')->exists());
    }

    public function test_incomplete_or_weak_admin_settings_create_nobody(): void
    {
        config(['platform.initial_admin.password' => 'short']);

        $this->seedAs('production');

        $this->assertSame(0, User::query()->count());
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        (new DemoSeeder)->run();
    }

    public function test_demo_seed_is_repeatable(): void
    {
        $this->seedAs('local');
        $this->seedAs('local');

        $this->assertSame(8, User::query()->count());
        $this->assertTrue(User::query()->where('username', 'admin')->firstOrFail()->hasRole(RoleEnum::Administrator->value));
    }

    public function test_demo_seed_has_a_closed_design_office_cabinet_with_a_client_occurrence_to_repair(): void
    {
        $this->seedAs('local');

        $audit = Audit::query()->where('cabinet_key', 'PM305')->firstOrFail();
        $occurrence = $audit->occurrences()->sole();

        $this->assertTrue($audit->isClosed());
        $this->assertSame(1, $audit->dossierVersions()->count());
        $this->assertSame(AuditOccurrenceSource::Client, $occurrence->source);
        $this->assertSame(AuditOccurrenceState::ToRepair, $occurrence->state);
        $this->assertSame(1, $audit->interventions()->count());
        $this->assertSame(0, $audit->reopenings()->count());
    }

    private function seedAs(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    }
}
