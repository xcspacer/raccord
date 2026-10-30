<?php

namespace App\Logs;

use Illuminate\Support\Str;

/**
 * Groups the journal entries written by one request into a single « Logs » event.
 */
class ActivityBatch
{
    private ?string $id = null;

    public function id(): string
    {
        return $this->id ??= (string) Str::uuid();
    }
}
