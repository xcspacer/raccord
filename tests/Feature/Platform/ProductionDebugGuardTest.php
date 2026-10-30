<?php

namespace Tests\Feature\Platform;

use App\Support\ProductionDebugGuard;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A production installation never runs with APP_DEBUG on, whatever its .env file says.
 */
class ProductionDebugGuardTest extends TestCase
{
    public function test_debug_mode_is_off_unless_the_env_file_switches_it_on(): void
    {
        $previous = $_SERVER['APP_DEBUG'] ?? null;
        unset($_SERVER['APP_DEBUG'], $_ENV['APP_DEBUG']);
        putenv('APP_DEBUG');

        try {
            $config = require config_path('app.php');
        } finally {
            if ($previous !== null) {
                $_SERVER['APP_DEBUG'] = $previous;
            }
        }

        $this->assertFalse($config['debug']);
    }

    public function test_production_switches_debug_mode_off_and_reports_it(): void
    {
        Log::shouldReceive('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'APP_DEBUG'));
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        app(ProductionDebugGuard::class)->enforce();

        $this->assertFalse(config('app.debug'));
    }

    public function test_production_error_pages_never_show_debug_details(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        Log::shouldReceive('critical')->once();
        app(ProductionDebugGuard::class)->enforce();
        Log::partialMock()->shouldReceive('error', 'log');

        $this->app['router']->get('/_test/boom', fn () => throw new \RuntimeException('Secret stack detail'));

        $this->get('/_test/boom')->assertStatus(500)->assertDontSee('Secret stack detail');
    }

    public function test_other_environments_keep_their_setting(): void
    {
        config(['app.debug' => true]);

        app(ProductionDebugGuard::class)->enforce();

        $this->assertTrue(config('app.debug'));
    }
}
