<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 * @property string|null $logo_path
 * @property string|null $portal_subtitle
 * @property string $locale Language of the documents delivered to this client (the official audit dossier).
 */
#[Fillable(['name', 'code', 'is_active', 'logo_path', 'portal_subtitle', 'locale', 'legacy_id'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<WorkSite, $this>
     */
    public function workSites(): HasMany
    {
        return $this->hasMany(WorkSite::class);
    }

    /**
     * @return HasMany<ClientAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class);
    }

    /**
     * Public URL of the client's logo (portal sign-in and header), or null when none is uploaded.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || ! Storage::disk('local')->exists($this->logo_path)) {
            return null;
        }

        return route('portal.logo', ['portalClient' => $this->portalSlug(), 'v' => substr(md5($this->logo_path), 0, 8)]);
    }

    /**
     * The client's part of its portal address (/portal/<slug>).
     */
    public function portalSlug(): string
    {
        return strtolower($this->code);
    }
}
