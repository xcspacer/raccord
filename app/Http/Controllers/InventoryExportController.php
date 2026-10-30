<?php

namespace App\Http\Controllers;

use App\Inventory\InventoryExport;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel and PDF exports of the inventory (all of it, or one person's equipment), for managers only.
 */
class InventoryExportController extends Controller
{
    public function __invoke(Request $request, string $format, InventoryExport $export): Response
    {
        abort_unless($request->user()?->can('manage', InventoryItem::class) === true, 403);

        $holder = $request->query('user') === null || $request->query('user') === '' ? null : User::query()->findOrFail((int) $request->query('user'));

        if ($format === 'pdf') {
            return response($export->pdf($holder), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$export->fileName('pdf', $holder).'"', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $spreadsheet = $export->excel($holder);

        return response()->streamDownload(function () use ($spreadsheet): void {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $export->fileName('xlsx', $holder), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }
}
