<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditDecision;
use App\Models\AuditSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicators of the design office workflow over a period, from what really happened (never invented figures): for
 * each reviewer the decisions taken, the time from submission to decision and the late ones; for each technician the
 * submissions, refusals, boxes accepted at the first submission and the average production time of the boxes they
 * completed. Restricted to one person when asked (each person sees only their own figures).
 *
 * @phpstan-import-type Row from ProductionTime
 *
 * @phpstan-type ReviewerRow array{id: int, name: string, decisions: int, average: int|null, late: int, refused: int}
 * @phpstan-type TechnicianRow array{id: int, name: string, submissions: int, boxes: int, refused: int, first_time: int, completed: int, average: int|null}
 * @phpstan-type BoxRow array{box: AuditBox, time: Row}
 */
class Indicators
{
    public function __construct(private ProductionTime $production) {}

    /**
     * @return list<ReviewerRow>
     */
    public function reviewers(CarbonImmutable $from, CarbonImmutable $to, ?int $userId = null): array
    {
        $decisions = AuditDecision::query()->whereBetween('decided_at', [$from, $to])
            ->when($userId !== null, fn ($query) => $query->where('decided_by', $userId))
            ->with(['request', 'type', 'decider:id,name'])->get();

        return $decisions->groupBy('decided_by')->map(function (Collection $own): array {
            /** @var AuditDecision $first */
            $first = $own->first();
            $durations = $own->map(fn (AuditDecision $decision) => (int) $decision->request->queued_at->diffInSeconds($decision->decided_at));

            return [
                'id' => $first->decided_by,
                'name' => (string) $first->decider?->name,
                'decisions' => $own->count(),
                'average' => $durations->isEmpty() ? null : (int) round($durations->avg()),
                'late' => $own->filter(fn (AuditDecision $decision) => $decision->request->late_at !== null)->count(),
                'refused' => $own->filter(fn (AuditDecision $decision) => in_array($decision->type->outcome, [AuditDecisionOutcome::Refuse, AuditDecisionOutcome::RepairsRefused], true))->count(),
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @param  list<BoxRow>  $completed  Boxes completed in the period (see boxes()).
     * @return list<TechnicianRow>
     */
    public function technicians(CarbonImmutable $from, CarbonImmutable $to, array $completed, ?int $userId = null): array
    {
        $submissions = AuditSubmission::query()->where('kind', AuditSubmissionKind::Inspection)->whereBetween('received_at', [$from, $to])
            ->when($userId !== null, fn ($query) => $query->where('submitted_by', $userId))
            ->with(['validationRequest.decision.type', 'submitter:id,name'])->get();
        $firstSubmissionIds = AuditSubmission::query()->where('kind', AuditSubmissionKind::Inspection)->whereIn('audit_box_id', $submissions->pluck('audit_box_id')->unique())
            ->selectRaw('MIN(id) as id')->groupBy('audit_box_id')->pluck('id')->all();

        return $submissions->groupBy('submitted_by')->map(function (Collection $own, int $technicianId) use ($firstSubmissionIds, $completed): array {
            /** @var AuditSubmission $first */
            $first = $own->first();
            $outcome = fn (AuditSubmission $submission) => $submission->validationRequest?->decision?->type->outcome;
            $times = collect($completed)->filter(fn (array $row) => $row['time']['technician_id'] === $technicianId && $row['time']['effective'] !== null)->pluck('time.effective');

            return [
                'id' => $technicianId,
                'name' => (string) $first->submitter?->name,
                'submissions' => $own->count(),
                'boxes' => $own->pluck('audit_box_id')->unique()->count(),
                'refused' => $own->filter(fn (AuditSubmission $submission) => $outcome($submission) === AuditDecisionOutcome::Refuse)->count(),
                'first_time' => $own->filter(fn (AuditSubmission $submission) => in_array($submission->id, $firstSubmissionIds, true) && $outcome($submission)?->validatesInspection() === true)->count(),
                'completed' => $times->count(),
                'average' => $times->isEmpty() ? null : (int) round($times->avg()),
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * Boxes whose inspection the design office accepted in the period, with their production time.
     *
     * @return list<BoxRow>
     */
    public function boxes(CarbonImmutable $from, CarbonImmutable $to, ?User $technician = null): array
    {
        $boxIds = AuditDecision::query()->whereBetween('audit_decisions.decided_at', [$from, $to])
            ->whereHas('type', fn ($types) => $types->whereIn('outcome', [AuditDecisionOutcome::Validate, AuditDecisionOutcome::ValidateWithReserves, AuditDecisionOutcome::AuthorizeContinuation]))
            ->join('audit_validation_requests', 'audit_validation_requests.id', '=', 'audit_decisions.audit_validation_request_id')
            ->distinct()->pluck('audit_validation_requests.audit_box_id');
        $boxes = AuditBox::query()->whereIn('id', $boxIds)->with('audit:id,cabinet_key')->orderBy('id')->get();
        $times = $this->production->forBoxes($boxes);

        return $boxes
            ->map(fn (AuditBox $box) => ['box' => $box, 'time' => $times[$box->id]])
            ->filter(fn (array $row) => $technician === null || $row['time']['technician_id'] === $technician->id)
            ->values()->all();
    }
}
