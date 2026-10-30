<?php

namespace App\Jobs;

use App\DesignOfficeWorkflow\Dossier\DossierGenerator;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates and publishes a pending version of an official dossier. A long piece of work: it never runs inside a web
 * request — `design-office:generate-dossiers` (every minute) runs it, and an installation with a queue worker may
 * dispatch it instead. Running it twice, or on a version that is no longer pending, changes nothing.
 */
class GenerateDossierVersion
{
    use Queueable;

    public function __construct(public int $versionId) {}

    public function handle(DossierGenerator $generator): void
    {
        $generator->generate($this->versionId);
    }
}
