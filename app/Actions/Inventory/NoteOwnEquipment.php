<?php

namespace App\Actions\Inventory;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\EquipmentReadStatus;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * A person photographs the label of equipment they hold: a line « noted, to confirm » is created in their name
 * with the photo, waiting for the AI to read the label. A manager confirms it later, like any noted equipment.
 */
class NoteOwnEquipment
{
    public function __construct(private StoreUploadedFile $files, private CreateItems $create, private ManagePhotos $photos) {}

    /**
     * @throws \InvalidArgumentException When the file is not an image.
     */
    public function handle(User $user, UploadedFile $upload): InventoryItem
    {
        $stored = $this->files->handleImage($upload);

        $item = $this->create->handle($user, [['label' => __('inventory.label.pending')]], $user, ['force_new' => true, 'source' => __('inventory.label.source')])['created'][0];
        $item->update(['read_status' => EquipmentReadStatus::Reading]);
        $this->photos->attach($user, $item, [$stored]);

        return $item;
    }
}
