<?php

namespace App\Http\Controllers\MediaLibrary;

use App\Audit\AuditDeliverables;
use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Support\FileResponse;
use App\Support\ZipDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\CompressionMethod;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

/**
 * Files and ZIPs of the « Retours travaux » tree; paths are looked up in the tree built from the database, never
 * on the disk.
 */
class AuditDeliverablesController extends Controller
{
    public const int MAX_ITEMS = 400;

    public function file(Request $request, AuditDeliverables $deliverables): Response
    {
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:1000']])['path'];
        $found = collect($deliverables->forPath($path)['files'] ?? [])->firstWhere('path', $path);
        abort_if($found === null, 404);

        /** @var StoredFile $stored */
        $stored = $found['file'];
        $thumb = $request->query('variant') === 'thumb' && $stored->thumbnail_path !== null;
        $inline = $thumb || in_array($stored->extension, ['jpg', 'jpeg', 'pdf'], true);

        return FileResponse::make($stored->disk, $thumb ? $stored->thumbnail_path : $stored->path, basename($path), [
            'Content-Type' => $thumb ? 'image/webp' : $stored->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    /**
     * Folders or files of the tree (one, or up to 400); folders inside another chosen folder are not repeated.
     */
    public function zip(Request $request, AuditDeliverables $deliverables, ZipDownload $zips): Response
    {
        $items = $request->validate([
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['string', 'max:1000'],
        ])['items'];

        $items = array_values(array_unique($items));
        $items = array_values(array_filter($items, fn (string $item) => ! collect($items)->contains(fn (string $other) => $other !== $item && str_starts_with($item, $other.'/'))));
        $entries = [];

        foreach ($items as $item) {
            foreach ($deliverables->forPath($item)['files'] ?? [] as $file) {
                if ($file['path'] === $item || str_starts_with($file['path'], $item.'/')) {
                    $entries[$file['path']] = $file['file'];
                }
            }
        }

        abort_if($entries === [], 404);

        $name = count($items) === 1 ? basename($items[0]) : __('media_library.deliverables.title').' '.now()->setTimezone(config('platform.timezone'))->toDateString();
        $zip = $this->simulatedZip($entries);

        return $zips->send('audit_deliverables', $name.'.zip', fn () => $zip['stream']->executeSimulation(), [
            'Content-Length' => (string) $zip['size'],
        ], count($entries), $zip['size']);
    }

    /**
     * The archive is stored without compression and the size of every file is known, so its exact size is worked out
     * before anything is sent and announced to the browser; the files are then streamed one after the other, without
     * building the archive first. A file missing from the disk is left out.
     *
     * @param  array<string, StoredFile>  $entries
     * @return array{stream: ZipStream, size: int}
     */
    private function simulatedZip(array $entries): array
    {
        $zip = new ZipStream(
            operationMode: OperationMode::SIMULATE_STRICT,
            outputStream: fopen('php://output', 'wb'),
            defaultCompressionMethod: CompressionMethod::STORE,
            sendHttpHeaders: false,
        );

        foreach ($entries as $path => $stored) {
            $disk = Storage::disk($stored->disk);

            try {
                $size = $disk->size($stored->path);
            } catch (UnableToRetrieveMetadata) {
                continue;
            }

            $zip->addFileFromCallback($path, fn () => $disk->readStream($stored->path) ?? throw new RuntimeException('Unreadable file '.$stored->id), exactSize: $size);
        }

        return ['stream' => $zip, 'size' => $zip->finish()];
    }
}
