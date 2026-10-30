<?php

namespace App\Actions\Files;

use App\Jobs\GenerateThumbnail;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores uploaded content once on the private disk, flat, under a stable internal name derived from
 * its SHA-256 hash; identical content is never stored twice.
 */
class StoreUploadedFile
{
    private const string DISK = 'local';

    public function handle(UploadedFile $file): StoredFile
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->guessExtension() ?? 'bin');

        $stored = StoredFile::query()->firstOrCreate(['sha256' => $hash], [
            'disk' => self::DISK,
            'path' => 'files/'.substr($hash, 0, 2).'/'.$hash.'.'.$extension,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
            'reference_count' => 0,
        ]);

        if ($stored->wasRecentlyCreated || ! Storage::disk($stored->disk)->exists($stored->path)) {
            Storage::disk($stored->disk)->putFileAs(dirname($stored->path), $file, basename($stored->path));
        }

        $stored->increment('reference_count');

        return $stored;
    }

    /**
     * Stores a photo: downscaled JPEG without metadata plus a WebP thumbnail, deduplicated on the content of
     * the processed image (the same photo uploaded twice is stored once). A thumbnail that cannot be made or written
     * never loses the photo: it stays without one (the full image is shown meanwhile) and GenerateThumbnail makes it
     * in the background, with retries.
     *
     * @throws \InvalidArgumentException When the file is not an image.
     */
    public function handleImage(UploadedFile $file): StoredFile
    {
        $processed = app(ProcessImage::class)->handle($file);
        $hash = hash('sha256', $processed['jpeg']);
        $thumbnailPath = 'thumbs/'.substr($hash, 0, 2).'/'.$hash.'.webp';

        $stored = StoredFile::query()->firstOrCreate(['sha256' => $hash], [
            'disk' => self::DISK,
            'path' => 'files/'.substr($hash, 0, 2).'/'.$hash.'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => strlen($processed['jpeg']),
            'width' => $processed['width'],
            'height' => $processed['height'],
            'thumbnail_path' => null,
            'reference_count' => 0,
        ]);

        $disk = Storage::disk($stored->disk);

        if (! $disk->exists($stored->path)) {
            $disk->put($stored->path, $processed['jpeg']);
        }

        if ($stored->thumbnail_path === null || ! $disk->exists($stored->thumbnail_path)) {
            $written = $processed['thumbnail'] !== null && $disk->put($thumbnailPath, $processed['thumbnail']);
            $stored->forceFill(['thumbnail_path' => $written ? $thumbnailPath : null])->save();

            if (! $written) {
                GenerateThumbnail::dispatch($stored->id)->afterCommit();
            }
        }

        $stored->increment('reference_count');

        return $stored;
    }

    /**
     * Stores a photo given as JPEG/PNG contents, like handleImage().
     *
     * @throws \InvalidArgumentException When the contents are not an image.
     */
    public function handleImageBytes(string $contents): StoredFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'img');

        try {
            file_put_contents($path, $contents);

            return $this->handleImage(new UploadedFile($path, 'photo.jpg', null, null, true));
        } finally {
            @unlink($path);
        }
    }

    /**
     * Stores generated content (e.g. a PDF) once, under a name derived from its hash.
     */
    public function handleContents(string $contents, string $mimeType, string $extension): StoredFile
    {
        $hash = hash('sha256', $contents);

        $stored = StoredFile::query()->firstOrCreate(['sha256' => $hash], [
            'disk' => self::DISK,
            'path' => 'files/'.substr($hash, 0, 2).'/'.$hash.'.'.$extension,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'reference_count' => 0,
        ]);

        if (! Storage::disk($stored->disk)->exists($stored->path)) {
            Storage::disk($stored->disk)->put($stored->path, $contents);
        }

        $stored->increment('reference_count');

        return $stored;
    }

    /**
     * Drops one reference; the content is deleted when nothing uses it any more.
     */
    public function release(StoredFile $stored): void
    {
        if ($stored->reference_count > 1) {
            $stored->decrement('reference_count');

            return;
        }

        Storage::disk($stored->disk)->delete(array_filter([$stored->path, $stored->thumbnail_path]));
        $stored->delete();
    }
}
