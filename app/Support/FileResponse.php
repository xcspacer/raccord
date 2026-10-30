<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a stored file to the browser. Files never run as a page of the app: every response is sandboxed by its
 * Content-Security-Policy, except PDFs opened in the browser (the browsers' PDF viewers refuse sandboxed
 * documents; they run apart from the app anyway). When the web server is set up for it, the file itself is sent
 * by nginx (X-Accel-Redirect) instead of PHP.
 */
class FileResponse
{
    public const string SANDBOX_POLICY = "default-src 'none'; sandbox";

    /**
     * @param  array<string, string>  $headers
     * @param  'inline'|'attachment'  $disposition
     */
    public static function make(string $disk, string $path, ?string $name = null, array $headers = [], string $disposition = 'attachment'): Response
    {
        $storage = Storage::disk($disk);
        $name = str_replace(['/', '\\'], '-', $name ?? basename($path));

        $headers['Content-Type'] ??= $storage->mimeType($path) ?: 'application/octet-stream';
        $headers['Content-Disposition'] ??= HeaderUtils::makeDisposition($disposition, $name, str_replace('%', '', Str::ascii($name)));
        $headers['X-Content-Type-Options'] = 'nosniff';

        if ($disposition === 'attachment' || ! str_starts_with(strtolower($headers['Content-Type']), 'application/pdf')) {
            $headers['Content-Security-Policy'] = self::SANDBOX_POLICY;
        }

        $internalPrefix = self::internalPrefix($disk);

        if ($internalPrefix !== null) {
            return new Response('', 200, $headers + [
                'X-Accel-Redirect' => $internalPrefix.'/'.implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/')))),
            ]);
        }

        return $storage->response($path, $name, $headers, $disposition);
    }

    /**
     * The nginx « internal » location that serves this disk, when sending files through nginx is switched on.
     */
    private static function internalPrefix(string $disk): ?string
    {
        if (! config('filesystems.x_accel_redirect.enabled') || $disk !== 'local' || config('filesystems.disks.local.driver') !== 'local') {
            return null;
        }

        return rtrim((string) config('filesystems.x_accel_redirect.prefix'), '/');
    }
}
