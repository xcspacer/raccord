<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('design_office_workflow.review_deadline_seconds', 300);
        $this->migrator->add('design_office_workflow.review_alert_seconds', 180);
        $this->migrator->add('design_office_workflow.max_open_requests_per_reviewer', null);
        $this->migrator->add('design_office_workflow.exempt_folders', ['before']);
        $this->migrator->add('design_office_workflow.ai_drafts_enabled', false);
    }
};
