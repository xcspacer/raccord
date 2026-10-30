<?php

namespace App\Settings;

use App\Enums\AuditFolder;
use Spatie\LaravelSettings\Settings;

/**
 * Rules of the design office workflow, edited on the platform settings page: how long the design office has to
 * decide a submitted box, when it is alerted, an optional cap of open requests per reviewer (off by default: requests
 * then go to the least loaded reviewer whatever their load), the photo folders a technician may leave empty, and
 * whether the design office may ask the AI for a draft text of an occurrence (off until the provider's retention and
 * minimisation terms are approved, see docs/rgpd).
 */
class DesignOfficeWorkflowSettings extends Settings
{
    public int $review_deadline_seconds;

    public int $review_alert_seconds;

    public ?int $max_open_requests_per_reviewer;

    /** @var list<string> */
    public array $exempt_folders;

    public bool $ai_drafts_enabled;

    public static function group(): string
    {
        return 'design_office_workflow';
    }

    /**
     * @return list<AuditFolder>
     */
    public function exemptFolders(): array
    {
        return array_values(array_filter(array_map(fn (string $folder) => AuditFolder::tryFrom($folder), $this->exempt_folders)));
    }
}
