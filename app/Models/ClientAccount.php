<?php

namespace App\Models;

use Database\Factories\ClientAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A person of a client company who signs in to the client portal to follow its audits.
 *
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string $username
 * @property string $password
 * @property bool $must_change_password
 * @property bool $has_all_audits
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $notifications_seen_at
 * @property-read Client $client
 */
#[Fillable(['client_id', 'name', 'username', 'password', 'has_all_audits', 'is_active', 'last_login_at', 'notifications_seen_at'])]
#[Hidden(['password', 'remember_token'])]
class ClientAccount extends Authenticatable
{
    /** @use HasFactory<ClientAccountFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['must_change_password' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'has_all_audits' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'notifications_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Audits picked one by one (ignored when the account sees every audit, future ones included).
     *
     * @return BelongsToMany<Audit, $this>
     */
    public function audits(): BelongsToMany
    {
        return $this->belongsToMany(Audit::class);
    }

    /**
     * Audits the account may follow: those it was given (or all), with at least one box.
     *
     * @return Builder<Audit>
     */
    public function visibleAudits(): Builder
    {
        return Audit::query()
            ->whereHas('boxes')
            ->when(! $this->has_all_audits, fn (Builder $query) => $query->whereIn('id', $this->audits()->select('audits.id')));
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
