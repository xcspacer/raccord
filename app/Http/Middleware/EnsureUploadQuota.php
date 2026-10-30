<?php

namespace App\Http\Middleware;

use App\Support\UploadQuota;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploadQuota
{
    public function __construct(private UploadQuota $quota) {}

    /**
     * Usage: ->middleware('upload.quota') on routes that receive files. Over the daily quota of the person the files are
     * refused with a translated message: as a validation error for the Livewire uploads (so it shows under the field),
     * as 429 for the JSON endpoints (the phone keeps its queue and tries again later).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $files = array_values(array_filter(Arr::flatten($request->allFiles()), fn (mixed $file) => $file instanceof UploadedFile));

        if ($files === []) {
            return $next($request);
        }

        $bytes = array_sum(array_map(fn (UploadedFile $file) => (int) $file->getSize(), $files));

        if ($this->quota->consume($this->owner($request), count($files), $bytes)) {
            return $next($request);
        }

        $message = __('errors.upload_quota');

        if ($request->routeIs('livewire.upload-file')) {
            return response()->json(['message' => $message, 'errors' => ['files.0' => [$message], 'files' => [$message]]], 422);
        }

        return response()->json(['ok' => false, 'error' => 'upload_quota', 'message' => $message], 429, ['Retry-After' => (string) $this->quota->secondsUntilReset()]);
    }

    private function owner(Request $request): string
    {
        return match (true) {
            Auth::guard('web')->id() !== null => 'user:'.Auth::guard('web')->id(),
            Auth::guard('client')->id() !== null => 'client:'.Auth::guard('client')->id(),
            default => 'ip:'.$request->ip(),
        };
    }
}
