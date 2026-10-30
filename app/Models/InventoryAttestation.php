<?php

namespace App\Models;

use App\Enums\InventoryAttestationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A certificate of handover of equipment to a person, kept as a PDF. It is « signed » only when both signatures
 * were drawn; a certificate brought over from a previous platform keeps its original PDF with signatures never
 * verified (never counted as signed). It is never edited or deleted.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property Carbon $delivered_on
 * @property string|null $observations
 * @property string $representative_name
 * @property bool $technician_signed
 * @property bool $representative_signed
 * @property InventoryAttestationStatus $status
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property int $stored_file_id
 * @property Carbon $created_at
 */
#[Fillable(['reference', 'user_id', 'delivered_on', 'observations', 'representative_name', 'technician_signed', 'representative_signed', 'status', 'created_by', 'created_by_name', 'stored_file_id', 'created_at'])]
class InventoryAttestation extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['delivered_on' => 'date', 'technician_signed' => 'boolean', 'representative_signed' => 'boolean', 'status' => InventoryAttestationStatus::class];
    }

    public function isSigned(): bool
    {
        return $this->status === InventoryAttestationStatus::Signed;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsToMany<InventoryItem, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'inventory_attestation_item')->withTrashed();
    }
}
