<?php

namespace App\Inventory;

use App\Enums\InventoryStatus;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports of the inventory of the company or of one person, as Excel (inventory and history sheets) and PDF.
 * Texts follow the language of the person exporting.
 */
class InventoryExport
{
    public const int HISTORY_LIMIT = 600;

    public function __construct(private CompanySettings $company) {}

    public function excel(?User $holder): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('inventory.export.sheet_inventory'));

        $this->row($sheet, 1, array_map(fn (string $key) => __('inventory.export.columns.'.$key), ['id', 'label', 'category', 'brand', 'model', 'serial', 'quantity', 'condition', 'status', 'holder', 'vehicle', 'assigned_on', 'to_check', 'uncertainty', 'notes', 'site', 'source']));
        $row = 2;

        foreach ($this->items($holder, true) as $item) {
            $this->row($sheet, $row++, [
                $item->reference(), $item->label, $item->category === null ? '' : __('enums.inventory_categories.'.$item->category), $item->brand, $item->model, $item->serial, $item->quantity,
                $item->condition->label(), $item->status->label(), $item->holder?->name, $item->vehicle ?: collect([$item->holder?->vehicle?->model, $item->holder?->vehicle?->plate])->filter()->implode(' - '),
                $item->assigned_on?->format('d/m/Y'), $item->needs_check ? __('inventory.export.yes') : '', $item->uncertainty, $item->notes, $item->site, $item->source,
            ]);
        }

        $this->style($sheet, 17);

        $history = $spreadsheet->createSheet();
        $history->setTitle(__('inventory.export.sheet_history'));
        $this->row($history, 1, array_map(fn (string $key) => __('inventory.export.history.'.$key), ['date', 'action', 'equipment', 'quantity', 'from', 'to', 'by', 'comment']));
        $row = 2;

        foreach ($this->movements($holder) as $movement) {
            $this->row($history, $row++, [
                $movement->created_at->setTimezone(config('platform.timezone'))->format('d/m/Y H:i'), $movement->type->label().($movement->kind ? ' ('.$movement->kind.')' : ''),
                $movement->label, $movement->quantity, $movement->from_name ?? $movement->holder_name, $movement->to_name, $movement->user_name, $movement->comment,
            ]);
        }

        $this->style($history, 8);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function pdf(?User $holder): string
    {
        $items = $this->items($holder, false);
        $now = CarbonImmutable::now(config('platform.timezone'));

        return Pdf::loadView('pdf.inventory-list', [
            'company' => $this->company->displayName(),
            'logo' => PdfImage::logoDataUri($this->company->logo_path),
            'footer' => $this->company->document_footer,
            'holder' => $holder,
            'items' => $items,
            'date' => $now,
        ])->setPaper('a4')->output();
    }

    public function fileName(string $extension, ?User $holder): string
    {
        $base = __('inventory.export.file', ['company' => $this->company->displayName()]).($holder === null ? '' : ' - '.$holder->name);

        return (string) str($base)->ascii()->replaceMatches('/[^A-Za-z0-9 _.\-]/', '')->squish().'.'.$extension;
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    private function items(?User $holder, bool $withRetired): Collection
    {
        return InventoryItem::query()->with('holder.vehicle')
            ->when($holder !== null, fn ($query) => $query->where('holder_id', $holder?->id))
            ->when(! $withRetired, fn ($query) => $query->where('status', '!=', InventoryStatus::Retired->value))
            ->orderBy('id')->get();
    }

    /**
     * @return Collection<int, InventoryMovement>
     */
    private function movements(?User $holder): Collection
    {
        return InventoryMovement::query()->latest('id')->limit(self::HISTORY_LIMIT)
            ->when($holder !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('from_user_id', $holder?->id)->orWhere('to_user_id', $holder?->id)->orWhere('holder_id', $holder?->id)))
            ->get();
    }

    /**
     * @param  list<mixed>  $values
     */
    private function row(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            $sheet->setCellValueExplicit([$index + 1, $row], (string) ($value ?? ''), DataType::TYPE_STRING);
        }
    }

    private function style(Worksheet $sheet, int $columns): void
    {
        $sheet->getStyle([1, 1, $columns, 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        for ($column = 1; $column <= $columns; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }
}
