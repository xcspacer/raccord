<?php

namespace App\ClientPortal;

use App\Enums\AuditInspectionState;
use App\Models\Audit;
use App\Models\AuditBox;

/**
 * Which photos the client portal may show. On an audit of the design office workflow, a client only sees the photos
 * (and the box PDF, which holds them) of the boxes whose inspection the design office accepted, and the photos of the
 * control points once the audit is closed; a photo nobody validated never leaves the office. Standard audits are
 * followed live as before.
 */
class PortalPhotoVisibility
{
    public function box(Audit $audit, AuditBox $box): bool
    {
        return ! $audit->usesDesignOfficeWorkflow() || $box->inspection_state === AuditInspectionState::Validated;
    }

    public function controlPoints(Audit $audit): bool
    {
        return ! $audit->usesDesignOfficeWorkflow() || $audit->isClosed();
    }
}
