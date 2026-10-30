<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Enums\EquipmentReadStatus;
use App\Enums\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads the label photographed on an inventory line and fills it: designation, category, brand, model and serial
 * number. When the AI cannot be used the line is left for the person to fill in, with the reason. A serial number
 * already registered is never duplicated: on the same person's equipment the photo joins the existing line, elsewhere
 * the number is left out and the line is flagged to check.
 */
class EquipmentLabelReader
{
    public function __construct(private AnthropicClient $client, private AiQuota $quota) {}

    public function read(User $user, InventoryItem $item): void
    {
        $photo = $item->photos()->with('file')->first();

        try {
            $file = $photo?->file;

            if ($file === null) {
                throw new AiException('invalid_image');
            }

            if (! $this->client->isConfigured()) {
                throw new AiException('not_configured');
            }

            $this->quota->consume($user, AiFeature::ReadEquipmentLabel);

            $contents = Storage::disk($file->disk)->get($file->path);

            if ($contents === null || strlen($contents) > 6 * 1024 * 1024) {
                throw new AiException('invalid_image');
            }

            $data = $this->client->askForJson($this->prompt(), [['data' => base64_encode($contents), 'media_type' => 'image/jpeg']], 300);
        } catch (AiException $exception) {
            $item->update(['label' => __('inventory.label.unnamed'), 'read_status' => EquipmentReadStatus::Manual, 'read_message' => $exception->reason]);

            return;
        }

        $category = match ((string) ($data['type'] ?? '')) {
            'welder' => InventoryCategory::Welder,
            'drill' => InventoryCategory::PowerTool,
            default => InventoryCategory::HandTool,
        };
        $brand = $this->text($data['brand'] ?? null);
        $model = $this->text($data['model'] ?? null);
        $serial = $this->text($data['serial_number'] ?? null);
        $doubtful = ($data['confidence'] ?? 'medium') === 'low';
        $uncertainty = null;

        if ($serial !== null) {
            $normalized = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $serial) ?? '');
            $existing = $normalized === '' ? null : InventoryItem::query()->where('serial_normalized', $normalized)->where('status', '!=', 'retired')->whereKeyNot($item->id)->first();

            if ($existing !== null && $existing->holder_id === $user->id) {
                $photo->update(['inventory_item_id' => $existing->id]);
                $item->forceDelete();

                return;
            }

            if ($existing !== null) {
                $uncertainty = __('inventory.label.serial_exists', ['reference' => $existing->reference(), 'serial' => $serial]);
                $serial = null;
                $doubtful = true;
            }
        }

        $item->update([
            'label' => mb_substr(trim($brand.' '.$model), 0, 120) ?: $category->label(),
            'category' => $category->value,
            'brand' => $brand,
            'model' => $model,
            'serial' => $serial,
            'is_serialized' => $serial !== null,
            'uncertainty' => $uncertainty,
            'needs_check' => $doubtful,
            'read_status' => $doubtful ? EquipmentReadStatus::Check : EquipmentReadStatus::Ok,
            'read_message' => null,
        ]);
    }

    private function text(mixed $value): ?string
    {
        $text = mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, 60);

        return $text === '' ? null : $text;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
You are reading the photo of a label or rating plate stuck on a tool or piece of equipment used on telecom work sites (fibre-optic fusion splicer, drill, or any other tool).
Answer ONLY with a JSON object, no text around it, of this shape:
{"type":"welder|drill|tool","brand":"...","model":"...","serial_number":"...","confidence":"high|medium|low"}
Rules:
- type: welder if it is a fibre-optic fusion splicer, drill if it is a drill or screwdriver, otherwise tool.
- brand: the manufacturer written on the label (e.g. Fujikura, Sumitomo, Bosch, Makita, DeWalt). Empty string if unreadable.
- model: the exact reference or model displayed (e.g. 90S+, GBH 2-26). Empty string if unreadable.
- serial_number: the exact serial number (S/N, Serial No.), digits and letters. Empty string if unreadable or absent.
- confidence: low if any field is uncertain.
Never invent a value: if you cannot read it clearly, use an empty string.
PROMPT;
    }
}
