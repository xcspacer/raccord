<?php

namespace App\Models;

use App\Enums\ClientRemarkSide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message in the thread of a client portal note, from the client or from the company's staff.
 *
 * @property int $id
 * @property int $client_remark_id
 * @property ClientRemarkSide $author_side
 * @property int|null $user_id
 * @property int|null $client_account_id
 * @property string $author_name
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['author_side', 'user_id', 'client_account_id', 'author_name', 'body', 'created_at'])]
class ClientRemarkReply extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['author_side' => ClientRemarkSide::class];
    }
}
