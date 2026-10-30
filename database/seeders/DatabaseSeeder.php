<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Activitylog\Support\ActivityLogStatus;

class DatabaseSeeder extends Seeder
{
    /**
     * Base data needed by every installation (production included): roles, the starting public holidays, the built-in
     * catalogues of the design office workflow and the initial administrator
     * taken from the ADMIN_* environment values. Demo data is only added on
     * local and demo environments.
     */
    public function run(): void
    {
        app(ActivityLogStatus::class)->disable();

        $this->call([RoleSeeder::class, PublicHolidaySeeder::class, DesignOfficeCatalogueSeeder::class, InitialAdministratorSeeder::class]);

        if (app()->environment(DemoSeeder::ENVIRONMENTS)) {
            $this->call(DemoSeeder::class);
        }
    }
}
