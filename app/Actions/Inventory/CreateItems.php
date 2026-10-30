<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records equipment lines (by hand, from a list or from photos). Lines that cannot be recorded are skipped and
 * reported: no designation, serial number already registered, same equipment already registered, unknown holder.
 */
class CreateItems
{
    public const int MAX_LINES = 150;

    public function __construct(private MovementRecorder $movements) {}

    /**
     * @param  list<array<string, mixed>>  $rows  label, category, brand, model, serial, quantity, condition, needs_check, uncertainty, notes, vehicle
     * @param  array{official?: bool, date?: string|null, site?: string|null, source?: string|null, force_new?: bool}  $options
     * @return array{created: list<InventoryItem>, skipped: list<array{label: string, reason: string, existing: string|null}>}
     */
    public function handle(User $actor, array $rows, ?User $holder, array $options = []): array
    {
        if (count($rows) > self::MAX_LINES) {
            throw new InventoryException('too_many_lines', ['max' => self::MAX_LINES]);
        }

        return DB::transaction(function () use ($actor, $rows, $holder, $options): array {
            $created = [];
            $skipped = [];
            $keys = [];
            $holderKey = $holder === null ? '' : (string) $holder->id;

            foreach ($rows as $row) {
                $label = $this->text($row['label'] ?? null, 120);

                if ($label === null) {
                    $skipped[] = ['label' => '', 'reason' => 'label', 'existing' => null];

                    continue;
                }

                $serial = $this->text($row['serial'] ?? null, 80);
                $normalized = $serial === null ? null : (Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $serial) ?? '') ?: null);
                $serialized = $serial !== null || (bool) ($row['is_serialized'] ?? false);

                if ($normalized !== null) {
                    $existing = InventoryItem::query()->where('serial_normalized', $normalized)->where('status', '!=', InventoryStatus::Retired->value)->first();

                    if ($existing !== null) {
                        $skipped[] = ['label' => $label, 'reason' => 'serial', 'existing' => $existing->reference()];

                        continue;
                    }
                }

                $brand = $this->text($row['brand'] ?? null, 60);
                $model = $this->text($row['model'] ?? null, 60);

                if (! $serialized && ! ($options['force_new'] ?? false)) {
                    $key = Str::lower($holderKey.'|'.$label.'|'.$brand.'|'.$model);
                    $duplicate = InventoryItem::query()->where('is_serialized', false)->where('label', $label)
                        ->where(fn ($query) => $brand === null ? $query->whereNull('brand') : $query->where('brand', $brand))
                        ->where(fn ($query) => $model === null ? $query->whereNull('model') : $query->where('model', $model))
                        ->where(fn ($query) => $holder === null ? $query->whereNull('holder_id') : $query->where('holder_id', $holder->id))
                        ->first();

                    if ($duplicate !== null || isset($keys[$key])) {
                        $skipped[] = ['label' => $label, 'reason' => 'duplicate', 'existing' => $duplicate?->reference() ?? $keys[$key]];

                        continue;
                    }
                }

                $official = $holder !== null && ($options['official'] ?? false);
                $status = $holder === null ? InventoryStatus::Stock : ($official ? InventoryStatus::Assigned : InventoryStatus::ToConfirm);
                $date = $this->date($options['date'] ?? null);
                $condition = InventoryCondition::tryFrom((string) ($row['condition'] ?? '')) ?? InventoryCondition::Unknown;

                $item = InventoryItem::query()->create([
                    'label' => $label,
                    'category' => $this->text($row['category'] ?? null, 60),
                    'brand' => $brand,
                    'model' => $model,
                    'serial' => $serial,
                    'is_serialized' => $serialized,
                    'quantity' => $serialized ? 1 : max(1, min(9999, (int) ($row['quantity'] ?? 1))),
                    'condition' => $condition,
                    'needs_check' => (bool) ($row['needs_check'] ?? false),
                    'uncertainty' => $this->text($row['uncertainty'] ?? null, 400),
                    'notes' => $this->text($row['notes'] ?? null, 600),
                    'vehicle' => $this->text($row['vehicle'] ?? null, 60),
                    'status' => $status,
                    'holder_id' => $holder?->id,
                    'assigned_on' => $holder === null ? null : ($official ? ($date ?? CarbonImmutable::now(config('platform.timezone'))->toDateString()) : $date),
                    'site' => $this->text($options['site'] ?? null, 80),
                    'noted_on' => $date,
                    'source' => $this->text($options['source'] ?? null, 120),
                    'created_by' => $actor->id,
                    'confirmed_by' => $official ? $actor->id : null,
                    'confirmed_at' => $official ? now() : null,
                ]);

                $keys[Str::lower($holderKey.'|'.$label.'|'.$brand.'|'.$model)] = $item->reference();
                $created[] = $item;

                $this->movements->record($actor, $item, $status === InventoryStatus::ToConfirm ? InventoryMovementType::Noted : InventoryMovementType::Create, [
                    'quantity' => $item->quantity, 'holder' => $holder, 'condition' => $condition->value,
                ]);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private function text(mixed $value, int $max): ?string
    {
        $text = mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $max);

        return $text === '' ? null : $text;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value ? $value : null;
    }
}
