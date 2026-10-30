<?php

namespace App\Policies;

use App\Enums\Module;
use App\Enums\Role;
use App\Models\InventoryAttestation;
use App\Models\InventoryItem;
use App\Models\User;
use App\Settings\ModuleSettings;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Certificates of handover: made by equipment managers; read by them and by the person each was made for (never the
 * design office). Someone else's certificate does not exist for a holder (404).
 */
class InventoryAttestationPolicy
{
    public function __construct(private ModuleSettings $modules) {}

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('manage', InventoryItem::class);
    }

    public function view(User $user, InventoryAttestation $attestation): Response
    {
        $ownCopy = $attestation->user_id === $user->id && ! $user->hasRole(Role::DesignOffice->value) && $this->modules->isEnabled(Module::Equipment);

        return $ownCopy || $this->create($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
