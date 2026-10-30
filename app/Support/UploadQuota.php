<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Daily upload quota of a person (UPL-11): how many files and how many bytes they may send in a calendar day of the
 * company. Counted when the files arrive, before anything is done with them.
 */
class UploadQuota
{
    /**
     * Records the files when they fit in what is left of the day; nothing is recorded otherwise.
     */
    public function consume(string $owner, int $files, int $bytes): bool
    {
        $key = $this->key($owner);
        $expires = $this->today()->addDays(2);

        Cache::add($key.':files', 0, $expires);
        Cache::add($key.':bytes', 0, $expires);

        $usedFiles = (int) Cache::increment($key.':files', $files);
        $usedBytes = (int) Cache::increment($key.':bytes', $bytes);

        if ($usedFiles <= $this->maxFiles() && $usedBytes <= $this->maxBytes()) {
            return true;
        }

        Cache::decrement($key.':files', $files);
        Cache::decrement($key.':bytes', $bytes);

        return false;
    }

    /**
     * Seconds until the quota starts again (midnight in the company's timezone).
     */
    public function secondsUntilReset(): int
    {
        return max(1, (int) CarbonImmutable::now()->diffInSeconds($this->today()->addDay(), true));
    }

    public function maxFiles(): int
    {
        return (int) config('platform.upload_daily_files');
    }

    public function maxBytes(): int
    {
        return (int) config('platform.upload_daily_megabytes') * 1024 * 1024;
    }

    private function key(string $owner): string
    {
        return 'upload-quota:'.$owner.':'.$this->today()->toDateString();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('platform.timezone'))->startOfDay();
    }
}
