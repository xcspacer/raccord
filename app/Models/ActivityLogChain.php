<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Head of one hash-chained journal of the activity log: the last entry and its hash. Locking this row serialises
 * the writers of the journal, so its chain never forks.
 *
 * @property int $id
 * @property string $log_name
 * @property int|null $last_activity_id
 * @property string|null $last_hash
 */
#[Fillable(['log_name', 'last_activity_id', 'last_hash'])]
class ActivityLogChain extends Model {}
