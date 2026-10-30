<?php

namespace App\MediaLibrary;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\MediaPhase;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The media library tree: cabinets, their folders (boxes, or « PM » for the cabinet itself) and the files of each
 * phase. Folders are rows, files are stored once; renaming or merging never touches the stored contents.
 *
 * @phpstan-type Entry array{path: string, file: StoredFile}
 */
class MediaLibrary
{
    public const int NAME_LENGTH = 80;

    /** Minutes during which the person who sent a photo may still delete it. */
    public const int OWN_DELETE_MINUTES = 15;

    public function __construct(private StoreUploadedFile $files) {}

    /**
     * A cabinet (no parent) or a folder inside a cabinet.
     *
     * @throws ValidationException
     */
    public function createFolder(?MediaFolder $cabinet, string $name, string $field = 'name'): MediaFolder
    {
        $name = $this->name($name, $field);
        $this->ensureFree($cabinet?->id, $name, null, $field);

        return MediaFolder::query()->create(['parent_id' => $cabinet?->id, 'name' => $name]);
    }

    /**
     * @throws ValidationException
     */
    public function rename(MediaFolder $folder, string $name, string $field = 'name'): void
    {
        $name = $this->name($name, $field);
        $this->ensureFree($folder->parent_id, $name, $folder->id, $field);

        $folder->update(['name' => $name]);
    }

    /**
     * Moves a folder into another of the same level, merging their contents: sub-folders of the same name are
     * merged in turn, and a file whose name is already taken keeps both under a new name.
     */
    public function merge(MediaFolder $source, MediaFolder $target): void
    {
        abort_if($source->id === $target->id || $source->isCabinet() !== $target->isCabinet(), 422);

        DB::transaction(function () use ($source, $target): void {
            $this->mergeInto($source, $target);
        });
    }

    /**
     * A folder and everything inside it; stored contents no longer used are deleted.
     */
    public function deleteFolder(MediaFolder $folder): void
    {
        DB::transaction(function () use ($folder): void {
            foreach ($folder->children()->get() as $child) {
                $this->deleteFolder($child);
            }

            foreach ($folder->files()->with('file')->get() as $file) {
                $this->deleteFile($file);
            }

            $folder->delete();
        });
    }

    /**
     * A photo sent into a folder: stored once (downscaled, without metadata), named « date-time_sender_random.jpg ».
     */
    public function store(MediaFolder $folder, MediaPhase $phase, UploadedFile $upload, User $user): MediaFile
    {
        $stored = $this->files->handleImage($upload);
        $now = now();

        return $folder->files()->create([
            'phase' => $phase,
            'file_name' => $now->copy()->utc()->format('Ymd-His').'_'.(Str::slug($user->name, '_') ?: 'technician').'_'.Str::lower(Str::random(8)).'.jpg',
            'stored_file_id' => $stored->id,
            'uploaded_by' => $user->id,
            'uploader_name' => $user->name,
            'uploaded_at' => $now,
        ]);
    }

    /**
     * The sender may delete their own photo for 15 minutes after sending it.
     */
    public function mayDeleteOwn(User $user, MediaFile $file): bool
    {
        return $file->uploaded_by === $user->id && $file->uploaded_at->gt(now()->subMinutes(self::OWN_DELETE_MINUTES));
    }

    public function deleteFile(MediaFile $file): void
    {
        $stored = $file->file;
        $file->delete();
        $this->files->release($stored);
    }

    /**
     * Files of a folder (cabinet or box) for a ZIP, under « cabinet/folder/phase/file ».
     *
     * @return list<Entry>
     */
    public function folderEntries(MediaFolder $folder): array
    {
        $boxes = $folder->isCabinet() ? $folder->children()->with('files.file')->get() : collect([$folder->load('files.file')]);
        $cabinet = $folder->isCabinet() ? $folder : $folder->parent;
        $entries = [];

        foreach ($boxes as $box) {
            foreach ($box->files as $file) {
                $entries[] = $this->entry($cabinet, $box, $file);
            }
        }

        return $entries;
    }

    /**
     * @return Entry
     */
    public function fileEntry(MediaFile $file): array
    {
        $box = $file->folder;

        return $this->entry($box->parent, $box, $file);
    }

    /**
     * @return Entry
     */
    private function entry(?MediaFolder $cabinet, MediaFolder $box, MediaFile $file): array
    {
        return ['path' => implode('/', [$cabinet->name ?? '_', $box->name, $file->phase->label(), $file->file_name]), 'file' => $file->file];
    }

    private function mergeInto(MediaFolder $source, MediaFolder $target): void
    {
        foreach ($source->children()->get() as $child) {
            $twin = $target->children()->where('name', $child->name)->first();

            if ($twin === null) {
                $child->update(['parent_id' => $target->id]);
            } else {
                $this->mergeInto($child, $twin);
            }
        }

        foreach ($source->files()->get() as $file) {
            $taken = $target->files()->where('phase', $file->phase->value)->where('file_name', $file->file_name)->exists();
            $file->update(['media_folder_id' => $target->id, 'file_name' => $taken ? $this->alternativeName($file->file_name) : $file->file_name]);
        }

        $source->delete();
    }

    /**
     * « photo.jpg » becomes « photo_3fa9c1.jpg ».
     */
    private function alternativeName(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return pathinfo($name, PATHINFO_FILENAME).'_'.Str::lower(Str::random(6)).($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Characters forbidden in file names are replaced, spaces and dots trimmed, 80 characters at most.
     *
     * @throws ValidationException
     */
    private function name(string $name, string $field): string
    {
        $clean = mb_substr(trim((string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F\x7F]+/u', '_', $name), " ._\t"), 0, self::NAME_LENGTH);

        if ($clean === '') {
            throw ValidationException::withMessages([$field => __('media_library.errors.name_required')]);
        }

        return $clean;
    }

    /**
     * @throws ValidationException
     */
    private function ensureFree(?int $parentId, string $name, ?int $except, string $field): void
    {
        $taken = MediaFolder::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => __('media_library.errors.exists')]);
        }
    }
}
