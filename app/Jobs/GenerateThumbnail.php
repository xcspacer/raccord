<?php

namespace App\Jobs;

use App\Actions\Files\ProcessImage;
use App\Models\StoredFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Makes again the thumbnail of a photo whose thumbnail could not be made when it was received, in the background and
 * with retries; the photo itself is never touched. Running it twice changes nothing.
 */
class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before each new attempt.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $storedFileId) {}

    public function handle(ProcessImage $images): void
    {
        $file = StoredFile::query()->find($this->storedFileId);

        if ($file === null || $file->mime_type !== 'image/jpeg') {
            return;
        }

        $disk = Storage::disk($file->disk);

        if ($file->thumbnail_path !== null && $disk->exists($file->thumbnail_path)) {
            return;
        }

        $thumbnail = $disk->exists($file->path) ? $images->thumbnail((string) $disk->get($file->path)) : null;
        $path = 'thumbs/'.substr($file->sha256, 0, 2).'/'.$file->sha256.'.webp';

        if ($thumbnail === null || ! $disk->put($path, $thumbnail)) {
            throw new RuntimeException("The thumbnail of stored file {$file->id} could not be made.");
        }

        $file->update(['thumbnail_path' => $path]);
    }
}
