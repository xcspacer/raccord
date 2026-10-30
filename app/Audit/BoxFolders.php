<?php

namespace App\Audit;

use App\Enums\AuditBoxKind;
use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditSupport;
use App\Models\AuditBox;

/**
 * The photo folders asked for a box, which depend on the audit mode, the kind of box and what it is fixed on.
 * A box whose support is not chosen yet is treated as being in a chamber, in both modes (one rule everywhere).
 */
class BoxFolders
{
    /**
     * @return list<AuditFolder>
     */
    public function for(AuditBox $box, AuditMode $mode, bool $hasTcoPhotos = false): array
    {
        $support = $box->support;
        $folders = [];

        if ($mode === AuditMode::Optical) {
            if ($support === AuditSupport::Chamber) {
                array_push($folders, AuditFolder::Surroundings, AuditFolder::Interior);
            }

            if ($box->kind === AuditBoxKind::Pbo && $support === AuditSupport::Facade) {
                $folders[] = AuditFolder::FacadeSurroundings;
            }

            if ($support === AuditSupport::Pole) {
                $folders[] = AuditFolder::Pole;
            }

            array_push($folders, AuditFolder::Closed, AuditFolder::Open, AuditFolder::Cassettes);

            if ($box->kind === AuditBoxKind::Pbo || $hasTcoPhotos) {
                $folders[] = AuditFolder::Tco;
            }

            array_push($folders, AuditFolder::Labels, AuditFolder::Lashing);

            return $folders;
        }

        if (($support ?? AuditSupport::Chamber) === AuditSupport::Chamber) {
            array_push($folders, AuditFolder::Surroundings, AuditFolder::Interior);
        }

        if ($box->kind === AuditBoxKind::Pbo && $support === AuditSupport::Facade) {
            $folders[] = AuditFolder::FacadeSurroundings;
        }

        array_push($folders, AuditFolder::Closed, AuditFolder::Open, AuditFolder::Labels, AuditFolder::Entries, AuditFolder::Storage);
        $folders[] = in_array($support, [AuditSupport::Pole, AuditSupport::Facade], true) ? AuditFolder::Fixing : AuditFolder::Placed;
        $folders[] = AuditFolder::Cassettes;

        if ($box->kind === AuditBoxKind::Pbo) {
            $folders[] = AuditFolder::Tco;
        }

        if ($support === AuditSupport::Pole) {
            $folders[] = AuditFolder::Pole;
        }

        return $folders;
    }
}
