<?php

namespace App\Settings;

use App\Enums\Module;
use Spatie\LaravelSettings\Settings;

class ModuleSettings extends Settings
{
    /** @var list<string> */
    public array $enabled;

    public static function group(): string
    {
        return 'modules';
    }

    /**
     * A module is active when it is switched on and every module it relies on is active too.
     */
    public function isEnabled(Module $module): bool
    {
        if (! in_array($module->value, $this->enabled, true)) {
            return false;
        }

        foreach ($module->requires() as $required) {
            if (! $this->isEnabled($required)) {
                return false;
            }
        }

        return true;
    }
}
