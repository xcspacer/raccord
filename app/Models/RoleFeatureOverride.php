<?php

namespace App\Models;

use App\Enums\Feature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * @property int $id
 * @property Feature $feature
 * @property int $role_id
 * @property bool $is_enabled
 */
#[Fillable(['feature', 'role_id', 'is_enabled'])]
class RoleFeatureOverride extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => Feature::class,
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
