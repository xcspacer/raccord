<?php

namespace App\Models;

use App\Enums\EquipmentReadStatus;
use App\Enums\InventoryAttestationStatus;
use App\Enums\InventoryCondition;
use App\Enums\InventoryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A line of the company's equipment inventory: a tool, a device or a number of identical consumables, in stock
 * or held by a person. Nothing is physically deleted; every change is kept in the movements.
 *
 * @property int $id
 * @property string $label
 * @property string|null $category
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial
 * @property string|null $serial_normalized
 * @property bool $is_serialized
 * @property int $quantity
 * @property InventoryCondition $condition
 * @property bool $needs_check
 * @property string|null $uncertainty
 * @property string|null $notes
 * @property string|null $vehicle
 * @property InventoryStatus $status
 * @property EquipmentReadStatus|null $read_status
 * @property string|null $read_message
 * @property int|null $holder_id
 * @property Carbon|null $assigned_on
 * @property string|null $site
 * @property Carbon|null $noted_on
 * @property string|null $source
 * @property int|null $split_from_id
 * @property int|null $created_by
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $returned_at
 * @property array{kind: string, at: string, by: int|null, by_name: string|null, comment: string}|null $anomaly
 * @property array{kind: string, at: string, by: int|null, by_name: string|null, comment: string|null}|null $pending_request
 * @property int|null $deleted_by
 * @property string|null $deleted_comment
 * @property Carbon|null $deleted_at
 */
#[Fillable(['label', 'category', 'brand', 'model', 'serial', 'is_serialized', 'quantity', 'condition', 'needs_check', 'uncertainty', 'notes', 'vehicle', 'status', 'read_status', 'read_message', 'holder_id', 'assigned_on', 'site', 'noted_on', 'source', 'created_by', 'confirmed_by', 'confirmed_at', 'returned_at', 'anomaly', 'pending_request', 'deleted_by', 'deleted_comment'])]
class InventoryItem extends Model
{
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['quantity' => 1, 'is_serialized' => false, 'needs_check' => false, 'condition' => 'unknown'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_serialized' => 'boolean',
            'quantity' => 'integer',
            'condition' => InventoryCondition::class,
            'needs_check' => 'boolean',
            'status' => InventoryStatus::class,
            'read_status' => EquipmentReadStatus::class,
            'assigned_on' => 'date',
            'noted_on' => 'date',
            'confirmed_at' => 'datetime',
            'returned_at' => 'datetime',
            'anomaly' => 'array',
            'pending_request' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item): void {
            $item->serial_normalized = $item->serial === null ? null : (Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $item->serial) ?? '') ?: null);
        });
    }

    /**
     * Identifier shown to people (MAT-0001); numbers are never reused.
     */
    public function reference(): string
    {
        return 'MAT-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cuts a number of units off a multi-unit line into a new line of its own (the one the operation applies to);
     * this line keeps the rest.
     */
    public function splitOff(int $quantity): InventoryItem
    {
        $part = $this->replicate();
        $part->quantity = $quantity;
        $part->split_from_id = $this->id;
        $part->save();

        $this->update(['quantity' => $this->quantity - $quantity]);

        return $part;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Photos still shown on the line (equipment photos first, then proofs), oldest first.
     *
     * @return HasMany<InventoryItemPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(InventoryItemPhoto::class)->whereNull('removed_at')->orderByRaw("kind = 'proof'")->orderBy('id');
    }

    /**
     * @return BelongsToMany<InventoryAttestation, $this>
     */
    public function attestations(): BelongsToMany
    {
        return $this->belongsToMany(InventoryAttestation::class, 'inventory_attestation_item');
    }

    /**
     * Whether a signed handover certificate to the current holder covers this line (needs « attestations » loaded).
     */
    public function hasSignedHandover(): bool
    {
        return $this->holder_id !== null && $this->attestations->contains(fn (InventoryAttestation $attestation) => $attestation->isSigned() && $attestation->user_id === $this->holder_id);
    }

    /**
     * Whether only unsigned certificates cover this line for the current holder.
     */
    public function hasUnsignedHandoverOnly(): bool
    {
        return $this->bestHandoverStatus() === InventoryAttestationStatus::Unsigned;
    }

    /**
     * Whether the best certificate of the current holder is an old one whose signatures were never verified.
     */
    public function hasUnverifiedHandoverOnly(): bool
    {
        return $this->bestHandoverStatus() === InventoryAttestationStatus::Unverified;
    }

    /**
     * The strongest state among the current holder's certificates of this line: signed, then unverified, then
     * unsigned (needs « attestations » loaded).
     */
    private function bestHandoverStatus(): ?InventoryAttestationStatus
    {
        if ($this->holder_id === null) {
            return null;
        }

        $statuses = $this->attestations->where('user_id', $this->holder_id)->map(fn (InventoryAttestation $attestation) => $attestation->status);

        foreach ([InventoryAttestationStatus::Signed, InventoryAttestationStatus::Unverified, InventoryAttestationStatus::Unsigned] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
