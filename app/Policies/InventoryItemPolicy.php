<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Enums\InventoryStatus;
use App\Enums\Module;
use App\Enums\Role;
use App\Models\InventoryItem;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\FeatureAccess;
use Illuminate\Auth\Access\Response;

/**
 * Managers (administrators and supervisors with the « equipment management » access) handle the whole inventory;
 * a person only sees and reports on the equipment they hold. Design office users never reach the inventory.
 */
class InventoryItemPolicy
{
    public function __construct(private FeatureAccess $access, private ModuleSettings $modules) {}

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    /**
     * Equipment the person neither manages nor holds does not exist for them (404).
     */
    public function view(User $user, InventoryItem $item): Response
    {
        return $this->manage($user) || $this->holds($user, $item) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Create, edit, confirm, assign, return, transfer, resolve, delete and restore.
     */
    public function manage(User $user, ?InventoryItem $item = null): bool
    {
        return $user->isManager() && $this->access->allows($user, Feature::EquipmentManagement);
    }

    /**
     * Photograph the label of equipment one holds, to have it noted in one's name.
     */
    public function note(User $user): bool
    {
        return ! $user->hasRole(Role::DesignOffice->value) && $this->modules->isEnabled(Module::Equipment);
    }

    /**
     * A person keeps control of what they noted themselves until a manager confirms it.
     */
    public function editOwn(User $user, InventoryItem $item): bool
    {
        return $this->holds($user, $item) && $item->status === InventoryStatus::ToConfirm && $item->created_by === $user->id;
    }

    /**
     * Declare an anomaly or ask the manager for something: the manager, or the holder of the equipment.
     */
    public function report(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) || $this->holds($user, $item);
    }

    /**
     * Confirm the handover of a line noted by its holder.
     */
    public function confirm(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed() && $item->status === InventoryStatus::ToConfirm;
    }

    /**
     * Hand out a line that is in stock.
     */
    public function assign(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed() && $item->status === InventoryStatus::Stock && $item->holder_id === null;
    }

    /**
     * Take back a line from its holder.
     */
    public function takeBack(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed() && $item->holder_id !== null;
    }

    /**
     * Move a held line to someone else, unless it is out of use or in repair.
     */
    public function transfer(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed() && $item->holder_id !== null && ! $this->isOutOfUse($item) && $item->status !== InventoryStatus::Repair;
    }

    /**
     * Settle a problem or an anomaly of the line.
     */
    public function resolve(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed() && ($item->status->isProblem() || $item->anomaly !== null);
    }

    /**
     * Close the holder's pending request.
     */
    public function closeRequest(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed() && $item->pending_request !== null && ! $item->status->isProblem();
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return ($this->manage($user) || $this->editOwn($user, $item)) && ! $item->trashed();
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function restore(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && $item->trashed();
    }

    /**
     * Replace or remove the photos of a line.
     */
    public function managePhotos(User $user, InventoryItem $item): bool
    {
        return $this->manage($user) && ! $item->trashed();
    }

    public function addPhotos(User $user, InventoryItem $item): bool
    {
        return ! $item->trashed() && $this->report($user, $item);
    }

    public function declareAnomaly(User $user, InventoryItem $item): bool
    {
        return ! $item->trashed() && $item->holder_id !== null && ! $this->isOutOfUse($item) && $this->report($user, $item);
    }

    /**
     * The holder (never a manager) asks for something about equipment assigned to them, one request at a time.
     */
    public function makeRequest(User $user, InventoryItem $item): bool
    {
        return ! $item->trashed() && $item->holder_id !== null && $item->pending_request === null && $item->status === InventoryStatus::Assigned
            && $this->report($user, $item) && ! $this->manage($user);
    }

    private function isOutOfUse(InventoryItem $item): bool
    {
        return in_array($item->status, [InventoryStatus::Lost, InventoryStatus::Damaged, InventoryStatus::Retired], true);
    }

    private function holds(User $user, InventoryItem $item): bool
    {
        return $item->holder_id === $user->id
            && ! $item->trashed()
            && ! $user->hasRole(Role::DesignOffice->value)
            && $this->modules->isEnabled(Module::Equipment);
    }
}
