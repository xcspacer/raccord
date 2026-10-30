<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Enums\InventoryCategory;
use App\Enums\InventoryStatus;
use App\Models\InventoryItem;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Proposes inventory lines from photos of equipment: what is on the photos, compared with what is already in the
 * inventory. Nothing is saved; the manager checks and corrects the proposals first.
 */
class InventoryPhotoAnalyzer
{
    public const int MAX_PHOTOS = 8;

    private const int KNOWN_LINES = 120;

    public function __construct(private AnthropicClient $client, private AiQuota $quota) {}

    /**
     * @param  list<StoredFile>  $photos
     * @return array{proposals: list<array{label: string, category: string|null, brand: string, model: string, serial: string, quantity: int, confidence: string, needs_check: bool, note: string, match: string|null, photos: list<int>}>, warnings: list<string>}
     *
     * @throws AiException
     */
    public function analyse(User $actor, array $photos, ?User $holder): array
    {
        $photos = array_slice($photos, 0, self::MAX_PHOTOS);

        if ($photos === []) {
            throw new AiException('invalid_image');
        }

        if (! $this->client->isConfigured()) {
            throw new AiException('not_configured');
        }

        $this->quota->consume($actor, AiFeature::IdentifyInventory);

        $images = array_map(function (StoredFile $file): array {
            $contents = Storage::disk($file->disk)->get($file->path);

            if ($contents === null || strlen($contents) > 6 * 1024 * 1024) {
                throw new AiException('invalid_image');
            }

            return ['data' => base64_encode($contents), 'media_type' => 'image/jpeg'];
        }, $photos);

        $known = $this->known($holder);
        $data = $this->client->askForJson($this->prompt($known, count($photos)), $images, 3500, 110);

        return $this->normalise($data, count($photos), $known);
    }

    /**
     * @return array<int, InventoryItem> Lines the AI may match, keyed by id.
     */
    private function known(?User $holder): array
    {
        return InventoryItem::query()->where('status', '!=', InventoryStatus::Retired->value)
            ->when($holder !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('holder_id', $holder?->id)->orWhereNull('holder_id')))
            ->orderBy('label')->limit(self::KNOWN_LINES)->get()->keyBy('id')->all();
    }

    /**
     * @param  array<int, InventoryItem>  $known
     */
    private function prompt(array $known, int $photoCount): string
    {
        $inventory = $known === [] ? '(empty)' : implode("\n", array_map(fn (InventoryItem $item) => implode(' | ', [$item->reference(), $item->label, $item->brand, $item->model, $item->serial, 'x'.$item->quantity]), $known));
        $categories = implode(' | ', array_map(fn (InventoryCategory $category) => $category->value, InventoryCategory::cases()));
        $language = __('inventory.ai_language');

        return <<<PROMPT
You help a manager of a fibre-optic network contractor to inventory the equipment of a technician from :n photos (numbered from 1 in the order they were sent).
Answer ONLY with a JSON object, no text around it, of this shape:
{"proposals":[{"label":"...","category":"...","brand":"...","model":"...","serial":"...","qty":1,"confidence":"high|medium|low","verify":false,"note":"...","matchItemId":null,"photos":[1]}],"warnings":["..."]}
Rules:
- The same object seen on several photos is ONE line with qty 1 (list its photos in "photos"). Use qty above 1 only when several identical units are visible on the same photo; when unsure, use the minimum and set verify to true.
- category: exactly one of: {$categories}.
- Write brand, model and serial only when readable. Never invent a brand, a model or a serial number.
- If the object already exists in the existing inventory below, set matchItemId to its id (e.g. MAT-0012) and do not propose it as new.
- If something is uncertain set verify to true and explain in note. confidence is low when the designation itself is uncertain.
- Write note and warnings in {$language}; write label, brand and model as they are written on the equipment.
Existing inventory (id | designation | brand | model | serial | quantity):
{$inventory}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, InventoryItem>  $known
     * @return array{proposals: list<array{label: string, category: string|null, brand: string, model: string, serial: string, quantity: int, confidence: string, needs_check: bool, note: string, match: string|null, photos: list<int>}>, warnings: list<string>}
     */
    private function normalise(array $data, int $photoCount, array $known): array
    {
        $references = array_map(fn (InventoryItem $item) => $item->reference(), $known);
        $proposals = [];

        foreach ((array) ($data['proposals'] ?? []) as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $label = $this->text($proposal['label'] ?? null, 120);

            if ($label === '') {
                continue;
            }

            $category = InventoryCategory::tryFrom((string) ($proposal['category'] ?? ''))?->value;
            $match = in_array($proposal['matchItemId'] ?? null, $references, true) ? (string) $proposal['matchItemId'] : null;
            $photoIndexes = array_values(array_unique(array_filter(array_map('intval', (array) ($proposal['photos'] ?? [])), fn (int $index) => $index >= 1 && $index <= $photoCount)));

            $proposals[] = [
                'label' => $label,
                'category' => $category,
                'brand' => $this->text($proposal['brand'] ?? null, 60),
                'model' => $this->text($proposal['model'] ?? null, 60),
                'serial' => $this->text($proposal['serial'] ?? null, 80),
                'quantity' => max(1, min(999, (int) ($proposal['qty'] ?? 1))),
                'confidence' => in_array($proposal['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $proposal['confidence'] : 'medium',
                'needs_check' => (bool) ($proposal['verify'] ?? false),
                'note' => $this->text($proposal['note'] ?? null, 400),
                'match' => $match,
                'photos' => $photoIndexes,
            ];
        }

        $warnings = array_slice(array_values(array_filter(array_map(fn (mixed $warning) => $this->text($warning, 300), (array) ($data['warnings'] ?? [])))), 0, 10);

        return ['proposals' => $proposals, 'warnings' => $warnings];
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $max);
    }
}
