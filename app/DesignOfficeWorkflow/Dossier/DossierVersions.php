<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowGuard;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRequestState;
use App\Enums\AuditWorkflow;
use App\Models\Audit;
use App\Models\AuditDecision;
use App\Models\AuditDossierVersion;
use App\Models\AuditOccurrence;
use App\Models\AuditValidationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The versions of the official dossier of a closed audit. Closing the audit authorizes the first version; a correction
 * validated by the design office after a published version (the audit closed again, nothing left open) makes the next
 * one automatically; a failed version is relaunched by a supervisor. One version is in preparation at a time and
 * versions only count up. Requesting a version never generates it: the scheduler does (`design-office:generate-dossiers`).
 */
class DossierVersions
{
    /** A generation still « generating » after this long was interrupted (the process died). */
    public const int STALE_MINUTES = 180;

    /** Outcomes of a decision that validate a correction. */
    private const array VALIDATING = [AuditDecisionOutcome::Validate, AuditDecisionOutcome::ValidateWithReserves, AuditDecisionOutcome::RepairsValidated, AuditDecisionOutcome::RepairsPartial];

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal, private DossierLanguage $language) {}

    /**
     * The first version, authorized by the closure of the audit (the person who closed it, or nobody when the scheduler
     * finds a closed audit without dossier).
     *
     * @throws WorkflowException
     */
    public function authorizeInitial(Audit $audit, ?User $by): AuditDossierVersion
    {
        $this->guard->ensureDesignOffice($audit);

        if (! $audit->isClosed()) {
            throw new WorkflowException('audit_not_closed', 409);
        }

        return $this->create($audit, AuditDossierKind::Initial, null, $by, $by->id ?? $audit->closed_by);
    }

    /**
     * A later version, after a correction validated once a version was published (no reason: the automatic one).
     *
     * @throws WorkflowException
     */
    public function requestUpdate(Audit $audit, ?string $reason, ?User $by): AuditDossierVersion
    {
        $this->guard->ensureDesignOffice($audit);

        if (! $audit->isClosed()) {
            throw new WorkflowException('audit_not_closed', 409);
        }

        return $this->create($audit, AuditDossierKind::Update, $reason, $by, $by?->id);
    }

    /**
     * Puts a failed version back in the queue of the scheduler (only the last version: a later one replaced it).
     *
     * @throws WorkflowException
     */
    public function relaunch(User $supervisor, AuditDossierVersion $version): void
    {
        DB::transaction(function () use ($supervisor, $version): void {
            Audit::query()->whereKey($version->audit_id)->lockForUpdate()->first();
            $locked = AuditDossierVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ($locked->status !== AuditDossierStatus::Failed) {
                throw new WorkflowException('dossier_not_failed', 409);
            }

            if (AuditDossierVersion::query()->where('audit_id', $locked->audit_id)->where('version_number', '>', $locked->version_number)->exists()) {
                throw new WorkflowException('dossier_superseded', 409);
            }

            if (AuditDossierVersion::query()->where('audit_id', $locked->audit_id)->where('id', '!=', $locked->id)->whereIn('status', [AuditDossierStatus::Pending, AuditDossierStatus::Generating])->exists()) {
                throw new WorkflowException('dossier_in_progress', 409);
            }

            $locked->forceFill(['status' => AuditDossierStatus::Pending, 'error' => null])->save();
            $this->journal->record('dossier_relaunched', $locked, $supervisor, ['version' => $locked->version_number, 'attempts' => $locked->attempts]);
        });
    }

    /**
     * What the scheduler does before generating: interrupted generations are marked failed, closed audits without
     * dossier get their first version and audits with a correction validated after their last published version get
     * the next one. Running it twice changes nothing.
     *
     * @return array{stale: int, initial: int, updates: int}
     */
    public function sweep(): array
    {
        $stale = 0;

        foreach (AuditDossierVersion::query()->where('status', AuditDossierStatus::Generating)->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))->pluck('id') as $id) {
            $stale += $this->markInterrupted((int) $id) ? 1 : 0;
        }

        $closed = Audit::query()->where('workflow', AuditWorkflow::DesignOffice)->whereNotNull('closed_at');
        $initial = 0;

        foreach ((clone $closed)->whereDoesntHave('dossierVersions')->get() as $audit) {
            $initial += $this->attempt(fn () => $this->authorizeInitial($audit, null)) ? 1 : 0;
        }

        $updates = 0;

        foreach ($closed->whereHas('dossierVersions', fn ($versions) => $versions->where('status', AuditDossierStatus::Published))->get() as $audit) {
            if ($this->needsUpdate($audit)) {
                $updates += $this->attempt(fn () => $this->requestUpdate($audit, null, null)) ? 1 : 0;
            }
        }

        return ['stale' => $stale, 'initial' => $initial, 'updates' => $updates];
    }

    /**
     * The last version is published, the audit was closed again or a correction was validated after it was read,
     * and nothing is left open.
     */
    private function needsUpdate(Audit $audit): bool
    {
        $last = AuditDossierVersion::query()->where('audit_id', $audit->id)->orderByDesc('version_number')->first();

        if ($last === null || $last->status !== AuditDossierStatus::Published) {
            return false;
        }

        $since = $last->started_at ?? $last->created_at;
        $changed = $audit->closed_at?->greaterThan($since)
            || AuditDecision::query()->where('decided_at', '>', $since)
                ->whereHas('type', fn ($types) => $types->whereIn('outcome', self::VALIDATING))
                ->whereHas('request.box', fn ($boxes) => $boxes->where('audit_id', $audit->id))->exists();

        return $changed
            && ! AuditValidationRequest::query()->whereIn('state', AuditRequestState::open())->whereHas('box', fn ($boxes) => $boxes->where('audit_id', $audit->id))->exists()
            && ! AuditOccurrence::query()->where('audit_id', $audit->id)->whereNotIn('state', [AuditOccurrenceState::Validated, AuditOccurrenceState::ReserveAccepted])->exists();
    }

    /**
     * @throws WorkflowException
     */
    private function create(Audit $audit, AuditDossierKind $kind, ?string $reason, ?User $by, ?int $authorizedBy): AuditDossierVersion
    {
        return DB::transaction(function () use ($audit, $kind, $reason, $by, $authorizedBy): AuditDossierVersion {
            Audit::query()->whereKey($audit->id)->lockForUpdate()->first();
            $versions = AuditDossierVersion::query()->where('audit_id', $audit->id)->get(['id', 'version_number', 'status']);

            if ($kind === AuditDossierKind::Initial && $versions->isNotEmpty()) {
                throw new WorkflowException('dossier_exists', 409);
            }

            if ($kind === AuditDossierKind::Update && ! $versions->contains('status', AuditDossierStatus::Published)) {
                throw new WorkflowException('dossier_no_initial', 409);
            }

            if ($versions->contains(fn (AuditDossierVersion $version) => $version->status->isInProgress())) {
                throw new WorkflowException('dossier_in_progress', 409);
            }

            $version = AuditDossierVersion::query()->create([
                'audit_id' => $audit->id,
                'version_number' => ((int) $versions->max('version_number')) + 1,
                'kind' => $kind,
                'status' => AuditDossierStatus::Pending,
                'reason' => $reason,
                'locale' => $this->language->for($audit),
                'requested_by' => $by?->id,
                'authorized_by' => $authorizedBy,
            ]);

            $this->journal->record('dossier_requested', $version, $by, ['cabinet' => $audit->cabinet_key, 'version' => $version->version_number, 'kind' => $kind->value, 'origin' => $by === null ? 'scheduler' : 'user']);

            return $version;
        });
    }

    private function markInterrupted(int $versionId): bool
    {
        return DB::transaction(function () use ($versionId): bool {
            $version = AuditDossierVersion::query()->lockForUpdate()->find($versionId);

            if ($version === null || $version->status !== AuditDossierStatus::Generating) {
                return false;
            }

            $version->forceFill(['status' => AuditDossierStatus::Failed, 'error' => 'interrupted'])->save();
            $this->journal->record('dossier_failed', $version, null, ['version' => $version->version_number, 'error' => 'interrupted', 'origin' => 'scheduler']);

            return true;
        });
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): bool
    {
        try {
            $action();
        } catch (WorkflowException) {
            return false;
        }

        return true;
    }
}
