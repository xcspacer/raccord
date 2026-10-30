<?php

namespace App\Http\Controllers;

use App\Enums\Feature;
use App\Enums\Module;
use App\Models\ExpenseLine;
use App\Models\InventoryItem;
use App\Models\InventoryItemPhoto;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\VehicleDamage;
use App\Models\VehiclePhoto;
use App\Settings\ModuleSettings;
use App\Support\FileResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vehicle inspection photos, damage photos and expense ticket photos and inventory photos, for the person they belong to and for managers only.
 * Expense ticket photos also follow the « Notes de frais » module and access.
 */
class VehicleImageController extends Controller
{
    public function __invoke(Request $request, string $type, int $id, string $variant): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($type === 'inventory') {
            $photo = InventoryItemPhoto::query()->with('item')->findOrFail($id);
            Gate::authorize('view', $photo->item);
            abort_if($photo->removed_at !== null && ! $user->can('manage', InventoryItem::class), 404);

            return $this->stream($photo->stored_file_id, $variant);
        }

        if ($type === 'expense') {
            abort_unless(app(ModuleSettings::class)->isEnabled(Module::ExpenseReports), 404);
            abort_unless($user->can('use-feature', Feature::ExpenseReports), 403);
        }

        $record = match ($type) {
            'photo' => VehiclePhoto::query()->findOrFail($id),
            'expense' => ExpenseLine::query()->findOrFail($id),
            default => VehicleDamage::query()->findOrFail($id),
        };
        Gate::authorize('view', $record);

        return $this->stream($record->stored_file_id, $variant);
    }

    private function stream(?int $storedFileId, string $variant): Response
    {
        $file = StoredFile::query()->find($storedFileId);
        abort_if($file === null, 404);

        $path = $variant === 'thumb' && $file->thumbnail_path !== null ? $file->thumbnail_path : $file->path;

        return FileResponse::make($file->disk, $path, null, [
            'Content-Type' => $variant === 'thumb' ? 'image/webp' : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
