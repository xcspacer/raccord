<?php

namespace App\Planning;

use App\Audit\BoxName;
use App\Audit\Import\KmlReader;
use App\Enums\WorkSiteAttachmentType;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\WorkSite;
use App\Models\WorkSiteAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * Where to drive for a work site: the cabinet and its boxes. A position comes from the audit of the cabinet (the GPS
 * recorded on site or imported; in the design office workflow, the official position approved by the design office
 * first), otherwise from the map file of the work site or of another work site on the same cabinet. Nothing is
 * invented: a place without a known position has none.
 *
 * @phpstan-type Point array{latitude: float, longitude: float}
 */
class NavigationPoints
{
    public function __construct(private KmlReader $reader) {}

    /**
     * @param  list<string>  $boxes  References of the boxes.
     * @return array{cabinet: Point|null, boxes: array<string, Point>}
     */
    public function for(WorkSite $site, array $boxes): array
    {
        $key = BoxName::cabinetKey((string) $site->cabinet_ref);
        $audit = $key === '' ? null : Audit::query()->where('cabinet_key', $key)->with('boxes')->first();
        $map = null;
        $mapRead = false;
        $readMap = function () use ($site, &$map, &$mapRead): ?array {
            if (! $mapRead) {
                $mapRead = true;
                $map = $this->map($site);
            }

            return $map;
        };

        $points = ['cabinet' => null, 'boxes' => []];

        if ($site->cabinet_ref) {
            $points['cabinet'] = $this->point($audit?->cabinet_latitude, $audit?->cabinet_longitude);

            if ($points['cabinet'] === null && ($cabinet = $readMap()['cabinet'] ?? null) !== null) {
                $points['cabinet'] = ['latitude' => $cabinet['latitude'], 'longitude' => $cabinet['longitude']];
            }
        }

        if ($audit?->usesDesignOfficeWorkflow()) {
            $audit->boxes->load('officialPosition');
        }

        foreach ($boxes as $reference) {
            $official = $audit?->boxes->first(fn (AuditBox $candidate) => ! $candidate->isRemoved() && KmlReader::sameBox($candidate->name, $reference) && $candidate->officialPosition !== null)?->officialPosition;
            $box = $audit?->boxes->first(fn (AuditBox $candidate) => ! $candidate->isRemoved() && KmlReader::sameBox($candidate->name, $reference) && $this->point($candidate->latitude, $candidate->longitude) !== null);
            $point = $official !== null ? $this->point($official->latitude, $official->longitude) : ($box === null ? null : $this->point($box->latitude, $box->longitude));

            if ($point === null) {
                foreach ($readMap()['boxes'] ?? [] as $name => [$latitude, $longitude]) {
                    if (KmlReader::sameBox((string) $name, $reference)) {
                        $point = $this->point($latitude, $longitude);

                        break;
                    }
                }
            }

            if ($point !== null) {
                $points['boxes'][$reference] = $point;
            }
        }

        return $points;
    }

    /**
     * @return array{boxes: array<string, array{float, float}>, cabinet: array{latitude: float, longitude: float, name: string}|null}|null
     */
    private function map(WorkSite $site): ?array
    {
        $attachment = $site->attachments()->where('type', WorkSiteAttachmentType::Kmz->value)->with('file')->first()
            ?? ($site->cabinet_ref ? WorkSiteAttachment::query()->where('type', WorkSiteAttachmentType::Kmz->value)
                ->whereHas('workSite', fn ($query) => $query->where('cabinet_ref', $site->cabinet_ref))->with('file')->latest('id')->first() : null);
        $file = $attachment?->file;

        if ($file === null || ! Storage::disk($file->disk)->exists($file->path)) {
            return null;
        }

        return $this->reader->read((string) Storage::disk($file->disk)->get($file->path));
    }

    /**
     * @return Point|null
     */
    private function point(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180 || (abs($latitude) < 1e-9 && abs($longitude) < 1e-9)) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }
}
