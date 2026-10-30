<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Models\Audit;
use App\Models\AuditDossierVersion;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\WorkSite;

/**
 * Language the official dossier is written in: the language of the client the audit is delivered to (the client of
 * its work site, else of a portal account given the audit), French when there is none. Once a version is written,
 * the next ones keep its language, so the paths of the ZIP stay the same from one version to the next.
 */
class DossierLanguage
{
    public const string DEFAULT = 'fr';

    public function for(Audit $audit): string
    {
        $previous = AuditDossierVersion::query()->where('audit_id', $audit->id)->whereNotNull('locale')->orderByDesc('version_number')->value('locale');

        return $this->supported(is_string($previous) ? $previous : $this->client($audit)?->locale);
    }

    private function client(Audit $audit): ?Client
    {
        $clientId = WorkSite::query()->where('audit_id', $audit->id)->whereNotNull('client_id')->orderBy('id')->value('client_id')
            ?? ClientAccount::query()->whereHas('audits', fn ($audits) => $audits->whereKey($audit->id))->orderBy('id')->value('client_id');

        return $clientId === null ? null : Client::query()->find($clientId);
    }

    private function supported(?string $locale): string
    {
        return in_array($locale, config('app.supported_locales'), true) ? $locale : self::DEFAULT;
    }
}
