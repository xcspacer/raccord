<div class="media-library">
    <nav class="media-library__crumbs" aria-label="{{ __('media_library.title') }}">
        <button type="button" class="media-library__crumb" wire:click="crumb('root')">{{ __('media_library.title') }}</button>
        @if ($cabinet)
            <span class="media-library__sep">/</span>
            <button type="button" class="media-library__crumb" wire:click="crumb('cabinet')">{{ $cabinet->name }}</button>
        @endif
        @if ($folder)
            <span class="media-library__sep">/</span>
            <button type="button" class="media-library__crumb" wire:click="crumb('folder')">{{ $folder->name === \App\Models\MediaFolder::CABINET ? __('media_library.cabinet_folder') : $folder->name }}</button>
        @endif
        @if ($currentPhase)
            <span class="media-library__sep">/</span>
            <span class="media-library__crumb is-current">{{ $currentPhase->label() }}</span>
        @endif
    </nav>

    @if ($level !== 'phases')
        <div class="media-library__toolbar">
            @if ($level !== 'files')
                @if ($creating)
                    <form class="media-library__inline" wire:submit="create">
                        <input class="field__input" wire:model="newName" maxlength="80" placeholder="{{ $level === 'cabinets' ? __('media_library.new_cabinet_name') : __('media_library.new_folder_name') }}" aria-label="{{ $level === 'cabinets' ? __('media_library.new_cabinet_name') : __('media_library.new_folder_name') }}" autofocus>
                        <button type="submit" class="btn btn--primary btn--small">{{ __('media_library.create') }}</button>
                        <button type="button" class="btn btn--ghost btn--small" wire:click="cancel">{{ __('media_library.cancel') }}</button>
                    </form>
                @else
                    <button type="button" class="btn btn--ghost btn--small" wire:click="startCreate">+ {{ $level === 'cabinets' ? __('media_library.new_cabinet') : __('media_library.new_folder') }}</button>
                @endif
            @else
                <span></span>
            @endif

            @if ($selected !== [])
                <form class="media-library__selection" method="POST" action="{{ route('media-library.zip') }}">
                    @csrf
                    @foreach ($selected as $item)
                        <input type="hidden" name="items[]" value="{{ $item }}">
                    @endforeach
                    <span>{{ __('media_library.selected', ['count' => count($selected)]) }}</span>
                    <button type="submit" class="btn btn--ghost btn--small">⬇ {{ __('media_library.download') }}</button>
                    <button type="button" class="btn btn--danger btn--small" wire:click="deleteSelected" wire:confirm="{{ __('media_library.confirm_delete_selected', ['count' => count($selected)]) }}">🗑 {{ __('media_library.delete') }}</button>
                </form>
            @endif
        </div>
        @error('newName') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        @error('renameName') <p class="field__error" role="alert">{{ $message }}</p> @enderror
    @endif

    @if ($level === 'cabinets' && $deliverables)
        <div class="media-library__grid media-library__grid--returns">
            <a class="media-library__folder media-library__open" href="{{ route('media-library.audits') }}" wire:navigate>📁 {{ __('media_library.deliverables.entry') }}</a>
        </div>
    @endif

    @if ($level === 'cabinets' || $level === 'folders')
        <div class="media-library__grid" x-data="mediaFolders" data-confirm="{{ __('media_library.confirm_move') }}">
            @foreach ($folders as $item)
                @php($label = $item->name === \App\Models\MediaFolder::CABINET && ! $item->isCabinet() ? __('media_library.cabinet_folder') : $item->name)
                <div class="media-library__folder" wire:key="folder-{{ $item->id }}" draggable="true" data-folder="{{ $item->id }}" data-label="{{ $label }}"
                     x-bind:class="overClass({{ $item->id }})" x-on:dragstart="start" x-on:dragover="enter" x-on:dragleave="leave" x-on:drop="drop" x-on:dragend="end">
                    @if ($renamingId === $item->id)
                        <form class="media-library__inline" wire:submit="rename">
                            <input class="field__input" wire:model="renameName" maxlength="80" aria-label="{{ __('media_library.rename_label', ['name' => $label]) }}" autofocus>
                            <button type="submit" class="btn btn--primary btn--small">{{ __('media_library.save') }}</button>
                            <button type="button" class="btn btn--ghost btn--small" wire:click="cancel">{{ __('media_library.cancel') }}</button>
                        </form>
                    @else
                        <label class="media-library__check"><input type="checkbox" value="folder:{{ $item->id }}" wire:model.live="selected" aria-label="{{ $label }}"></label>
                        <button type="button" class="media-library__open" wire:click="open({{ $item->id }})">📁 {{ $label }}</button>
                        <button type="button" class="media-library__icon" wire:click="startRename({{ $item->id }})" title="{{ __('media_library.rename') }}" aria-label="{{ __('media_library.rename') }}">✎</button>
                        <button type="button" class="media-library__icon media-library__icon--danger" wire:click="deleteFolder({{ $item->id }})" wire:confirm="{{ __('media_library.confirm_delete_folder', ['name' => $label]) }}" title="{{ __('media_library.delete') }}" aria-label="{{ __('media_library.delete') }}">✕</button>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($folders->isEmpty())
            <div class="empty-state">{{ $level === 'cabinets' ? __('media_library.no_cabinets') : __('media_library.no_folders') }}</div>
        @endif
    @elseif ($level === 'phases')
        <div class="media-library__grid">
            @foreach ($phases as $entry)
                <button type="button" class="media-library__phase" wire:click="openPhase('{{ $entry['phase']->value }}')">📁 {{ $entry['phase']->label() }} <span class="media-library__count">{{ $entry['count'] }}</span></button>
            @endforeach
        </div>
    @else
        @if ($files->isEmpty())
            <div class="empty-state">{{ __('media_library.no_files') }}</div>
        @else
            <div class="media-library__photos" data-file-group>
                @foreach ($files as $file)
                    <div class="media-library__photo" wire:key="file-{{ $file->id }}">
                        <label class="media-library__check media-library__check--photo"><input type="checkbox" value="file:{{ $file->id }}" wire:model.live="selected" aria-label="{{ $file->file_name }}"></label>
                        <button type="button" class="media-library__icon media-library__icon--danger media-library__icon--photo" wire:click="deleteFile({{ $file->id }})" wire:confirm="{{ __('media_library.confirm_delete_file') }}" title="{{ __('media_library.delete') }}" aria-label="{{ __('media_library.delete') }}">✕</button>
                        <a href="{{ route('media-library.files', [$file->id, 'full']) }}" target="_blank" rel="noreferrer" title="{{ $file->file_name }}" data-file-view data-file-name="{{ $file->file_name }}">
                            @if ($file->isImage())
                                <img src="{{ route('media-library.files', [$file->id, 'thumb']) }}" loading="lazy" alt="">
                            @else
                                <div class="media-library__no-image">📄</div>
                            @endif
                            <span class="media-library__name">{{ $this->size($file->file->size_bytes) }}</span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
