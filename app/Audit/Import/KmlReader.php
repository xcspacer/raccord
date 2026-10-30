<?php

namespace App\Audit\Import;

use App\Audit\BoxName;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Reads the positions of the boxes and of the cabinet from a map file (KMZ or KML): only points are used. The boxes
 * are the points of the folder named after the boxes (every point when there is none), the cabinet is the point of
 * its own folder or the one named after the cabinet code.
 */
class KmlReader
{
    private const int MAX_BYTES = 30_000_000;

    /**
     * @return array{boxes: array<string, array{float, float}>, cabinet: array{latitude: float, longitude: float, name: string}|null}|null Null when the file cannot be read.
     */
    public function read(string $contents): ?array
    {
        $kml = str_starts_with($contents, 'PK') ? $this->unzip($contents) : $contents;

        if ($kml === null || $kml === '') {
            return null;
        }

        $document = new DOMDocument;

        if (! @$document->loadXML($kml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT)) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('k', $document->documentElement?->namespaceURI ?: 'http://www.opengis.net/kml/2.2');
        $points = [];

        foreach ($xpath->query('//k:Placemark[k:Point]') ?: [] as $placemark) {
            if (! $placemark instanceof DOMElement) {
                continue;
            }

            $coordinates = trim((string) $xpath->evaluate('string(k:Point/k:coordinates)', $placemark));
            [$longitude, $latitude] = array_pad(array_map('trim', explode(',', preg_split('/\s+/', $coordinates)[0] ?? '')), 2, '');

            if (! is_numeric($latitude) || ! is_numeric($longitude)) {
                continue;
            }

            $points[] = [
                'name' => trim((string) $xpath->evaluate('string(k:name)', $placemark)), 'latitude' => (float) $latitude, 'longitude' => (float) $longitude,
                'folders' => $this->folders($placemark, $xpath),
            ];
        }

        return $this->classify($points);
    }

    /**
     * @param  list<array{name: string, latitude: float, longitude: float, folders: list<string>}>  $points
     * @return array{boxes: array<string, array{float, float}>, cabinet: array{latitude: float, longitude: float, name: string}|null}
     */
    private function classify(array $points): array
    {
        $boxFolder = mb_strtoupper((string) config('audit.kml.box_folder'));
        $cabinetFolder = mb_strtoupper((string) config('audit.kml.cabinet_folder'));
        $prefixes = array_map('mb_strtoupper', (array) config('audit.kml.cabinet_prefixes'));
        $inFolder = function (array $point, string $word): bool {
            foreach ($point['folders'] as $folder) {
                if ($word !== '' && str_contains(mb_strtoupper($folder), $word)) {
                    return true;
                }
            }

            return false;
        };
        $cabinet = null;
        $boxes = [];

        foreach ($points as $point) {
            $isCabinet = $inFolder($point, $cabinetFolder);

            foreach ($prefixes as $prefix) {
                $isCabinet = $isCabinet || ($prefix !== '' && str_starts_with(mb_strtoupper($point['name']), $prefix));
            }

            if ($isCabinet) {
                $cabinet ??= ['latitude' => $point['latitude'], 'longitude' => $point['longitude'], 'name' => mb_substr($point['name'], 0, 80)];
            }
        }

        $named = array_values(array_filter($points, fn (array $point) => $inFolder($point, $boxFolder)));

        foreach ($named === [] ? $points : $named as $point) {
            if ($point['name'] !== '' && ! ($cabinet !== null && $point['latitude'] === $cabinet['latitude'] && $point['longitude'] === $cabinet['longitude'] && $point['name'] === $cabinet['name'])) {
                $boxes[$point['name']] = [$point['latitude'], $point['longitude']];
            }
        }

        return ['boxes' => $boxes, 'cabinet' => $cabinet];
    }

    /**
     * @return list<string>
     */
    private function folders(DOMElement $placemark, DOMXPath $xpath): array
    {
        $names = [];

        for ($parent = $placemark->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array($parent->localName, ['Folder', 'Document'], true)) {
                $names[] = trim((string) $xpath->evaluate('string(k:name)', $parent));
            }
        }

        return $names;
    }

    private function unzip(string $contents): ?string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kmz');

        try {
            file_put_contents($path, $contents);
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                return null;
            }

            $entry = $zip->locateName('doc.kml') !== false ? 'doc.kml' : null;

            for ($index = 0; $entry === null && $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $entry = strtolower(substr($name, -4)) === '.kml' ? $name : null;
            }

            $kml = $entry === null ? null : $zip->getFromName($entry, self::MAX_BYTES);
            $zip->close();

            return $kml === false ? null : $kml;
        } finally {
            @unlink($path);
        }
    }

    /**
     * Key used to match a map point with a box: letters and digits only, upper case.
     */
    public static function key(string $name): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($name));
    }

    /**
     * Whether two names may be the same box (same key, or same canonical key as for plans).
     */
    public static function sameBox(string $a, string $b): bool
    {
        return self::key($a) === self::key($b) || (BoxName::canonicalKey($a) !== '' && BoxName::canonicalKey($a) === BoxName::canonicalKey($b));
    }
}
