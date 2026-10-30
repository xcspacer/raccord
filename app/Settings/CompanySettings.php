<?php

namespace App\Settings;

use Illuminate\Support\Facades\Storage;
use Spatie\LaravelSettings\Settings;

/**
 * Identity of the company running this installation (white label). Everything starts empty except the
 * name, which falls back to APP_NAME until configured on the platform settings page.
 */
class CompanySettings extends Settings
{
    public ?string $name;

    public ?string $legal_name;

    public ?string $logo_path;

    public ?string $email;

    public ?string $phone;

    public ?string $address;

    public ?string $legal_identifier;

    public ?string $website;

    public ?string $mail_from_name;

    public ?string $mail_from_address;

    public ?string $expense_report_email;

    public ?string $document_footer;

    public static function group(): string
    {
        return 'company';
    }

    public function displayName(): string
    {
        return $this->name ?: (string) config('app.name');
    }

    /**
     * Public URL of the logo (served from the private disk by the branding route), or null when no logo
     * is configured. The version parameter busts browser caches when the logo changes.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || ! Storage::disk('local')->exists($this->logo_path)) {
            return null;
        }

        return route('branding.logo', ['v' => substr(md5($this->logo_path), 0, 8)]);
    }
}
