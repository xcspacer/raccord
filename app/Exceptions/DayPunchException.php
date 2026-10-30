<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A day punch request that cannot be applied; the code is shown to the person as a translated message.
 */
class DayPunchException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct($reason);
    }
}
