<?php

namespace App\Actions\Files;

use Closure;
use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * What each upload field accepts (D10, UPL-02): its own short list of file types, checked on the content of the file
 * and never on its name alone. Photos are re-encoded by the server, so only their content counts; documents must also
 * carry the extension of what they really are. Formats with macros are always refused.
 */
final class UploadRules
{
    /** Photos the server decodes and re-encodes (the phones already send JPEG; HEIC is converted on the phone). */
    public const array PHOTO = ['jpg', 'jpeg', 'png', 'webp'];

    public const array LOGO = ['png', 'jpg', 'jpeg', 'webp'];

    /** Personal documents of the profile (construction ID card, driving licence). */
    public const array PERSONAL_DOCUMENT = ['pdf', 'png', 'jpg', 'jpeg'];

    /** Map with the positions of the boxes. */
    public const array MAP = ['kmz', 'kml'];

    /** Synoptic of an audit: its text is read to list the boxes. */
    public const array SYNOPTIC = ['pdf', 'txt', 'csv'];

    /** Version of the synoptic published by the design office: kept and downloaded. */
    public const array VERSIONED_SYNOPTIC = ['pdf', 'png', 'jpg', 'jpeg'];

    /** Synoptic attached to a daily work site: only kept and downloaded. */
    public const array WORK_SITE_SYNOPTIC = ['pdf', 'png', 'jpg', 'jpeg', 'xlsx', 'xls', 'csv', 'txt'];

    /** Drawer plan and plans of the boxes. */
    public const array PLAN = ['xlsx', 'xls', 'pdf'];

    /** Equipment list read from a text file. */
    public const array TEXT_LIST = ['txt', 'csv', 'md'];

    /** Opened in the browser rather than downloaded. */
    public const array INLINE = ['pdf', 'png', 'jpg', 'jpeg'];

    /**
     * Content types the server may find when reading each kind of file.
     *
     * @var array<string, list<string>>
     */
    private const array CONTENT = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'kmz' => ['application/zip', 'application/vnd.google-earth.kmz'],
        'kml' => ['text/xml', 'application/xml', 'text/plain', 'application/vnd.google-earth.kml+xml'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/cdfv2', 'application/x-ole-storage'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
    ];

    /**
     * A photo of at most the given size, whatever name the phone gave it.
     *
     * @return list<mixed>
     */
    public static function photo(int $maxKilobytes): array
    {
        return ['file', 'max:'.$maxKilobytes, self::photoContent()];
    }

    /**
     * A file of one of the given types (extension and content) of at most the given size.
     *
     * @param  list<string>  $extensions
     * @return list<mixed>
     */
    public static function document(array $extensions, int $maxKilobytes): array
    {
        return ['file', 'max:'.$maxKilobytes, self::types($extensions)];
    }

    public static function photoContent(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value instanceof UploadedFile && ! self::isPhoto($value)) {
                $fail(__('validation.mimes', ['values' => implode(', ', self::PHOTO)]));
            }
        };
    }

    public static function isPhoto(UploadedFile $file): bool
    {
        return in_array(self::detectedType($file), array_merge(...array_map(fn (string $extension) => self::CONTENT[$extension], self::PHOTO)), true);
    }

    /**
     * @param  list<string>  $extensions
     */
    public static function types(array $extensions): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($extensions): void {
            if ($value instanceof UploadedFile && ! self::matches($value, $extensions)) {
                $fail(__('validation.mimes', ['values' => implode(', ', $extensions)]));
            }
        };
    }

    /**
     * @param  list<string>  $extensions
     */
    private static function matches(UploadedFile $file, array $extensions): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $extensions, true) || ! in_array(self::detectedType($file), self::CONTENT[$extension] ?? [], true)) {
            return false;
        }

        return in_array($extension, ['xlsx', 'kmz'], true) ? self::isSafeArchive($file, $extension) : true;
    }

    /**
     * A workbook must be a real OOXML workbook without macros; a map archive must hold a KML document.
     */
    private static function isSafeArchive(UploadedFile $file, string $extension): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($extension === 'xlsx') {
                return $zip->locateName('xl/workbook.xml') !== false && $zip->locateName('xl/vbaProject.bin') === false;
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($index)), '.kml')) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /**
     * Type found by reading the content (never the type announced by the browser nor the name of the file).
     */
    private static function detectedType(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        return $path === false ? '' : strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));
    }
}
