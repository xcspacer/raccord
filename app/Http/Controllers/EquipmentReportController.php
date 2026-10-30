<?php

namespace App\Http\Controllers;

use App\Enums\InventoryCategory;
use App\Enums\InventoryStatus;
use App\Models\InventoryItem;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Support\DocumentBrand;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Fiche matériel » PDF of the signed-in technician: assigned tools and the equipment with its label photo.
 */
class EquipmentReportController extends Controller
{
    public function __invoke(Request $request, CompanySettings $company, DocumentBrand $brand): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $today = CarbonImmutable::now(config('platform.timezone'));
        $items = InventoryItem::query()->with('photos.file')->where('holder_id', $user->id)
            ->whereIn('status', [InventoryStatus::Assigned->value, InventoryStatus::ToConfirm->value])->orderBy('label')->get();
        $names = fn (Collection $lines): string => $lines->map(fn (InventoryItem $item) => trim($item->label.($item->quantity > 1 ? ' ×'.$item->quantity : '')))->implode(', ');

        $pdf = Pdf::loadView('pdf.equipment-report', [
            ...$brand->for($user),
            'footer' => $company->document_footer,
            'technician' => $user->name,
            'date' => $today,
            'assigned' => [
                ['label' => __('pdf.equipment_report.assigned.welder'), 'value' => $names($items->where('category', InventoryCategory::Welder->value))],
                ['label' => __('pdf.equipment_report.assigned.drill'), 'value' => $names($items->where('category', InventoryCategory::PowerTool->value))],
                ['label' => __('pdf.equipment_report.assigned.tools'), 'value' => $names($items->whereNotIn('category', [InventoryCategory::Welder->value, InventoryCategory::PowerTool->value]))],
            ],
            'items' => $items->map(fn (InventoryItem $item) => [
                'type' => $item->label,
                'brand' => $item->brand,
                'model' => $item->model,
                'serial_number' => $item->serial,
                'image' => PdfImage::dataUri($item->photos->first()?->file),
            ]),
        ])->setPaper('a4');

        $name = Str::of(__('pdf.equipment_report.file', ['company' => $company->displayName(), 'name' => $user->name, 'date' => $today->toDateString()]))
            ->ascii()->replaceMatches('/[^A-Za-z0-9 _.\-]/', '')->squish();

        return $pdf->download($name.'.pdf');
    }
}
