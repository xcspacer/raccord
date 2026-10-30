<?php

namespace App\Livewire\Inventory;

use App\Actions\Files\StoreUploadedFile;
use App\Actions\Files\UploadRules;
use App\Actions\Inventory\CreateItems;
use App\Actions\Inventory\ManagePhotos;
use App\Ai\AiException;
use App\Ai\InventoryPhotoAnalyzer;
use App\Enums\InventoryCategory;
use App\Enums\InventoryCondition;
use App\Enums\Role;
use App\Inventory\InventoryException;
use App\Inventory\InventoryListParser;
use App\Models\InventoryItem;
use App\Models\StoredFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Records several equipment lines at once, for managers: from photos analysed by the AI, or from a pasted or
 * uploaded list. Both show a preview that is checked and corrected before anything is saved.
 */
class BulkAdd extends Component
{
    use WithFileUploads;

    public bool $open = false;

    #[Locked]
    public string $mode = 'photos';

    public string $holder = '';

    /** @var list<TemporaryUploadedFile> */
    public array $photos = [];

    public ?TemporaryUploadedFile $listFile = null;

    public string $text = '';

    /** @var list<int> */
    #[Locked]
    public array $storedIds = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $warnings = [];

    public bool $analysed = false;

    public string $initial = 'to_confirm';

    public string $site = '';

    public string $date = '';

    #[On('inventory-bulk')]
    public function openFor(string $mode, ?int $holderId = null): void
    {
        $this->authorize('manage', InventoryItem::class);
        abort_unless(in_array($mode, ['photos', 'import'], true), 404);

        $this->reset('photos', 'listFile', 'text', 'rows', 'warnings', 'analysed', 'site');
        $this->resetErrorBag();
        $this->release();
        $this->mode = $mode;
        $this->holder = $holderId === null ? '' : (string) $holderId;
        $this->initial = 'to_confirm';
        $this->date = CarbonImmutable::now(config('platform.timezone'))->toDateString();
        $this->open = true;
    }

    public function close(): void
    {
        $this->release();
        $this->reset('photos', 'listFile', 'text', 'rows', 'warnings', 'analysed');
        $this->open = false;
    }

    public function analyse(StoreUploadedFile $files, InventoryPhotoAnalyzer $analyzer): void
    {
        $this->authorize('manage', InventoryItem::class);
        abort_unless($this->mode === 'photos', 404);
        $this->resetErrorBag();

        $this->validate(['photos' => ['required', 'array', 'min:1'], 'photos.*' => UploadRules::photo(16384)]);

        $this->release();
        $stored = [];

        try {
            foreach (array_slice($this->photos, 0, InventoryPhotoAnalyzer::MAX_PHOTOS) as $photo) {
                $stored[] = $files->handleImage($photo);
            }
        } catch (InvalidArgumentException) {
            $this->addError('form', __('inventory.errors.invalid_image'));

            return;
        }

        $this->storedIds = array_map(fn (StoredFile $file) => $file->id, $stored);

        try {
            $result = $analyzer->analyse($this->actor(), $stored, $this->holderUser());
        } catch (AiException $exception) {
            $this->addError('form', __('inventory.ai_errors.'.$exception->reason));
            $this->release();

            return;
        }

        $this->rows = array_map(fn (array $proposal) => $proposal + ['include' => $proposal['match'] === null, 'condition' => 'unknown', 'vehicle' => ''], $result['proposals']);
        $this->warnings = $result['warnings'];
        $this->analysed = true;
        $this->photos = [];
    }

    public function updatedListFile(): void
    {
        $this->authorize('manage', InventoryItem::class);
        $this->validate(['listFile' => UploadRules::document(UploadRules::TEXT_LIST, 1024)]);

        $this->text = (string) file_get_contents($this->listFile->getRealPath());
        $this->listFile = null;
    }

    public function parse(InventoryListParser $parser): void
    {
        $this->authorize('manage', InventoryItem::class);
        abort_unless($this->mode === 'import', 404);
        $this->validate(['text' => ['required', 'string', 'max:200000']]);

        $this->rows = array_map(fn (array $line) => $line + ['include' => true, 'note' => $line['uncertainty'], 'match' => null, 'confidence' => '', 'photos' => []], array_slice($parser->parse($this->text), 0, CreateItems::MAX_LINES));
        $this->analysed = true;

        if ($this->rows === []) {
            $this->addError('text', __('inventory.import_nothing'));
            $this->analysed = false;
        }
    }

    public function addRow(): void
    {
        $this->authorize('manage', InventoryItem::class);

        $this->rows[] = ['include' => true, 'label' => '', 'category' => '', 'brand' => '', 'model' => '', 'serial' => '', 'quantity' => 1, 'needs_check' => false, 'note' => '', 'match' => null, 'confidence' => '', 'photos' => [], 'condition' => 'unknown', 'vehicle' => ''];
        $this->analysed = true;
    }

