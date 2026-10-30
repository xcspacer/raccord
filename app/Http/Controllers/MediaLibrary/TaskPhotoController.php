<?php

namespace App\Http\Controllers\MediaLibrary;

use App\Actions\Files\UploadRules;
use App\Enums\MediaPhase;
use App\Http\Controllers\Controller;
use App\MediaLibrary\MediaLibrary;
use App\MediaLibrary\TaskMediaTargets;
use App\Models\MediaFile;
use App\Models\User;
use App\Support\FileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before / after photos sent by technicians from the detail of their task, and their viewing.
 */
class TaskPhotoController extends Controller
{
    public function store(Request $request, TaskMediaTargets $targets, MediaLibrary $library): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'target' => ['required', 'string', 'max:80'],
            'phase' => ['required', Rule::enum(MediaPhase::class)],
            'file' => ['required', ...UploadRules::photo(15360)],
        ]);

        $target = $targets->find($user, $data['date'], $data['target']);
        abort_if($target === null, 403);

        $file = $library->store($targets->folder($target, create: true), MediaPhase::from($data['phase']), $request->file('file'), $user);

        return response()->json(['ok' => true, 'id' => $file->id]);
    }

    public function show(MediaFile $file, string $variant): Response
    {
        Gate::authorize('view', $file);

        $stored = $file->file;
        $thumb = $variant === 'thumb' && $stored->thumbnail_path !== null;

        return FileResponse::make($stored->disk, $thumb ? $stored->thumbnail_path : $stored->path, $file->file_name, [
            'Content-Type' => $thumb ? 'image/webp' : $stored->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
