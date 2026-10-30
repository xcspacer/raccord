<?php

namespace App\DesignOfficeWorkflow\Dossier;

use Illuminate\Support\Facades\Storage;

/**
 * Free space of the private disk the dossiers are written to. Below the minimum of the configuration
 * (DOSSIER_MIN_FREE_MEGABYTES) the closure tab warns and a generation stops before writing anything.
 */
class DossierDiskSpace
{
    private const string DISK = 'local';

    /**
     * Free bytes, or null when the system does not tell.
     */
    public function free(): ?int
    {
        $free = @disk_free_space(Storage::disk(self::DISK)->path(''));

        return $free === false ? null : (int) $free;
    }

    public function minimum(): int
    {
        return max(0, (int) config('platform.dossier_min_free_megabytes')) * 1024 * 1024;
    }

    public function isLow(): bool
    {
        $free = $this->free();

        return $free !== null && $free < $this->minimum();
    }
}
