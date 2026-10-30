<?php

namespace App\Actions\Inventory;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\InventoryMovementType;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\InventoryItemPhoto;
use App\Models\InventoryMovement;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Photos of an inventory line: added (by a manager or the holder), replaced or removed (managers). Removing only
 * archives the photo; the file stays.
 */
class ManagePhotos
{
    use LocksItem;

    public const int MAX_PER_UPLOAD = 8;

    public function __construct(private StoreUploadedFile $files, private MovementRecorder $movements) {}

    /**
     * @param  list<UploadedFile>  $uploads
     * @param  'photo'|'proof'  $kind
     * @return list<InventoryItemPhoto>
     *
     * @throws \InvalidArgumentException When a file is not an image.
     */
    public function add(User $actor, InventoryItem $item, array $uploads, string $kind = 'photo', ?InventoryMovement $attachTo = null): array
    {
        $stored = array_map(fn (UploadedFile $upload) => $this->files->handleImage($upload), array_slice($uploads, 0, self::MAX_PER_UPLOAD));

        return $this->attach($actor, $item, $stored, $kind, $attachTo);
    }

    /**
     * @param  list<StoredFile>  $stored  Files already stored (their reference is taken over by the photos).
     * @param  'photo'|'proof'  $kind
     * @return list<InventoryItemPhoto>
     */
    public function attach(User $actor, InventoryItem $item, array $stored, string $kind = 'photo', ?InventoryMovement $attachTo = null): array
    {
        if ($stored === []) {
            return [];
        }

        $photos = array_map(fn (StoredFile $file) => InventoryItemPhoto::query()->create([
            'inventory_item_id' => $item->id, 'stored_file_id' => $file->id, 'kind' => $kind, 'added_by' => $actor->id, 'created_at' => now(),
        ]), $stored);

        $ids = array_map(fn (InventoryItemPhoto $photo) => $photo->id, $photos);

        if ($attachTo !== null) {
            $attachTo->update(['details' => array_merge($attachTo->details ?? [], ['photos' => $ids])]);
        } else {
            $this->movements->record($actor, $item, InventoryMovementType::Photo, ['quantity' => count($ids), 'details' => ['photos' => $ids]]);
        }

        return $photos;
    }

    public function replace(User $actor, int $photoId, UploadedFile $upload): void
    {
        $photo = $this->photoOfLiveItem($photoId);
        $stored = $this->files->handleImage($upload);

        $photo->update(['stored_file_id' => $stored->id]);
        $this->movements->record($actor, $photo->item, InventoryMovementType::PhotoReplace, ['details' => ['photos' => [$photo->id]]]);
    }

    public function remove(User $actor, int $photoId): void
    {
        $photo = $this->photoOfLiveItem($photoId);

        $photo->update(['removed_at' => now()]);
        $this->movements->record($actor, $photo->item, InventoryMovementType::PhotoRemove, ['details' => ['photos' => [$photo->id]]]);
    }

    private function photoOfLiveItem(int $photoId): InventoryItemPhoto
    {
        $photo = InventoryItemPhoto::query()->whereNull('removed_at')->with('item')->findOrFail($photoId);

        if ($photo->item->trashed()) {
            throw new InventoryException('item_deleted');
        }

        return $photo;
    }
}
