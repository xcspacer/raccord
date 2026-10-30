<?php

namespace App\Http\Controllers;

use App\Audit\BoxName;
use App\Audit\Spreadsheet\AuditSynthesis;
use App\Enums\AuditMode;
use App\Models\Audit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The synthesis of a cabinet (optical mode) as an Excel file, for the managers.
 */
class AuditSynthesisController extends Controller
{
    public function __invoke(Request $request, string $cabinet, AuditSynthesis $synthesis): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        Gate::forUser($user)->authorize('work', $audit);
        Gate::forUser($user)->authorize('manage', $audit);
        abort_unless($audit->effectiveMode() === AuditMode::Optical, 404);

        $book = $synthesis->build($audit, $user);

        return response()->streamDownload(function () use ($book): void {
            IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, __('audit.synthesis.file', ['cabinet' => $audit->cabinet_key]).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }
}
