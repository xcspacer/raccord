<?php

namespace App\Audit;

use App\DesignOfficeWorkflow\FieldDataGuard;
use App\Models\Audit;
use App\Models\User;
use App\Models\WorkSite;
use Illuminate\Support\Facades\DB;

/**
 * « Deletes » the audit of a cabinet: it is archived, nothing is destroyed. A closed audit of the design office
 * workflow is kept as delivered (only a formal reopening changes it). The audit is soft deleted with who and when
 * (its files stay where they are, they are never moved), its reference is freed so the cabinet can be audited again,
 * and the audit work sites of the cabinet leave the planning with their tasks (they can be restored from the logs).
 */
class ArchiveAudit
{
    /**
     * @return int Number of work sites removed from the planning.
     *
     * @throws AuditOperationException
     */
    public function handle(User $actor, Audit $audit): int
    {
        app(FieldDataGuard::class)->ensureNotFrozen($audit);

        return DB::transaction(function () use ($actor, $audit): int {
            $key = $audit->cabinet_key;
            $removed = $this->removePlanning($key, $audit->id);

            $audit->forceFill([
                'original_cabinet_key' => $key, 'cabinet_key' => $key.'~'.now()->format('Ymd-His'),
                'deleted_by' => $actor->id, 'deleted_by_name' => $actor->name,
            ])->save();
            $audit->delete();

            return $removed;
        });
    }

    /**
     * A cabinet listed on the audits: its audit when it was opened, otherwise only its planned audit work sites.
     *
     * @return int Number of work sites removed from the planning.
     */
    public function cabinet(User $actor, string $key): int
    {
        $audit = Audit::query()->where('cabinet_key', $key)->first();

        return $audit !== null ? $this->handle($actor, $audit) : DB::transaction(fn (): int => $this->removePlanning($key, null));
    }

    private function removePlanning(string $key, ?int $auditId): int
    {
        $sites = WorkSite::query()->where('project_type', 'audit')
            ->where(fn ($query) => $query->when($auditId !== null, fn ($inner) => $inner->where('audit_id', $auditId))->orWhereNotNull('cabinet_ref'))->get(['id', 'audit_id', 'cabinet_ref'])
            ->filter(fn (WorkSite $site) => ($auditId !== null && $site->audit_id === $auditId) || BoxName::cabinetKey((string) $site->cabinet_ref) === $key);

        WorkSite::query()->whereKey($sites->modelKeys())->get()->each(function (WorkSite $site): void {
            $site->tasks()->delete();
            $site->delete();
        });

        return $sites->count();
    }
}
