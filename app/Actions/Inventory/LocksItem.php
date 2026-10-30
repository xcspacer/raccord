<?php

namespace App\Actions\Inventory;

use App\Inventory\InventoryException;
use App\Models\InventoryItem;
use App\Models\User;

/**
 * Shared steps of the inventory operations: the line is read under a lock inside the operation's transaction,
 * so two people can never hand the same equipment over twice.
 */
trait LocksItem
{
    protected function lock(int $id, bool $allowDeleted = false): InventoryItem
    {
        $item = InventoryItem::withTrashed()->lockForUpdate()->findOrFail($id);

        if ($item->trashed() && ! $allowDeleted) {
            throw new InventoryException('item_deleted');
        }

        return $item;
    }

    /**
     * Line the operation applies to for a quantity: the line itself, or a new line cut off it.
     */
    protected function part(InventoryItem $item, ?int $quantity): InventoryItem
    {
        $quantity ??= $item->quantity;

        if ($quantity < 1) {
            throw new InventoryException('quantity_invalid');
        }

        if ($quantity > $item->quantity) {
            throw new InventoryException('quantity_too_high', ['max' => $item->quantity]);
        }

        if ($quantity === $item->quantity) {
            return $item;
        }

        if ($item->is_serialized) {
            throw new InventoryException('serialized_split');
        }

        return $item->splitOff($quantity);
    }

    protected function existingUser(int $id): User
    {
        $user = User::query()->find($id);

        if ($user === null || $user->hasRole('design_office')) {
            throw new InventoryException('bad_recipient');
        }

        return $user;
    }

    protected function clean(?string $text, int $max): ?string
    {
        $text = mb_substr(trim((string) $text), 0, $max);

        return $text === '' ? null : $text;
    }
}
