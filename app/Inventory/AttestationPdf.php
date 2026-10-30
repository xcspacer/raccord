<?php

namespace App\Inventory;

use App\Settings\CompanySettings;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The PDF of a certificate of handover, in the language of the person who makes it, with the company's name and
 * logo. Without both signatures it carries a red « not signed » warning.
 */
class AttestationPdf
{
    public function __construct(private CompanySettings $company) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(array $data): string
    {
        return Pdf::loadView('pdf.inventory-attestation', $data + [
            'company' => $this->company->displayName(),
            'logo' => PdfImage::logoDataUri($this->company->logo_path),
            'footer' => $this->company->document_footer,
            'technicianSignatureUri' => isset($data['technicianSignature']) ? 'data:image/png;base64,'.base64_encode((string) $data['technicianSignature']) : null,
            'representativeSignatureUri' => isset($data['representativeSignature']) ? 'data:image/png;base64,'.base64_encode((string) $data['representativeSignature']) : null,
        ])->setPaper('a4')->output();
    }
}
