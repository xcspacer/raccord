<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Small real files for upload tests: the server checks what a file contains, so empty fakes are refused.
 */
final class SampleFiles
{
    public static function pdf(string $name = 'document.pdf', string $body = 'body'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body."\n%%EOF\n");
    }

    public static function kml(string $name = 'map.kml', string $placemarks = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::kmlDocument($placemarks));
    }

    /**
     * A KMZ archive, padded with stored bytes up to about the given size in kilobytes when one is given.
     */
    public static function kmz(string $name = 'map.kmz', string $placemarks = '', int $kilobytes = 0): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kmz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', self::kmlDocument($placemarks));

        if ($kilobytes > 0) {
            $zip->addFromString('files/padding.bin', random_bytes(max(0, $kilobytes * 1024 - 1024)));
            $zip->setCompressionName('files/padding.bin', ZipArchive::CM_STORE);
        }

        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    public static function xlsx(string $name = 'plan.xlsx', ?Spreadsheet $spreadsheet = null): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');

        if ($spreadsheet === null) {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'plan');
        }

        (new Xlsx($spreadsheet))->save($path);
        $contents = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    public static function text(string $name = 'list.txt', string $contents = "line\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private static function kmlDocument(string $placemarks): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$placemarks.'</Document></kml>';
    }
}
