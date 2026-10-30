<?php

namespace App\Console\Commands;

use App\DesignOfficeWorkflow\Dossier\DossierVersions;
use App\Enums\AuditDossierStatus;
use App\Enums\Module;
use App\Jobs\GenerateDossierVersion;
use App\Models\AuditDossierVersion;
use App\Settings\ModuleSettings;
use Illuminate\Console\Command;

/**
 * Every minute: marks interrupted generations failed, prepares the first dossier of closed audits and the next one
 * after a validated correction, then generates the pending versions one after the other (outside any web request,
 * so a dossier of thousands of photos never hits a request time limit). Running it twice changes nothing.
 */
class GenerateDossiers extends Command
{
    protected $signature = 'design-office:generate-dossiers';

    protected $description = 'Prepare and generate the official dossier versions of the closed design office audits';

    public function handle(DossierVersions $versions, ModuleSettings $modules): int
    {
        if (! $modules->isEnabled(Module::DesignOfficeWorkflow)) {
            return self::SUCCESS;
        }

        $sweep = $versions->sweep();
        $generated = 0;

        foreach (AuditDossierVersion::query()->where('status', AuditDossierStatus::Pending)->orderBy('id')->pluck('id') as $id) {
            GenerateDossierVersion::dispatchSync((int) $id);
            $generated++;
        }

        $this->info("{$sweep['stale']} interrupted, {$sweep['initial']} first and {$sweep['updates']} next version(s) prepared, {$generated} version(s) generated.");

        return self::SUCCESS;
    }
}
