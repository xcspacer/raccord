<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['name', 'legal_name', 'logo_path', 'email', 'phone', 'address', 'legal_identifier', 'website', 'mail_from_name', 'mail_from_address', 'expense_report_email', 'document_footer'] as $property) {
            $this->migrator->add('company.'.$property, null);
        }
    }
};
