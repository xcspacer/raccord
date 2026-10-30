<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('activitylog:clean', ['--force', '--days' => (int) config('platform.activity_log_retention_days')])->daily()->withoutOverlapping();
Schedule::command('punches:purge-locations')->daily()->withoutOverlapping();
Schedule::command('vehicle-photos:purge-locations')->daily()->withoutOverlapping();
Schedule::command('ai:purge-usage')->daily()->withoutOverlapping();
Schedule::command('users:purge-departed')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('audits:purge-presences')->daily()->withoutOverlapping();
Schedule::command('users:expire-legacy-passwords')->daily()->withoutOverlapping();
Schedule::command('auth:clear-resets')->daily()->withoutOverlapping();
Schedule::command('audits:purge-operations')->daily()->withoutOverlapping();
Schedule::command('geocoding:prune')->daily()->withoutOverlapping();
Schedule::command('records:purge-deleted')->daily()->withoutOverlapping();
Schedule::command('files:purge-orphans')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('design-office:check-deadlines')->everyMinute()->withoutOverlapping();
Schedule::command('design-office:verify-journal')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('design-office:generate-dossiers')->everyMinute()->withoutOverlapping(180)->runInBackground();
