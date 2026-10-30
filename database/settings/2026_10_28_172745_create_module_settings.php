<?php

use App\Enums\Module;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $enabled = [];

        foreach (Module::cases() as $module) {
            if ($module !== Module::DesignOfficeWorkflow) {
                $enabled[] = $module->value;
            }
        }

        $this->migrator->add('modules.enabled', $enabled);
    }
};
