<?php

namespace App\Console\Commands;

use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Logs\AdministratorAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Every day: recomputes the hash chain of the design office workflow journal. A broken link (an entry changed or
 * removed outside the platform) is logged and e-mailed to the administrators.
 */
class VerifyWorkflowJournal extends Command
{
    public const string ALERT = 'journal_broken';

    protected $signature = 'design-office:verify-journal';

    protected $description = 'Verify the hash chain of the design office workflow journal';

    public function handle(WorkflowJournal $journal, AdministratorAlerts $alerts): int
    {
        $result = $journal->verify();

        if ($result['intact']) {
            $this->info("Journal intact ({$result['checked']} entries).");

            return self::SUCCESS;
        }

        Log::error('Design office workflow journal broken', ['entry' => $result['broken_at'], 'checked' => $result['checked']]);
        $alerts->send(self::ALERT, ['entry' => (string) $result['broken_at']]);
        $this->error("Journal broken at entry {$result['broken_at']}.");

        return self::FAILURE;
    }
}
