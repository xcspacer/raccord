<?php

namespace App\DesignOfficeWorkflow\Dossier;

use RuntimeException;

/**
 * The automatic checks run before a dossier version is written did not pass: nothing is published.
 *
 * @phpstan-import-type Check from DossierGenerator
 */
class DossierChecksFailed extends RuntimeException
{
    /**
     * @param  list<Check>  $checks
     */
    public function __construct(public readonly array $checks)
    {
        parent::__construct('checks_failed');
    }
}
