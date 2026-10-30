<?php

namespace Tests\Feature\Platform;

use App\Enums\Module;
use App\Settings\ModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'module:otdr_acceptance'])->get('/_test/otdr', fn () => 'otdr');
        Route::middleware(['web', 'module:client_portal'])->get('/_test/portal', fn () => 'portal');
    }

    public function test_enabled_module_is_reachable(): void
    {
        $this->get('/_test/otdr')->assertOk();
    }

    public function test_disabled_module_answers_not_found(): void
    {
        $this->disable(Module::OtdrAcceptance);

        $this->get('/_test/otdr')->assertNotFound();
    }

    public function test_module_is_off_when_a_required_module_is_off(): void
    {
        $this->disable(Module::Audits);

        $this->get('/_test/portal')->assertNotFound();
        $this->assertFalse(app(ModuleSettings::class)->isEnabled(Module::ClientPortal));
    }

    public function test_design_office_workflow_is_off_by_default(): void
    {
        $this->assertFalse(app(ModuleSettings::class)->isEnabled(Module::DesignOfficeWorkflow));
    }

    private function disable(Module $module): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_diff($settings->enabled, [$module->value]));
        $settings->save();
    }
}
