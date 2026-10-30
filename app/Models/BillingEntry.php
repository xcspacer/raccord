<?php

namespace App\Models;

use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingSchedule;
use Database\Factories\BillingEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A billable intervention declared by a technician (« pointage »): a breakdown or works for a billing client, with
 * the ticket, the partners who were there and what was done.
 *
 * @property int $id
 * @property string $uuid
 * @property BillingEntryType $type
 * @property int $billing_client_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $ticket
 * @property bool $is_alone
 * @property int|null $billing_intervention_type_id
 * @property int|null $level
 * @property BillingSchedule|null $schedule
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $billing_operator_id
 * @property BillingClosureStatus|null $closure_status
 * @property string $comment
 * @property Carbon $created_at
 * @property-read BillingClient $billingClient
 * @property-read User $user
 * @property-read BillingInterventionType|null $interventionType
 * @property-read BillingOperator|null $operator
 */
#[Fillable(['uuid', 'type', 'billing_client_id', 'user_id', 'work_date', 'ticket', 'is_alone', 'billing_intervention_type_id', 'level', 'schedule', 'started_at', 'ended_at', 'billing_operator_id', 'closure_status', 'comment'])]
class BillingEntry extends Model
{
    /** @use HasFactory<BillingEntryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BillingEntryType::class,
            'work_date' => 'date',
            'is_alone' => 'boolean',
            'schedule' => BillingSchedule::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'closure_status' => BillingClosureStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BillingClient, $this>
     */
    public function billingClient(): BelongsTo
    {
        return $this->belongsTo(BillingClient::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * People who worked with the technician on this ticket.
     *
     * @return BelongsToMany<User, $this>
     */
    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'billing_entry_user');
    }

    /**
     * @return BelongsTo<BillingInterventionType, $this>
     */
    public function interventionType(): BelongsTo
    {
        return $this->belongsTo(BillingInterventionType::class, 'billing_intervention_type_id');
    }

    /**
     * @return BelongsTo<BillingOperator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(BillingOperator::class, 'billing_operator_id');
    }

    /**
     * @return HasMany<BillingEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingEntryLine::class);
    }

    /**
     * @return HasMany<BillingEntryPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(BillingEntryPhoto::class);
    }
}
