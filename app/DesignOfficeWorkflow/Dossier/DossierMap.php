<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Models\AuditBox;
use DateTimeImmutable;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * The KMZ of an official dossier version: one placemark per box at its position (official first), grouped by kind of
 * box, with its conformity. Boxes without any position are left out (the generation counts them in its checks).
 */
class DossierMap
{
    public function build(DossierContents $contents): string
    {
        $folders = [];

        foreach ($contents->boxes as $box) {
            $position = $contents->positions[$box->id] ?? null;

            if ($position === null) {
                continue;
            }

            $folders[$box->kind->label()][] = $this->placemark($box, $position['latitude'], $position['longitude'], $position['source']->label());
        }

        $document = '';

        foreach ($folders as $kind => $placemarks) {
            $document .= '<Folder><name>'.$this->xml($kind).'</name>'.implode('', $placemarks).'</Folder>';
        }

        $kml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>'.$this->xml($contents->audit->cabinet_key).'</name>'.$document.'</Document></kml>';

        $stream = fopen('php://temp', 'w+b');
        $zip = new ZipStream(outputStream: $stream, defaultCompressionMethod: CompressionMethod::DEFLATE, sendHttpHeaders: false);
        $zip->addFile('doc.kml', $kml, lastModificationDateTime: new DateTimeImmutable('1980-01-01 00:00:00'));
        $zip->finish();
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private function placemark(AuditBox $box, float $latitude, float $longitude, string $source): string
    {
        $description = implode(' · ', array_filter([$box->kind->label(), trim($box->support?->label().' '.$box->support_number), $box->conformity->label(), $source]));

        return '<Placemark><name>'.$this->xml($box->name).'</name><description>'.$this->xml($description).'</description>'
            .'<Point><coordinates>'.number_format($longitude, 7, '.', '').','.number_format($latitude, 7, '.', '').',0</coordinates></Point></Placemark>';
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
