<?php

namespace App\Enums;

/**
 * What a file of an official dossier version is.
 */
enum AuditDossierFileRole: string
{
    case Report = 'report';
    case RepairsWorkbook = 'repairs_workbook';
    case Map = 'map';
    case Synoptic = 'synoptic';
    case BoxPhoto = 'box_photo';
    case OccurrencePhoto = 'occurrence_photo';
    case Manifest = 'manifest';
    case ChangesManifest = 'changes_manifest';

    public function label(): string
    {
        return __('enums.audit_dossier_file_roles.'.$this->value);
    }

    /**
     * Files listed in the MANIFEST (the manifests themselves are not).
     */
    public function isListed(): bool
    {
        return ! in_array($this, [self::Manifest, self::ChangesManifest], true);
    }
}
