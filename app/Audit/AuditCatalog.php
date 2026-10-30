<?php

namespace App\Audit;

use App\Enums\AuditCatalogList;
use App\Enums\AuditSupport;
use App\Models\AuditBox;
use App\Models\AuditCatalogItem;
use App\Models\AuditControlPoint;
use Illuminate\Support\Facades\DB;

/**
 * The articles that can be picked in a report. A box in a chamber uses the chamber list, a box on a pole or a
 * facade the pole list, a box without support none; only the cabinet itself uses the cabinet list. Managers edit the
 * lists as text, one article per line: « code | label ».
 */
class AuditCatalog
{
    public const int MAX_ITEMS = 200;

    /**
     * @return list<array{id: string, label: string}>
     */
    public function items(AuditCatalogList $list): array
    {
        return AuditCatalogItem::query()->where('list', $list->value)->orderBy('position')->get()->map(fn (AuditCatalogItem $item) => ['id' => $item->code, 'label' => $item->label])->all();
    }

    /**
     * @return array<string, list<array{id: string, label: string}>>
     */
    public function all(): array
    {
        $out = array_fill_keys(array_map(fn (AuditCatalogList $list) => $list->value, AuditCatalogList::cases()), []);

        foreach (AuditCatalogItem::query()->orderBy('list')->orderBy('position')->get() as $item) {
            $out[$item->list][] = ['id' => $item->code, 'label' => $item->label];
        }

        return $out;
    }

    public function listForBox(AuditBox $box): ?AuditCatalogList
    {
        return match ($box->support) {
            AuditSupport::Chamber => AuditCatalogList::Chamber,
            AuditSupport::Pole, AuditSupport::Facade => AuditCatalogList::Pole,
            null => null,
        };
    }

    /**
     * Whether a report has to be filled in for the box: its list exists and has articles.
     */
    public function boxNeedsReport(AuditBox $box): bool
    {
        $list = $this->listForBox($box);

        return $list !== null && AuditCatalogItem::query()->where('list', $list->value)->exists();
    }

    public function cabinetNeedsReport(AuditControlPoint $point): bool
    {
        return $point->kind === 'cabinet' && AuditCatalogItem::query()->where('list', AuditCatalogList::Cabinet->value)->exists();
    }

    /**
     * @return array{items: list<array{id: string, label: string}>, error: string|null, line: int|null} The articles read from the text, or what is wrong and where.
     */
    public function parse(string $text): array
    {
        $items = [];
        $seen = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $number => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$code, $label] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

            if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code) !== 1 || $label === '' || mb_strlen($label) > 160) {
                return ['items' => [], 'error' => 'invalid_line', 'line' => $number + 1];
            }

            if (isset($seen[mb_strtolower($code)])) {
                return ['items' => [], 'error' => 'duplicate_code', 'line' => $number + 1];
            }

            $seen[mb_strtolower($code)] = true;
            $items[] = ['id' => $code, 'label' => $label];
        }

        if (count($items) > self::MAX_ITEMS) {
            return ['items' => [], 'error' => 'too_many', 'line' => null];
        }

        return ['items' => $items, 'error' => null, 'line' => null];
    }

    public function toText(AuditCatalogList $list): string
    {
        return implode("\n", array_map(fn (array $item) => $item['id'].' | '.$item['label'], $this->items($list)));
    }

    /**
     * @param  list<array{id: string, label: string}>  $items
     */
    public function replace(AuditCatalogList $list, array $items): void
    {
        DB::transaction(function () use ($list, $items): void {
            AuditCatalogItem::query()->where('list', $list->value)->delete();

            foreach ($items as $position => $item) {
                AuditCatalogItem::query()->create(['list' => $list->value, 'code' => $item['id'], 'label' => $item['label'], 'position' => $position]);
            }
        });
    }
}
