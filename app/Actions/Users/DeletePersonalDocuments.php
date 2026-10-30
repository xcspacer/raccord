<?php

namespace App\Actions\Users;

use App\Models\StoredFile;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Support\Facades\DB;

class DeletePersonalDocuments
{
    /**
     * Erases for good every personal document of the person (BTP card, driving licence…), deleted ones included.
     * A stored content is shared by every record with the same hash: only the reference is dropped here, and the
     * content goes with the next orphan clean-up once nothing uses it any more. Returns how many were erased.
     */
    public function handle(User $user): int
    {
        $count = 0;

        UserDocument::query()->withTrashed()->where('user_id', $user->id)->get()->each(function (UserDocument $document) use (&$count): void {
            DB::transaction(function () use ($document): void {
                $document->forceDelete();
                StoredFile::query()->whereKey($document->stored_file_id)->where('reference_count', '>', 0)->decrement('reference_count');
            });

            $count++;
        });

        return $count;
    }
}
