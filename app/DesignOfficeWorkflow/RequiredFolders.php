<?php

namespace App\DesignOfficeWorkflow;

use App\Audit\BoxFolders;
use App\Enums\AuditFolder;
use App\Enums\AuditPoleSection;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Support\Collection;

/**
 * The photo folders a box must have before it is submitted: the folders asked for the box (same rule as the standard
 * workflow) less those exempted in the settings. A pole is proven by the whole, head and label sections, or by the
 * older before and after sections.
 */
class RequiredFolders
{
    public function __construct(private BoxFolders $folders, private DesignOfficeWorkflowSettings $settings) {}

    /**
     * @return list<AuditFolder>
     */
    public function for(AuditBox $box): array
    {
        $exempt = $this->settings->exemptFolders();

        return array_values(array_filter(
            $this->folders->for($box, $box->audit->effectiveMode()),
            fn (AuditFolder $folder) => ! in_array($folder, $exempt, true),
        ));
    }

    /**
     * Required folders without photographic proof.
     *
     * @param  Collection<int, AuditPhoto>  $photos
     * @return list<AuditFolder>
     */
    public function missing(AuditBox $box, Collection $photos): array
    {
        $missing = [];

        foreach ($this->for($box) as $folder) {
            $inFolder = $photos->where('folder', $folder->value);

            if ($folder === AuditFolder::Pole) {
                $has = fn (AuditPoleSection $section) => $inFolder->contains(fn (AuditPhoto $photo) => strtolower($photo->subfolder) === $section->value);
                $proven = ($has(AuditPoleSection::Whole) && $has(AuditPoleSection::Head) && $has(AuditPoleSection::Label))
                    || ($has(AuditPoleSection::Before) && $has(AuditPoleSection::After));

                if (! $proven) {
                    $missing[] = $folder;
                }

                continue;
            }

            if ($inFolder->isEmpty()) {
                $missing[] = $folder;
            }
        }

        return $missing;
    }
}
