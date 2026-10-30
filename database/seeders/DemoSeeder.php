<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\Demo\AuditDemoSeeder;
use Database\Seeders\Demo\BillingDemoSeeder;
use Database\Seeders\Demo\ClientPortalDemoSeeder;
use Database\Seeders\Demo\DesignOfficeClientRepairDemoSeeder;
use Database\Seeders\Demo\DesignOfficeRepairsDemoSeeder;
use Database\Seeders\Demo\DesignOfficeWorkflowDemoSeeder;
use Database\Seeders\Demo\InventoryDemoSeeder;
use Database\Seeders\Demo\MediaLibraryDemoSeeder;
use Database\Seeders\Demo\PlanningDemoSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Invented data for local development and sales demos. Every module adds its own demo seeder here
 * as it is built. Never runs in production.
 */
class DemoSeeder extends Seeder
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'demo'];

    public const string PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            throw new RuntimeException('Demo data can only be seeded on local or demo environments.');
        }

        $this->demoUser('admin', 'Alex Martin', Role::Administrator);
        $this->demoUser('supervisor', 'Sam Dubois', Role::Supervisor);
        $this->demoUser('office', 'Camille Bernard', Role::DesignOffice);

        foreach (['tech1' => 'Lucas Moreau', 'tech2' => 'Hugo Petit', 'tech3' => 'Nina Laurent', 'tech4' => 'Ivan Kovalenko'] as $username => $name) {
            $this->demoUser($username, $name, Role::Technician);
        }

        $this->call(PlanningDemoSeeder::class);
        $this->call(InventoryDemoSeeder::class);
        $this->call(AuditDemoSeeder::class);
        $this->call(ClientPortalDemoSeeder::class);
        $this->call(MediaLibraryDemoSeeder::class);
        $this->call(BillingDemoSeeder::class);
        $this->call(DesignOfficeWorkflowDemoSeeder::class);
        $this->call(DesignOfficeRepairsDemoSeeder::class);
        $this->call(DesignOfficeClientRepairDemoSeeder::class);
    }

    private function demoUser(string $username, string $name, Role $role): User
    {
        $user = User::query()->updateOrCreate(
            ['username' => $username],
            ['name' => $name, 'email' => $username.'@example.test', 'password' => self::PASSWORD],
        );

        $user->syncRoles([$role->value]);

        return $user;
    }
}
