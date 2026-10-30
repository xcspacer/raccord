<?php

namespace App\Inventory;

use RuntimeException;

/**
 * An inventory operation that cannot be done in the current state. The reason is a key of inventory.errors.
 */
class InventoryException extends RuntimeException
{
    /**
     * @param  array<string, scalar>  $replacements
     */
    public function __construct(public readonly string $reason, public readonly array $replacements = [])
    {
        parent::__construct($reason);
    }

    public function translated(): string
    {
        return __('inventory.errors.'.$this->reason, $this->replacements);
    }
}