    public function save(CreateItems $create, ManagePhotos $photos): void
    {
        $this->authorize('manage', InventoryItem::class);
        $this->resetErrorBag();

        $this->validate([
            'initial' => ['required', Rule::in(['to_confirm', 'official'])], 'site' => ['nullable', 'string', 'max:80'], 'date' => ['nullable', 'date_format:Y-m-d'],
            'holder' => ['nullable', 'integer'],
            'rows.*.label' => ['nullable', 'string', 'max:120'], 'rows.*.category' => ['nullable', Rule::enum(InventoryCategory::class)], 'rows.*.brand' => ['nullable', 'string', 'max:60'],
            'rows.*.model' => ['nullable', 'string', 'max:60'], 'rows.*.serial' => ['nullable', 'string', 'max:80'], 'rows.*.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'rows.*.condition' => ['nullable', Rule::enum(InventoryCondition::class)], 'rows.*.note' => ['nullable', 'string', 'max:400'], 'rows.*.vehicle' => ['nullable', 'string', 'max:60'],
        ]);

        $selected = array_values(array_filter($this->rows, fn (array $row) => ! empty($row['include']) && trim((string) ($row['label'] ?? '')) !== ''));

        if ($selected === []) {
            $this->addError('form', __('inventory.nothing_selected'));

            return;
        }

        $holder = $this->holderUser();

        if ($this->holder !== '' && $holder === null) {
            $this->addError('form', __('inventory.errors.bad_recipient'));

            return;
        }

        try {
            $result = $create->handle($this->actor(), array_map(fn (array $row) => [
                'label' => $row['label'], 'category' => $row['category'] ?? '', 'brand' => $row['brand'] ?? '', 'model' => $row['model'] ?? '', 'serial' => $row['serial'] ?? '',
                'quantity' => $row['quantity'] ?? 1, 'condition' => $row['condition'] ?? 'unknown', 'needs_check' => ! empty($row['needs_check']), 'uncertainty' => $row['note'] ?? '', 'vehicle' => $row['vehicle'] ?? '',
            ], $selected), $holder, [
                'official' => $this->initial === 'official', 'date' => $this->date ?: null, 'site' => $this->site ?: null,
                'source' => $this->mode === 'photos' ? __('inventory.source_photos', ['date' => $this->date]) : __('inventory.source_import'),
            ]);
        } catch (InventoryException $exception) {
            $this->addError('form', $exception->translated());

            return;
        }

        $this->attachPhotos($photos, $selected, $result['created']);

        $message = trans_choice('inventory.bulk_saved', count($result['created']), ['count' => count($result['created'])]);

        if ($result['skipped'] !== []) {
            $message .= ' '.trans_choice('inventory.bulk_skipped', count($result['skipped']), ['count' => count($result['skipped'])]);
        }

        $this->storedIds = [];
        $this->close();
        $this->dispatch('inventory-changed');
        $this->dispatch('toast', message: $message);
    }

    public function render(): View
    {
        return view('livewire.inventory.bulk-add', [
            'people' => $this->open ? User::query()->whereDoesntHave('roles', fn ($roles) => $roles->where('name', Role::DesignOffice->value))->orderBy('name')->get(['id', 'name']) : [],
            'categories' => InventoryCategory::cases(),
            'conditions' => InventoryCondition::cases(),
            'maxPhotos' => InventoryPhotoAnalyzer::MAX_PHOTOS,
        ]);
    }

    /**
     * Each created line gets the photos its proposal refers to, or all of them when it refers to none. A photo kept
     * by several lines counts one reference per line; a photo nobody kept is released.
     *
     * @param  list<array<string, mixed>>  $selected
     * @param  list<InventoryItem>  $created
     */
    private function attachPhotos(ManagePhotos $photos, array $selected, array $created): void
    {
        if ($this->storedIds === [] || $created === []) {
            return;
        }

        $files = StoredFile::query()->whereIn('id', $this->storedIds)->get()->keyBy('id');
        $ordered = array_values(array_map(fn (int $id) => $files[$id], array_filter($this->storedIds, fn (int $id) => isset($files[$id]))));
        $uses = array_fill_keys(array_keys($ordered), 0);
        $byLabel = [];

        foreach ($selected as $row) {
            $byLabel[mb_strtolower(trim((string) $row['label']))][] = $row;
        }

        foreach ($created as $item) {
            $row = array_shift($byLabel[mb_strtolower($item->label)]) ?? [];
            $indexes = array_values(array_filter(array_map(fn (int $number) => $number - 1, (array) ($row['photos'] ?? [])), fn (int $index) => isset($ordered[$index])));
            $indexes = $indexes === [] ? array_keys($ordered) : $indexes;

            $photos->attach($this->actor(), $item, array_map(fn (int $index) => $ordered[$index], $indexes));

            foreach ($indexes as $index) {
                $uses[$index]++;
            }
        }

        foreach ($ordered as $index => $file) {
            if ($uses[$index] > 1) {
                $file->increment('reference_count', $uses[$index] - 1);
            } elseif ($uses[$index] === 0) {
                app(StoreUploadedFile::class)->release($file);
            }
        }

        $this->storedIds = [];
    }

    /**
     * Gives back the references of photos analysed but not kept.
     */
    private function release(): void
    {
        foreach (StoredFile::query()->whereIn('id', $this->storedIds)->get() as $file) {
            app(StoreUploadedFile::class)->release($file);
        }

        $this->storedIds = [];
    }

    private function holderUser(): ?User
    {
        if ($this->holder === '') {
            return null;
        }

        $user = User::query()->find((int) $this->holder);

        return $user === null || $user->hasRole(Role::DesignOffice->value) ? null : $user;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
