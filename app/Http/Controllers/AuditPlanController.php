<?php

namespace App\Http\Controllers;

use App\Audit\BoxName;
use App\Audit\Spreadsheet\SheetReader;
use App\Models\Audit;
use App\Models\AuditPlan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * The plan viewer: a shell page and the plan read by the server as sheets, for the people working on the audit.
 */
class AuditPlanController extends Controller
{
    public function show(Request $request, string $cabinet, int $plan): View
    {
        [$audit, $model] = $this->find($request, $cabinet, $plan);

        return view('audit.plan-viewer', [
            'title' => $model->name,
            'config' => [
                'data' => route('audits.plans.data', ['cabinet' => $audit->cabinet_key, 'plan' => $model->id], false),
                'download' => route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'plan', 'plan' => $model->id], false),
                'back' => route('audits.show', ['cabinet' => $audit->cabinet_key], false),
                'texts' => __('audit.plan'),
            ],
        ]);
    }

    public function data(Request $request, string $cabinet, int $plan, SheetReader $reader): JsonResponse
    {
        [, $model] = $this->find($request, $cabinet, $plan);
        $file = $model->file;
        abort_unless(in_array($file->extension, ['xlsx', 'xls'], true), 404);

        try {
            $book = $reader->read(Storage::disk($file->disk)->path($file->path));
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => 'unreadable'], 422);
        }

        return response()->json(['ok' => true] + $book)->header('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{Audit, AuditPlan}
     */
    private function find(Request $request, string $cabinet, int $plan): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        Gate::forUser($user)->authorize('work', $audit);

        return [$audit, AuditPlan::query()->with('file')->where('audit_id', $audit->id)->whereKey($plan)->firstOrFail()];
    }
}
