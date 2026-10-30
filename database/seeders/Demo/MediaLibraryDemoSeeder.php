<?php

namespace Database\Seeders\Demo;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\MediaPhase;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Invented media library: two cabinets with their own folder and boxes, and a few plain coloured photos.
 */
class MediaLibraryDemoSeeder extends Seeder
{
    public function run(StoreUploadedFile $files): void
    {
        if (MediaFolder::query()->exists()) {
            return;
        }

        $uploader = User::query()->where('username', 'tech1')->first();
        $colours = [[46, 125, 50], [242, 161, 4], [76, 143, 191], [228, 87, 46]];
        $index = 0;

        foreach (['PM301' => ['PM', 'BPE-3001', 'PBO-3002'], 'PM302' => ['PM', 'BPE-3101']] as $cabinetName => $folders) {
            $cabinet = MediaFolder::query()->create(['name' => $cabinetName]);

            foreach ($folders as $folderName) {
                $folder = MediaFolder::query()->create(['parent_id' => $cabinet->id, 'name' => $folderName]);

                foreach (MediaPhase::cases() as $phase) {
                    $at = now()->subDays(3 - $index % 3)->setTime(9 + $index % 6, 15);
                    $stored = $files->handleImageBytes($this->photo($colours[$index % count($colours)]));

                    $folder->files()->create([
                        'phase' => $phase, 'file_name' => $at->format('Ymd-His').'_demo_'.$index.'.jpg', 'stored_file_id' => $stored->id,
                        'uploaded_by' => $uploader?->id, 'uploader_name' => $uploader?->name, 'uploaded_at' => $at,
                    ]);
                    $index++;
                }
            }
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function photo(array $rgb): string
    {
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
        ob_start();
        imagejpeg($image, null, 82);

        return (string) ob_get_clean();
    }
}
