<?php

namespace App\Audit;

use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditPoleSection;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditCable;
use App\Models\AuditPhoto;
use Illuminate\Support\Collection;

/**
 * How far a box and a cabinet are done. The same calculation serves the technician, the manager and the client
 * portal. The boxes must have their photos and cables loaded.
 */
class BoxProgress
{
    public function __construct(private BoxFolders $folders) {}

    /**
     * Things to do on a box, each done or not: the photo folders, the cable, the confirmed position (optical mode)
     * and the final status.
     *
     * @return list<array{key: string, done: bool}>
     */
    public function items(AuditBox $box, AuditMode $mode): array
    {
        $optical = $mode === AuditMode::Optical;
        $items = [];

        foreach ($this->folders->for($box, $mode, $this->photos($box, AuditFolder::Tco)->isNotEmpty()) as $folder) {
            if ($optical && in_array($folder, [AuditFolder::Labels, AuditFolder::Cassettes, AuditFolder::Lashing], true)) {
                continue;
            }

            $items[] = ['key' => $folder->value, 'done' => $folder === AuditFolder::Pole ? $this->poleDone($box) : $this->photos($box, $folder)->isNotEmpty()];
        }

        $items[] = ['key' => 'cable', 'done' => $optical ? $this->cablesDone($box) : $box->fibre_type !== null];

        if ($optical) {
            $items[] = ['key' => 'position', 'done' => $box->confirmed_at !== null];
        }

        $items[] = ['key' => 'status', 'done' => in_array($box->status?->value, ['ok', 'anomaly'], true) && ! $box->isReturned()];

        return $items;
    }

    public function percent(AuditBox $box, AuditMode $mode): int
    {
        if ($box->status?->value === 'not_installed' && ! $box->isReturned()) {
            return 100;
        }

        $items = collect($this->items($box, $mode));

        return (int) round($items->where('done', true)->count() * 100 / $items->count());
    }

    /**
     * Average of the boxes still in the audit; zero without boxes.
     */
    public function auditPercent(Audit $audit): int
    {
        $boxes = $audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved());

        if ($boxes->isEmpty()) {
            return 0;
        }

        return (int) round($boxes->sum(fn (AuditBox $box) => $this->percent($box, $audit->effectiveMode())) / $boxes->count());
    }

    /**
     * An optical cable is complete with a capacity, a fibre type and a label photo (or « no label »).
     */
    public function cableComplete(AuditBox $box, AuditCable $cable): bool
    {
        return $cable->capacity !== null && $cable->fibre_type !== null
            && ($cable->has_no_label || $this->photos($box, AuditFolder::Labels, $cable->code)->isNotEmpty());
    }

    private function cablesDone(AuditBox $box): bool
    {
        return $box->cables->isNotEmpty() && $box->cables->every(fn (AuditCable $cable) => $this->cableComplete($box, $cable));
    }

    /**
     * The pole is done with its whole, head and label photos, or (older audits) with before and after.
     */
    private function poleDone(AuditBox $box): bool
    {
        $has = fn (AuditPoleSection $section) => $this->photos($box, AuditFolder::Pole, $section->value)->isNotEmpty();

        return ($has(AuditPoleSection::Whole) && $has(AuditPoleSection::Head) && $has(AuditPoleSection::Label)) || ($has(AuditPoleSection::Before) && $has(AuditPoleSection::After));
    }

    /**
     * @return Collection<int, AuditPhoto>
     */
    private function photos(AuditBox $box, AuditFolder $folder, ?string $subfolder = null): Collection
    {
        return $box->photos->filter(fn (AuditPhoto $photo) => $photo->folder === $folder->value && ($subfolder === null || $photo->subfolder === $subfolder));
    }
}
