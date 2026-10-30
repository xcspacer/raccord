<?php

namespace App\Support;

use App\Logs\SecurityLog;
use App\Models\StoredFile;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Every ZIP download: streamed without a temporary file, one at a time per person (a second one while the first is
 * still running is refused with an explicit message) and written to the security log (who, which ZIP, how big).
 */
class ZipDownload
{
    public const string LOG_EVENT = 'zip_download';

    /** The lock is released when the ZIP ends; this only bounds it if the process dies. */
    public const int LOCK_SECONDS = 3600;

    public function __construct(private SecurityLog $log) {}

    /**
     * @param  string  $kind  what is downloaded (media_library, audit_deliverables, client_portal, billing_…)
     * @param  Closure(ZipStream): void  $write  adds the entries
     * @param  int|null  $entries  number of entries, when known before writing
     * @param  int|null  $bytes  size of the stored files put in it, when known before writing
     */
    public function stream(string $kind, string $fileName, Closure $write, ?int $entries = null, ?int $bytes = null): Response
    {
        return $this->send($kind, $fileName, function () use ($write): void {
            $zip = new ZipStream(outputStream: fopen('php://output', 'wb'), defaultCompressionMethod: CompressionMethod::STORE, sendHttpHeaders: false);
            $write($zip);
            $zip->finish();
        }, [], $entries, $bytes);
    }

    /**
     * Sends a ZIP written to the output by $output (for archives prepared by the caller).
     *
     * @param  Closure(): mixed  $output
     * @param  array<string, string>  $headers
     */
    public function send(string $kind, string $fileName, Closure $output, array $headers = [], ?int $entries = null, ?int $bytes = null): Response
    {
        $lock = Cache::lock(self::lockKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return response()->view('errors.notice', ['status' => 'zip_busy'], 429);
        }

        $this->log->info(self::LOG_EVENT, ['zip' => $kind, 'file_name' => $fileName, 'entries' => $entries, 'bytes' => $bytes]);

        return response()->streamDownload(function () use ($lock, $output): void {
            register_shutdown_function(fn () => $lock->release());

            try {
                $output();
            } finally {
                $lock->release();
            }
        }, $fileName, $headers + ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Adds a stored file; a file missing on the disk is left out.
     */
    public static function addStoredFile(ZipStream $zip, string $path, StoredFile $file): void
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);

        if (is_resource($stream)) {
            $zip->addFileFromStream($path, $stream);
            fclose($stream);
        }
    }

    /**
     * The lock of the person asking: a staff member, else a client portal account, else the address.
     */
    private static function lockKey(): string
    {
        $owner = match (true) {
            Auth::guard('web')->id() !== null => 'user:'.Auth::guard('web')->id(),
            Auth::guard('client')->id() !== null => 'client:'.Auth::guard('client')->id(),
            default => 'ip:'.request()->ip(),
        };

        return 'zip-download:'.$owner;
    }
}
