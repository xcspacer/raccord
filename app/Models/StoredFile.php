<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Unique file content (one row per SHA-256 hash) on the private disk.
 *
 * @property int $id
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $thumbnail_path
 * @property int $reference_count
 */
#[Fillable(['sha256', 'disk', 'path', 'mime_type', 'extension', 'size_bytes', 'width', 'height', 'thumbnail_path', 'reference_count'])]
class StoredFile extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'reference_count' => 'integer',
        ];
    }
}
