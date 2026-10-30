<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditClientClaimKind;
use App\Enums\AuditClientClaimState;
use App\Enums\AuditConformity;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Models\Audit;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditClientClaim;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditReserve;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a client raises on its portal about a design office audit, and how the office answers.
 *
 * A reserve may be disputed twice at most: the design office decides the first dispute, a supervisor the second
 * (the claim is « escalated »). An accepted dispute ends the disputes of that reserve. On a closed audit the client may
 * declare a new occurrence on a box; once the design office confirms it, it becomes an occurrence of the audit to
 * repair (source « client »), without reopening the audit, and its repair leads to a new dossier version.
 */
class ClientClaims
{
    public const int MAX_DISPUTES = 2;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @throws WorkflowException
     */
    public function dispute(ClientAccount $account, AuditReserve $reserve, string $body): AuditClientClaim
    {
        $reserve->loadMissing('box.audit');
        $this->guard->ensureDesignOffice($reserve->box->audit);

        return DB::transaction(function () use ($account, $reserve, $body): AuditClientClaim {
            AuditReserve::query()->whereKey($reserve->id)->lockForUpdate()->first();
            $previous = AuditClientClaim::query()->where('audit_reserve_id', $reserve->id)->where('kind', AuditClientClaimKind::ReserveDispute)->orderBy('round')->get();

            if ($previous->contains(fn (AuditClientClaim $claim) => $claim->state->isOpen())) {
                throw new WorkflowException('claim_pending', 409);
            }

            if ($previous->contains('state', AuditClientClaimState::Accepted)) {
                throw new WorkflowException('dispute_accepted', 409);
            }

            if ($previous->count() >= self::MAX_DISPUTES) {
                throw new WorkflowException('dispute_limit', 409);
            }

            $round = $previous->count() + 1;
            $claim = AuditClientClaim::query()->create([
                'audit_id' => $reserve->box->audit_id,
                'audit_box_id' => $reserve->audit_box_id,
                'audit_reserve_id' => $reserve->id,
                'audit_occurrence_id' => $reserve->audit_occurrence_id,
                'client_account_id' => $account->id,
                'kind' => AuditClientClaimKind::ReserveDispute,
                'body' => trim($body),
                'round' => $round,
                'state' => $round > 1 ? AuditClientClaimState::Escalated : AuditClientClaimState::Open,
            ]);

            $this->journal->record('client_reserve_disputed', $claim, null, ['client_account_id' => $account->id, 'reserve_id' => $reserve->id, 'box' => $reserve->box->name, 'round' => $round]);

            return $claim;
        });
    }

    /**
     * @throws WorkflowException
     */
    public function declareOccurrence(ClientAccount $account, AuditBox $box, string $body): AuditClientClaim
    {
        $box->loadMissing('audit');
        $this->guard->ensureDesignOffice($box->audit);
        $this->guard->ensureBoxInService($box);

        if (! $box->audit->isClosed()) {
            throw new WorkflowException('audit_not_closed', 409);
        }

        return DB::transaction(function () use ($account, $box, $body): AuditClientClaim {
            $claim = AuditClientClaim::query()->create([
                'audit_id' => $box->audit_id,
                'audit_box_id' => $box->id,
                'client_account_id' => $account->id,
                'kind' => AuditClientClaimKind::NewOccurrence,
                'body' => trim($body),
                'round' => 1,
                'state' => AuditClientClaimState::Open,
            ]);

            $this->journal->record('client_occurrence_declared', $claim, null, ['client_account_id' => $account->id, 'box' => $box->name]);

            return $claim;
        });
    }

    /**
     * Accepts or refuses a claim, once. Refusing needs a reason; confirming a new occurrence needs its anomaly type.
     *
     * @throws WorkflowException
     */
    public function decide(User $actor, AuditClientClaim $claim, bool $accept, ?string $reason, ?int $anomalyTypeId = null): AuditClientClaim
    {
        $claim->loadMissing('audit');
        $this->guard->ensureDesignOffice($claim->audit);
        $reason = trim((string) $reason);

        if (! $accept && mb_strlen($reason) < 3) {
            throw new WorkflowException('reason_required', 422);
        }

        $type = null;

        if ($accept && $claim->kind === AuditClientClaimKind::NewOccurrence) {
            $type = $anomalyTypeId === null ? null : AuditAnomalyType::query()->where('is_active', true)->find($anomalyTypeId);

            if ($type === null) {
                throw new WorkflowException('anomaly_type_required', 422);
            }
        }

        return DB::transaction(function () use ($actor, $claim, $accept, $reason, $type): AuditClientClaim {
            $locked = AuditClientClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! $locked->state->isOpen()) {
                throw new WorkflowException('claim_decided', 409);
            }

            $occurrence = $type === null ? null : $this->createOccurrence($actor, $locked, $type);

            $locked->forceFill([
                'state' => $accept ? AuditClientClaimState::Accepted : AuditClientClaimState::Refused,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason === '' ? null : $reason,
                'audit_occurrence_id' => $occurrence === null ? $locked->audit_occurrence_id : $occurrence->id,
            ])->save();

            $this->journal->record('client_claim_decided', $locked, $actor, array_filter([
                'kind' => $locked->kind->value, 'round' => $locked->round, 'accepted' => $accept, 'reason' => $reason === '' ? null : $reason, 'occurrence' => $occurrence?->reference,
            ], fn (mixed $value) => $value !== null));

            return $locked;
        });
    }

    /**
     * The occurrence a confirmed client claim becomes: to repair on its box, which no longer conforms.
     */
    private function createOccurrence(User $actor, AuditClientClaim $claim, AuditAnomalyType $type): AuditOccurrence
    {
        $audit = Audit::query()->lockForUpdate()->findOrFail($claim->audit_id);
        $box = AuditBox::query()->lockForUpdate()->findOrFail($claim->audit_box_id);
        $sequence = (int) AuditOccurrence::query()->where('audit_id', $audit->id)->max('sequence') + 1;

        $occurrence = AuditOccurrence::query()->create([
            'audit_id' => $audit->id,
            'audit_box_id' => $box->id,
            'sequence' => $sequence,
            'reference' => sprintf('OC-%s-%04d', $audit->cabinet_key, $sequence),
            'audit_anomaly_type_id' => $type->id,
            'nature' => $type->nature,
            'state' => AuditOccurrenceState::ToRepair,
            'source' => AuditOccurrenceSource::Client,
            'description' => $claim->body,
            'created_by' => $actor->id,
        ]);

        AuditOccurrenceBox::query()->create([
            'audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_by' => $actor->id, 'added_at' => now(),
            'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'conformity' => AuditConformity::NonConforming,
        ]);

        $this->journal->record('occurrence_created', $occurrence, $actor, ['reference' => $occurrence->reference, 'box' => $box->name, 'source' => AuditOccurrenceSource::Client->value, 'state' => AuditOccurrenceState::ToRepair->value]);

        if ($box->conformity !== AuditConformity::NonConforming) {
            $before = $box->conformity;
            $box->forceFill(['conformity' => AuditConformity::NonConforming])->save();
            $this->journal->record('box_conformity_changed', $box, $actor, ['box' => $box->name, 'old' => $before->value, 'new' => AuditConformity::NonConforming->value]);
        }

        return $occurrence;
    }
}
