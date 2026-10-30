<?php

namespace App\Support;

use App\Models\User;
use App\Settings\CompanySettings;

/**
 * Header of the documents a technician generates (expense report, vehicle and equipment sheets): the logo of the
 * company (société) the person belongs to, or the platform logo when that company has none. Only the logo changes;
 * the name printed and used in file names stays the platform's.
 */
class DocumentBrand
{
    /** Logos wider than this (width / height) are printed lower, as in the legacy documents. */
    private const float WIDE_RATIO = 2.0;

    public function __construct(private CompanySettings $company) {}

    /**
     * @return array{company: string, logo: ?string, wideLogo: bool}
     */
    public function for(?User $user): array
    {
        $logo = $user?->company?->logo;
        $dataUri = ($logo !== null ? PdfImage::logoDataUri($logo->path, $logo->disk) : null) ?? PdfImage::logoDataUri($this->company->logo_path);

        return [
            'company' => $this->company->displayName(),
            'logo' => $dataUri,
            'wideLogo' => $dataUri !== null && PdfImage::aspectRatio($dataUri) > self::WIDE_RATIO,
        ];
    }
}
