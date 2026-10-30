<?php

namespace App\Jobs;

use App\Audit\AuditPdf;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes the PDF of a box or of a control point, after the person has been answered: what was sent just before
 * (photos, status, notes) is already saved, so the PDF is complete.
 */
class GenerateAuditPdf
{
    use Queueable;

    public function __construct(public string $unit, public int $id) {}

    public function handle(AuditPdf $pdf): void
    {
        if ($this->unit === 'box') {
            $box = AuditBox::query()->find($this->id);

            if ($box !== null && ! $box->isRemoved()) {
                $pdf->forBox($box);
            }

            return;
        }

        $point = AuditControlPoint::query()->find($this->id);

        if ($point !== null) {
            $pdf->forPoint($point);
        }
    }
}
