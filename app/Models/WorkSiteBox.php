<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $work_site_id
 * @property string $reference
 * @property int|null $technician_id
 * @property int $position
 */
#[Fillable(['work_site_id', 'reference', 'technician_id', 'position'])]
class WorkSiteBox extends Model
{
    /**
     * @return BelongsTo<WorkSite, $this>
     */
    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_site_box_user');
    }
}
