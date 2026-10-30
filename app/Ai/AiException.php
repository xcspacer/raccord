<?php

namespace App\Ai;

use RuntimeException;

/**
 * An AI request that could not be served. The reason is one of: not_configured, quota_reached,
 * unavailable, unreadable_answer, invalid_image, disabled (the feature is switched off in the settings).
 */
class AiException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
