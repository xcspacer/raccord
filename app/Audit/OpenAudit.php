<?php

namespace App\Audit;

use App\Models\Audit;
use App\Models\AuditControlPoint;
use App\Models\User;
use App\Models\WorkSite;
use Illuminate\Support\Facades\DB;

/**
 * Creates the audit of a cabinet with its default control points, and links the audit work sites planned for it.
 */
class OpenAudit
{
    public function handle(User $actor, string $reference): Audit
    {
        $key = BoxName::cabinetKey($reference);

        if ($key === '') {
            throw new AuditOperationException('invalid_cabinet');
        }

        return DB::transaction(function () use ($key): Audit {
            $audit = Audit::query()->firstOrCreate(['cabinet_key' => $key], ['folder' => $key, 'revision' => 1]);

            if ($audit->wasRecentlyCreated) {
                foreach ((array) config('audit.control_points') as $point) {
                    AuditControlPoint::query()->create([
                        'audit_id' => $audit->id, 'name' => mb_substr(__('audit.control_points.'.$point['key'], ['cabinet' => $key], (string) config('app.locale')), 0, 70),
                        'kind' => $point['kind'] ?? null, 'subfolders' => $point['subfolders'] ?? [], 'is_custom' => false,
                    ]);
                }
            }

            WorkSite::query()->whereNull('audit_id')->where('project_type', 'audit')->whereNotNull('cabinet_ref')->get(['id', 'cabinet_ref'])
                ->filter(fn (WorkSite $site) => BoxName::cabinetKey((string) $site->cabinet_ref) === $key)
                ->each(fn (WorkSite $site) => WorkSite::query()->whereKey($site->id)->update(['audit_id' => $audit->id]));

            return $audit;
        });
    }
}
