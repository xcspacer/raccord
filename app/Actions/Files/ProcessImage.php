<?php

namespace App\Actions\Files;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Turns an uploaded photo into what is kept: a JPEG at most 2560 px wide or high (quality 82, EXIF and
 * any other metadata dropped by re-encoding) and a small WebP thumbnail. Images whose header announces more
 * pixels than MAX_PIXELS are refused before being decoded (a small file can claim a huge picture).
 */
class ProcessImage
{
    private const int MAX_SIDE = 2560;

    private const int JPEG_QUALITY = 82;

    private const int THUMBNAIL_SIDE = 480;

    private const int WEBP_QUALITY = 78;

    public const int MAX_PIXELS = 50_000_000;

    /**
     * The thumbnail is null when it could not be made: the photo is kept and the thumbnail is made again later
     * (GenerateThumbnail).
     *
     * @return array{jpeg: string, thumbnail: string|null, width: int, height: int}
     *
     * @throws InvalidArgumentException When the file is not a readable image.
     */
    public function handle(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidArgumentException('Not an image');
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Image too large');
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new InvalidArgumentException('Not an image');
        }

        $source = $this->upright($source, $file);
        $main = $this->fit($source, self::MAX_SIDE);

        return [
            'jpeg' => $this->encode($main, fn ($image) => imagejpeg($image, null, self::JPEG_QUALITY)),
            'thumbnail' => $this->webpThumbnail($source),
            'width' => imagesx($main),
            'height' => imagesy($main),
        ];
    }

    /**
     * The WebP thumbnail of a photo already kept (its JPEG), or null when it cannot be made.
     */
    public function thumbnail(string $contents): ?string
    {
        $source = @imagecreatefromstring($contents);

        return $source === false ? null : $this->webpThumbnail($source);
    }

    private function webpThumbnail(\GdImage $source): ?string
    {
        try {
            $thumbnail = $this->encode($this->fit($source, self::THUMBNAIL_SIDE), fn ($image) => imagewebp($image, null, self::WEBP_QUALITY));
        } catch (\Throwable) {
            return null;
        }

        return $thumbnail === '' ? null : $thumbnail;
    }

    /**
     * Applies the EXIF orientation of a JPEG (phones store the camera's rotation there) before it is dropped.
     */
    private function upright(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array(strtolower((string) $file->guessExtension()), ['jpg', 'jpeg'], true)) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function fit(\GdImage $image, int $maxSide): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(1, $maxSide / max($width, $height));

        if ($ratio === 1) {
            return $image;
        }

        return imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))) ?: $image;
    }

    /**
     * @param  callable(\GdImage): mixed  $writer
     */
    private function encode(\GdImage $image, callable $writer): string
    {
        ob_start();
        $writer($image);

        return (string) ob_get_clean();
    }
}
