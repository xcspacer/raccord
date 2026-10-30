<?php

namespace App\Http\Controllers;

use App\Audit\BoxName;
use App\Models\Audit;
use App\Models\AuditDrawerRecheck;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\FileResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files attached to an audit (the synoptic, the map file, the plans, the drawer plan after works), for the people
 * working on it.
 */
class AuditFileController extends Controller
{
    public function __invoke(Request $request, string $cabinet, string $kind, ?int $plan = null): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        Gate::forUser($user)->authorize('work', $audit);

        [$file, $name] = match ($kind) {
            'synoptic' => [$audit->synoptic_file_id, $audit->synoptic_name],
            'map' => [$audit->kmz_file_id, $audit->kmz_name],
            'drawer' => $this->drawerAfterWorks($audit),
            default => $this->plan($audit, (int) $plan),
        };

        $stored = $file === null ? null : StoredFile::query()->find($file);
        abort_if($stored === null, 404);

        return FileResponse::make($stored->disk, $stored->path, $name ?: $audit->cabinet_key.'.'.$stored->extension, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * The drawer plan as found on site (« Tiroir après travaux »), once the drawer recheck has been saved.
     *
     * @return array{int|null, string|null}
     */
    private function drawerAfterWorks(Audit $audit): array
    {
        $recheck = AuditDrawerRecheck::query()->where('audit_id', $audit->id)->whereNotNull('recnok_file_id')->first();

        return [$recheck?->recnok_file_id, $recheck?->recnok_name];
    }

    /**
     * @return array{int|null, string|null}
     */
    private function plan(Audit $audit, int $id): array
    {
        $plan = AuditPlan::query()->where('audit_id', $audit->id)->whereKey($id)->firstOrFail();

        return [$plan->stored_file_id, $plan->name];
    }
}
