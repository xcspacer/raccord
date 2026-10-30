<?php

namespace App\Models;

use App\Enums\PlanningMode;
use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property PlanningMode $planning_mode
 * @property int|null $default_client_id
 * @property int $daily_crew_capacity
 * @property int $sort_order
 */
#[Fillable(['name', 'code', 'planning_mode', 'default_client_id', 'daily_crew_capacity', 'sort_order', 'legacy_id'])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planning_mode' => PlanningMode::class,
            'daily_crew_capacity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function defaultClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'default_client_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<WorkSite, $this>
     */
    public function workSites(): HasMany
    {
        return $this->hasMany(WorkSite::class);
    }
}
