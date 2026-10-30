<?php

namespace App\DesignOfficeWorkflow;

use App\Models\AuditBox;
use App\Models\AuditPause;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pauses a technician declares on the box they work on: one open pause per person at a time, ended by the same person.
 * Declared pauses are left out of the production time of the box. Times always come from the server.
 */
class Pauses
{
    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @throws WorkflowException
     */
    public function start(User $technician, AuditBox $box, ?string $reason = null): AuditPause
    {
        $box->loadMissing('audit');
        $this->guard->ensureOpenOrClientRepair($box->audit, $box);
        $this->guard->ensureBoxInService($box);

        if (! $box->hasTeamMember($technician)) {
            throw new WorkflowException('not_team_member', 403);
        }

        return DB::transaction(function () use ($technician, $box, $reason): AuditPause {
            User::query()->whereKey($technician->id)->lockForUpdate()->first();

            if (AuditPause::query()->where('user_id', $technician->id)->whereNull('ended_at')->exists()) {
                throw new WorkflowException('pause_open', 409);
            }

            $reason = trim((string) $reason);
            $pause = AuditPause::query()->create(['user_id' => $technician->id, 'audit_box_id' => $box->id, 'started_at' => now(), 'reason' => $reason === '' ? null : mb_substr($reason, 0, 500)]);
            $this->journal->record('pause_started', $pause, $technician, ['box' => $box->name]);

            return $pause;
        });
    }

    /**
     * @throws WorkflowException
     */
    public function end(User $technician, AuditBox $box): AuditPause
    {
        return DB::transaction(function () use ($technician, $box): AuditPause {
            $pause = AuditPause::query()->where('user_id', $technician->id)->where('audit_box_id', $box->id)->whereNull('ended_at')->lockForUpdate()->first();

            if ($pause === null) {
                throw new WorkflowException('no_open_pause', 409);
            }

            $pause->forceFill(['ended_at' => now()])->save();
            $this->journal->record('pause_ended', $pause, $technician, ['box' => $box->name, 'seconds' => (int) $pause->started_at->diffInSeconds($pause->ended_at)]);

            return $pause;
        });
    }

    public function openPauseOf(User $technician): ?AuditPause
    {
        return AuditPause::query()->where('user_id', $technician->id)->whereNull('ended_at')->first();
    }
}
