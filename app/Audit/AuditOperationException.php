<?php

namespace App\Audit;

use RuntimeException;

/**
 * An audit operation that was refused. The code is a stable key (« not_found », « validated »…) the clients
 * understand; the status follows HTTP: 403 not allowed, 404 unknown, 409 conflict with the current state, 422 invalid.
 * A conflict between devices carries who changed the field meanwhile.
 */
class AuditOperationException extends RuntimeException
{
    /**
     * @param  array{field: string, by: string|null, at: string}|null  $conflict
     */
    public function __construct(public readonly string $reason, public readonly int $status = 422, public readonly ?array $conflict = null)
    {
        parent::__construct($reason);
    }
}
