<?php

namespace App\Planning;

use App\Models\WorkSite;
use App\Models\WorkSiteBox;

/**
 * Text shown for a task generated from a one-day work site: « Client — City · Cabinet : X · Box : a, b ».
 * Built when displayed, so it follows the viewer's language.
 */
class TaskDetail
{
    public function build(WorkSite $site, int $userId): string
    {
        $site->loadMissing(['client', 'boxes.partners']);

        $parts = [trim((string) data_get($site, 'client.name').' — '.$site->city, ' —')];

        if ($site->cabinet_ref) {
            $parts[] = __('work_sites.task_detail.cabinet').' : '.$site->cabinet_ref;
        }

        if ($site->audit_mode !== null) {
            $parts[] = __('work_sites.task_detail.audit_mode_'.$site->audit_mode->value);
        }

        $boxes = $site->boxes
            ->filter(fn (WorkSiteBox $box) => $box->technician_id === $userId || $box->partners->contains('id', $userId))
            ->pluck('reference');

        if ($boxes->isNotEmpty()) {
            $parts[] = __('work_sites.task_detail.box').' : '.$boxes->implode(', ');
        }

        return implode(' · ', $parts);
    }
}
