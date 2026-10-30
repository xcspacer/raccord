<?php

namespace App\Models;

use App\Enums\BillingFormType;
use Database\Factories\BillingClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer the technicians' interventions are billed to, with the form its breakdowns use, its ticket number
 * format and its rates.
 *
 * @property int $id
 * @property int|null $client_id
 * @property string $name
 * @property string $sheet_label
 * @property string|null $works_sheet_label
 * @property BillingFormType $form_type
 * @property bool $has_breakdown
 * @property bool $has_works
 * @property bool $has_travel_fee
 * @property string|null $ticket_pattern
 * @property string|null $ticket_hint
 * @property string|null $ticket_example
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['name', 'sheet_label', 'works_sheet_label', 'form_type', 'has_breakdown', 'has_works', 'has_travel_fee', 'ticket_pattern', 'ticket_hint', 'ticket_example', 'sort_order', 'is_active'])]
class BillingClient extends Model
{
    /** @use HasFactory<BillingClientFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['form_type' => BillingFormType::class, 'has_breakdown' => 'boolean', 'has_works' => 'boolean', 'has_travel_fee' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<BillingInterventionType, $this>
     */
    public function interventionTypes(): HasMany
    {
        return $this->hasMany(BillingInterventionType::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<BillingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(BillingRate::class);
    }

    /**
     * Whether a ticket number matches the client's format (any ticket when it has none).
     */
    public function acceptsTicket(string $ticket): bool
    {
        if ($this->ticket_pattern === null || $this->ticket_pattern === '') {
            return true;
        }

        return @preg_match('/'.str_replace('/', '\/', $this->ticket_pattern).'/i', mb_strtoupper(trim($ticket))) === 1;
    }
}
