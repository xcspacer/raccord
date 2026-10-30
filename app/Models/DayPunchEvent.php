<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only journal of every day punch request (duplicates included).
 */
#[Fillable(['user_id', 'operation', 'date', 'duplicate', 'payload', 'created_at'])]
class DayPunchEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'duplicate' => 'boolean', 'date' => 'date', 'created_at' => 'datetime'];
    }
}
