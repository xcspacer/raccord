<?php

namespace App\Support;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

/**
 * Photos embedded in generated PDFs: downscaled JPEG data URIs, so the PDF stays light.
 */
class PdfImage
{
    private const int MAX_SIDE = 900;

    public static function dataUri(?StoredFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $contents = Storage::disk($file->disk)->get($file->path);
        $image = $contents === null ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $ratio = min(1, self::MAX_SIDE / max(imagesx($image), imagesy($image)));

        if ($ratio < 1) {
            $image = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio))) ?: $image;
        }

        ob_start();
        imagejpeg($image, null, 80);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * A small JPEG of a photo for long reports (read from its thumbnail when there is one).
     */
    public static function thumbnailDataUri(?StoredFile $file, int $maxSide = 320): ?string
    {
        if ($file === null) {
            return null;
        }

        $disk = Storage::disk($file->disk);
        $path = $file->thumbnail_path !== null && $disk->exists($file->thumbnail_path) ? $file->thumbnail_path : $file->path;
        $contents = $disk->exists($path) ? $disk->get($path) : null;
        $image = $contents === null ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $ratio = min(1, $maxSide / max(imagesx($image), imagesy($image)));

        if ($ratio < 1) {
            $image = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio))) ?: $image;
        }

        ob_start();
        imagejpeg($image, null, 75);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    public static function logoDataUri(?string $path, string $disk = 'local'): ?string
    {
        if ($path === null || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) Storage::disk($disk)->get($path));

        if ($image === false) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * Width divided by height of an image given as a data URI (1 when it cannot be read).
     */
    public static function aspectRatio(string $dataUri): float
    {
        $size = @getimagesizefromstring((string) base64_decode(substr($dataUri, (int) strpos($dataUri, ',') + 1)));

        return $size === false || $size[1] === 0 ? 1.0 : $size[0] / $size[1];
    }
}
