<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditConformity;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditInspectionState;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRequestState;
use App\Enums\AuditReserveKind;
use App\Enums\AuditWorkflowPhase;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditDocumentVersion;
use App\Models\AuditIntervention;
use App\Models\AuditMissingProof;
use App\Models\AuditOccurrence;
use App\Models\AuditReserve;
use App\Models\AuditValidationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What must be true before a supervisor closes an audit of the design office workflow (a finished audit is not yet a
 * PM ready to deliver). Blocking: repairs phase not opened, requests or repair visits still open, boxes whose
 * inspection is not validated or that do not conform, anomalies neither validated nor covered by an accepted reserve,
 * justifications of missing photos not decided, additional controls not resolved. Warnings: refused justifications
 * (they show in the final report), follow-up reserves still open (followed after the closure) and no synoptic.
 * Removed boxes and boxes declared « not installed » are left out.
 *
 * @phpstan-type Check array{code: string, items: list<string>}
 * @phpstan-type Report array{ok: bool, blockers: list<Check>, warnings: list<Check>, summary: array{boxes: int, conforming: int, with_reserve: int, occurrences: int, reserves: int}}
 */
class ClosingChecks
{
    /** Occurrence states that keep the audit from being delivered. */
    private const array OPEN_OCCURRENCES = [AuditOccurrenceState::Detected, AuditOccurrenceState::ToRepair, AuditOccurrenceState::Corrected, AuditOccurrenceState::Refused];

    /**
     * @return Report
     */
    public function report(Audit $audit): array
    {
        $boxes = $this->boxes($audit);
        $blockers = array_values(array_filter([
            $this->check('phase', $audit->phase->value < AuditWorkflowPhase::Repairs->value ? [''] : []),
            $this->check('requests_open', $this->boxNames(AuditValidationRequest::query()->whereIn('audit_box_id', $this->allBoxIds($audit))->whereIn('state', AuditRequestState::open()))),
            $this->check('interventions_open', $this->boxNames(AuditIntervention::query()->where('audit_id', $audit->id)->whereIn('status', [AuditInterventionStatus::Planned, AuditInterventionStatus::Submitted]))),
            $this->check('inspection_not_validated', $boxes->reject(fn (AuditBox $box) => $box->inspection_state === AuditInspectionState::Validated)->pluck('name')->all()),
            $this->check('boxes_not_conforming', $boxes->filter(fn (AuditBox $box) => $box->inspection_state === AuditInspectionState::Validated && ! in_array($box->conformity, [AuditConformity::Conforming, AuditConformity::ConformingWithReserve], true))->pluck('name')->all()),
            $this->check('occurrences_open', AuditOccurrence::query()->where('audit_id', $audit->id)->whereIn('state', self::OPEN_OCCURRENCES)->with('box:id,name')->orderBy('sequence')->get()
                ->map(fn (AuditOccurrence $occurrence) => $occurrence->reference.' ('.$occurrence->box->name.')')->all()),
            $this->check('proofs_undecided', $this->proofs($audit, fn (Builder $proofs) => $proofs->whereNull('is_accepted'))),
            $this->check('controls_open', $this->boxNames(AuditBoxMessage::query()->whereIn('audit_box_id', $this->allBoxIds($audit))->where('is_blocking', true)->whereNull('resolved_at'))),
        ]));
        $warnings = array_values(array_filter([
            $this->check('proofs_refused', $this->proofs($audit, fn (Builder $proofs) => $proofs->where('is_accepted', false))),
            $this->check('follow_up_reserves_open', AuditReserve::query()->whereIn('audit_box_id', $this->allBoxIds($audit))->where('kind', AuditReserveKind::FollowUp)->whereNull('closed_at')
                ->with(['box:id,name', 'occurrence:id,reference'])->get()->sortBy(fn (AuditReserve $reserve) => $reserve->box->name, SORT_NATURAL)
                ->map(fn (AuditReserve $reserve) => $reserve->box->name.($reserve->occurrence === null ? '' : ' — '.$reserve->occurrence->reference))->values()->all()),
            $this->check('synoptic_missing', $this->hasSynoptic($audit) ? [] : ['']),
        ]));

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => [
                'boxes' => $boxes->count(),
                'conforming' => $boxes->where('conformity', AuditConformity::Conforming)->count(),
                'with_reserve' => $boxes->where('conformity', AuditConformity::ConformingWithReserve)->count(),
                'occurrences' => AuditOccurrence::query()->where('audit_id', $audit->id)->count(),
                'reserves' => AuditReserve::query()->whereIn('audit_box_id', $this->allBoxIds($audit))->count(),
            ],
        ];
    }

    /**
     * Boxes that count for the delivery: in service, and not declared « not installed ».
     *
     * @return Collection<int, AuditBox>
     */
    public function boxes(Audit $audit): Collection
    {
        return LegacyBoxStatus::counted(AuditBox::query()->where('audit_id', $audit->id))->get()->sortBy('name', SORT_NATURAL)->values();
    }

    /**
     * @param  list<string>  $items
     * @return Check|null
     */
    private function check(string $code, array $items): ?array
    {
        return $items === [] ? null : ['code' => $code, 'items' => array_values(array_filter($items, fn (string $item) => $item !== ''))];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  Rows carrying an audit_box_id.
     * @return list<string>
     */
    private function boxNames(Builder $query): array
    {
        $ids = $query->distinct()->pluck('audit_box_id')->all();

        return AuditBox::query()->whereKey($ids)->pluck('name')->sort(SORT_NATURAL)->values()->all();
    }

    /**
     * @param  callable(Builder<AuditMissingProof>): mixed  $filter
     * @return list<string>
     */
    private function proofs(Audit $audit, callable $filter): array
    {
        $query = AuditMissingProof::query()->whereIn('audit_box_id', $this->allBoxIds($audit))->with('box:id,name');
        $filter($query);

        return $query->get()->sortBy(fn (AuditMissingProof $proof) => $proof->box->name, SORT_NATURAL)
            ->map(fn (AuditMissingProof $proof) => $proof->box->name.' / '.$proof->folder->label())->values()->all();
    }

    /**
     * @return Builder<AuditBox>
     */
    private function allBoxIds(Audit $audit): Builder
    {
        return AuditBox::query()->select('id')->where('audit_id', $audit->id);
    }

    /**
     * The current synoptic of the workflow documents, or the one given when the audit was configured.
     */
    private function hasSynoptic(Audit $audit): bool
    {
        return $audit->synoptic_file_id !== null
            || AuditDocumentVersion::query()->where('audit_id', $audit->id)->where('kind', AuditDocumentKind::Synoptic)->where('is_current', true)->exists();
    }
}
