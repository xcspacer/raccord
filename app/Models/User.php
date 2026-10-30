<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\RecordsChanges;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password
 * @property string|null $legacy_password_hash
 * @property Carbon|null $legacy_password_expires_at
 * @property bool $must_change_password
 * @property string|null $locale
 * @property int|null $agency_id
 * @property bool $all_agencies
 * @property int|null $company_id
 * @property bool $is_plannable
 * @property bool $is_hidden_from_planning
 * @property int $planning_order
 * @property Carbon|null $departure_date
 * @property Carbon|null $anonymised_at
 * @property bool $is_available_for_review
 */
#[Fillable(['name', 'username', 'email', 'phone', 'password', 'must_change_password', 'locale', 'agency_id', 'all_agencies', 'company_id', 'is_plannable', 'is_hidden_from_planning', 'planning_order', 'departure_date', 'legacy_id'])]
#[Hidden(['password', 'legacy_password_hash', 'legacy_password_expires_at', 'remember_token'])]
class User extends Authenticatable
{
    /**
     * Days an imported legacy password stays usable; afterwards an administrator sets a new one.
     */
    public const int LEGACY_PASSWORD_DAYS = 90;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    use RecordsChanges;

    /**
     * Database defaults, so the journal always records complete records.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['all_agencies' => false, 'is_plannable' => false, 'is_hidden_from_planning' => false, 'planning_order' => 0, 'must_change_password' => false, 'is_available_for_review' => false];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'legacy_password_expires_at' => 'datetime',
            'departure_date' => 'date',
            'anonymised_at' => 'datetime',
            'all_agencies' => 'boolean',
            'is_plannable' => 'boolean',
            'is_hidden_from_planning' => 'boolean',
            'planning_order' => 'integer',
            'is_available_for_review' => 'boolean',
        ];
    }

    /**
     * @return HasOne<BillingProfile, $this>
     */
    public function billingProfile(): HasOne
    {
        return $this->hasOne(BillingProfile::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasOne<Vehicle, $this>
     */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->latestOfMany();
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function expenseLines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    /**
     * @return HasMany<ExpenseReport, $this>
     */
    public function expenseReports(): HasMany
    {
        return $this->hasMany(ExpenseReport::class);
    }

    /**
     * @return HasMany<UserDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Absence, $this>
     */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function hasDeparted(?CarbonInterface $on = null): bool
    {
        return $this->departure_date !== null
            && $this->departure_date->lte(($on ?? now())->startOfDay());
    }

    /**
     * Administrators need a longer password than everyone else.
     */
    public function minimumPasswordLength(): int
    {
        return $this->hasRole(Role::Administrator->value) ? 12 : 10;
    }

    /**
     * Shown on the planning like a technician (technicians always are).
     */
    public function isPlanned(): bool
    {
        return $this->hasRole(Role::Technician->value) || $this->is_plannable;
    }

    public function isManager(): bool
    {
        return $this->hasAnyRole(array_map(fn (Role $role) => $role->value, Role::managers()));
    }

    public static function activityLogName(): string
    {
        return 'users';
    }
}
