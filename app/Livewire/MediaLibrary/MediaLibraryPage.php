<?php

namespace App\Livewire\MediaLibrary;

use App\Enums\Feature;
use App\Enums\MediaPhase;
use App\MediaLibrary\MediaLibrary;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Médiathèque »: before / after photos by cabinet and box. Navigate cabinet → folder → phase → files, create,
 * rename, merge by drag and drop, delete and download folders or files.
 */
class MediaLibraryPage extends Component
{
    #[Url(as: 'pm', except: null)]
    public ?int $cabinetId = null;

    #[Url(as: 'box', except: null)]
    public ?int $folderId = null;

    #[Url(as: 'phase', except: '')]
    public string $phase = '';

    /** @var list<string> « folder:<id> » or « file:<id> » */
    public array $selected = [];

    public bool $creating = false;

    public string $newName = '';

    #[Locked]
    public ?int $renamingId = null;

    public string $renameName = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function open(int $id): void
    {
        $folder = MediaFolder::query()->findOrFail($id);

        if ($folder->isCabinet()) {
            $this->go($folder->id, null, '');
        } else {
            $this->go($folder->parent_id, $folder->id, '');
        }
    }

    public function openPhase(string $phase): void
    {
        $this->go($this->cabinetId, $this->folderId, MediaPhase::tryFrom($phase)->value ?? '');
    }

    public function crumb(string $level): void
    {
        match ($level) {
            'cabinet' => $this->go($this->cabinetId, null, ''),
            'folder' => $this->go($this->cabinetId, $this->folderId, ''),
            default => $this->go(null, null, ''),
        };
    }

    public function startCreate(): void
    {
        $this->creating = true;
        $this->reset('newName', 'renamingId', 'renameName');
        $this->resetValidation();
    }

    public function create(MediaLibrary $library): void
    {
        $this->guard();
        $this->resetValidation();

        $library->createFolder($this->cabinet(), $this->newName, 'newName');
        $this->reset('creating', 'newName');
    }

    public function startRename(int $id): void
    {
        $folder = MediaFolder::query()->findOrFail($id);

        $this->renamingId = $folder->id;
        $this->renameName = $folder->name;
        $this->reset('creating', 'newName');
        $this->resetValidation();
    }

    public function rename(MediaLibrary $library): void
    {
        $this->guard();
        $this->resetValidation();

        $library->rename(MediaFolder::query()->findOrFail($this->renamingId), $this->renameName, 'renameName');
        $this->reset('renamingId', 'renameName');
    }

    public function cancel(): void
    {
        $this->reset('creating', 'newName', 'renamingId', 'renameName');
        $this->resetValidation();
    }

    /**
     * Drag and drop of a folder onto another of the same level: contents are merged.
     */
    public function move(int $sourceId, int $targetId, MediaLibrary $library): void
    {
        $this->guard();

        $library->merge(MediaFolder::query()->findOrFail($sourceId), MediaFolder::query()->findOrFail($targetId));
        $this->selected = [];
    }

    public function deleteFolder(int $id, MediaLibrary $library): void
    {
        $this->guard();

        $library->deleteFolder(MediaFolder::query()->findOrFail($id));
        $this->selected = array_values(array_diff($this->selected, ['folder:'.$id]));
    }

    public function deleteFile(int $id, MediaLibrary $library): void
    {
        $file = MediaFile::query()->with('file')->findOrFail($id);
        $this->authorize('delete', $file);

        $library->deleteFile($file);
        $this->selected = array_values(array_diff($this->selected, ['file:'.$id]));
    }

    public function deleteSelected(MediaLibrary $library): void
    {
        $this->guard();

        foreach ($this->selected as $item) {
            [$type, $id] = array_pad(explode(':', $item), 2, '0');

            if ($type === 'folder' && ($folder = MediaFolder::query()->find((int) $id)) !== null) {
                $library->deleteFolder($folder);
            } elseif ($type === 'file' && ($file = MediaFile::query()->with('file')->find((int) $id)) !== null) {
                $this->authorize('delete', $file);
                $library->deleteFile($file);
            }
        }

        $this->selected = [];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function render(): View
    {
        $this->guard();

        $cabinet = $this->cabinet();
        $folder = $this->folder($cabinet);
        $phase = $folder === null ? null : MediaPhase::tryFrom($this->phase);

        $data = match (true) {
            $cabinet === null => ['level' => 'cabinets', 'folders' => MediaFolder::query()->whereNull('parent_id')->orderBy('name')->get()],
            $folder === null => ['level' => 'folders', 'folders' => $cabinet->children()->orderBy('name')->get()],
            $phase === null => ['level' => 'phases', 'phases' => collect(MediaPhase::cases())->map(fn (MediaPhase $case) => ['phase' => $case, 'count' => $folder->files()->where('phase', $case->value)->count()])],
            default => ['level' => 'files', 'files' => $folder->files()->with('file')->where('phase', $phase->value)->orderByDesc('uploaded_at')->orderByDesc('id')->get()],
        };

        return view('livewire.media-library.media-library-page', $data + [
            'cabinet' => $cabinet,
            'folder' => $folder,
            'currentPhase' => $phase,
            'level' => $data['level'],
            'deliverables' => Gate::allows('open-feature', Feature::Audits),
        ])->layout('components.layouts.app', ['title' => __('media_library.title'), 'page' => 'media-library']);
    }

    /**
     * Human file size, as the legacy screen showed it (« 820 Ko », « 2.4 Mo »).
     */
    public function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => __('media_library.size.bytes', ['size' => $bytes]),
            $bytes < 1024 * 1024 => __('media_library.size.kilobytes', ['size' => (int) round($bytes / 1024)]),
            default => __('media_library.size.megabytes', ['size' => number_format($bytes / 1024 / 1024, 1)]),
        };
    }

    private function go(?int $cabinetId, ?int $folderId, string $phase): void
    {
        $this->cabinetId = $cabinetId;
        $this->folderId = $folderId;
        $this->phase = $phase;
        $this->selected = [];
        $this->cancel();
    }

    private function cabinet(): ?MediaFolder
    {
        return $this->cabinetId === null ? null : MediaFolder::query()->whereNull('parent_id')->find($this->cabinetId);
    }

    private function folder(?MediaFolder $cabinet): ?MediaFolder
    {
        return $cabinet === null || $this->folderId === null ? null : $cabinet->children()->find($this->folderId);
    }

    private function guard(): void
    {
        $this->authorize('manage', MediaFolder::class);
    }
}
