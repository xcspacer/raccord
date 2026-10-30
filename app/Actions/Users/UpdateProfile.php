<?php

namespace App\Actions\Users;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\UserDocumentType;
use App\Models\User;
use App\Models\UserDocument;
use App\Support\UserSessions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UpdateProfile
{
    public function __construct(private StoreUploadedFile $files, private UserSessions $sessions) {}

    /**
     * Saves the person's own contact details, optional new password and personal documents.
     *
     * @param  array<string, UploadedFile|null>  $documents  Keyed by UserDocumentType value.
     */
    public function handle(User $user, ?string $email, ?string $phone, ?string $newPassword, array $documents, ?string $currentSessionId): void
    {
        DB::transaction(function () use ($user, $email, $phone, $newPassword, $documents): void {
            $attributes = ['email' => $email, 'phone' => $phone];

            if ($newPassword !== null) {
                $attributes += ['password' => Hash::make($newPassword), 'legacy_password_hash' => null, 'legacy_password_expires_at' => null, 'must_change_password' => false];
            }

            $user->forceFill($attributes)->save();

            foreach ($documents as $type => $file) {
                if ($file !== null) {
                    $this->replaceDocument($user, UserDocumentType::from($type), $file);
                }
            }
        });

        if ($newPassword !== null) {
            $this->sessions->keepOnlyCurrentDevice($user, $currentSessionId);
        }
    }

    private function replaceDocument(User $user, UserDocumentType $type, UploadedFile $file): void
    {
        $previous = $user->documents()->where('type', $type->value)->with('file')->get();

        $stored = $this->files->handle($file);

        $user->documents()->create([
            'type' => $type,
            'stored_file_id' => $stored->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
        ]);

        $previous->each(function (UserDocument $document): void {
            $file = $document->file;
            $document->forceDelete();
            $this->files->release($file);
        });
    }
}
