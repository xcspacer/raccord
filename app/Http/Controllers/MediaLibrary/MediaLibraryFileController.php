<?php

namespace App\Http\Controllers\MediaLibrary;

use App\Http\Controllers\Controller;
use App\MediaLibrary\MediaLibrary;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Support\FileResponse;
use App\Support\ZipDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\ZipStream;

/**
 * Files of the media library: one file (inline or its thumbnail) and ZIPs of folders and files.
 */
class MediaLibraryFileController extends Controller
{
    public const int MAX_ITEMS = 400;

    public function show(MediaFile $file, string $variant): Response
    {
        Gate::authorize('view', $file);

        $stored = $file->file;
        $thumb = $variant === 'thumb' && $stored->thumbnail_path !== null;

        return FileResponse::make($stored->disk, $thumb ? $stored->thumbnail_path : $stored->path, $file->file_name, [
            'Content-Type' => $thumb ? 'image/webp' : $stored->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }

    /**
     * Items are « folder:<id> » (a cabinet or a folder) or « file:<id> »; unknown ones are skipped.
     */
    public function zip(Request $request, MediaLibrary $library, ZipDownload $zips): Response
    {
        Gate::authorize('viewAny', MediaFile::class);

        $items = $request->validate([
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['string', 'regex:/^(folder|file):\d+$/'],
        ])['items'];

        $entries = [];

        foreach (array_unique($items) as $item) {
            [$type, $id] = explode(':', $item);

            if ($type === 'folder' && ($folder = MediaFolder::query()->find((int) $id)) !== null) {
                array_push($entries, ...$library->folderEntries($folder));
            } elseif ($type === 'file' && ($file = MediaFile::query()->with(['file', 'folder.parent'])->find((int) $id)) !== null) {
                $entries[] = $library->fileEntry($file);
            }
        }

        abort_if($entries === [], 404);

        $entries = collect($entries)->unique('path')->values();

        return $zips->stream('media_library', __('media_library.zip_name').'.zip', function (ZipStream $zip) use ($entries): void {
            foreach ($entries as $entry) {
                ZipDownload::addStoredFile($zip, $entry['path'], $entry['file']);
            }
        }, $entries->count(), (int) $entries->sum(fn (array $entry) => $entry['file']->size_bytes));
    }
}
