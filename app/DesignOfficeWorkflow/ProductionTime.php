<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditDurationAdjustment;
use App\Models\AuditPause;
use App\Models\AuditPhoto;
use App\Models\AuditSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Production time of a box: from its first photo taken on site to the submission the design office accepted (the
 * first complete one), minus the time spent waiting for the design office on the earlier submissions and minus the
 * pauses declared on the box (overlaps counted once). A supervisor may correct it with a justification; the computed
 * value is kept beside the correction, and the latest correction is the one used.
 *
 * @phpstan-type Row array{start: CarbonImmutable|null, end: CarbonImmutable|null, gross: int|null, waiting: int, paused: int, computed: int|null, adjusted: int|null, effective: int|null, technician_id: int|null, adjustment: AuditDurationAdjustment|null}
 */
class ProductionTime
{
    /** Largest corrected duration accepted (seconds). */
    public const int MAX_SECONDS = 7 * 24 * 3600;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @return Row
     */
    public function of(AuditBox $box): array
    {
        return $this->forBoxes(collect([$box]))[$box->id];
    }

    /**
     * @param  Collection<int, AuditBox>  $boxes
     * @return array<int, Row>
     */
    public function forBoxes(Collection $boxes): array
    {
        $ids = $boxes->map(fn (AuditBox $box) => $box->id)->values()->all();
        $firstPhotos = AuditPhoto::query()->whereIn('audit_box_id', $ids)->where('is_imported', false)->groupBy('audit_box_id')->pluck(DB::raw('MIN(captured_at)'), 'audit_box_id');
        $submissions = AuditSubmission::query()->whereIn('audit_box_id', $ids)->where('kind', AuditSubmissionKind::Inspection)
            ->with('validationRequest.decision.type')->orderBy('received_at')->orderBy('id')->get()->groupBy('audit_box_id');
        $pauses = AuditPause::query()->whereIn('audit_box_id', $ids)->get()->groupBy('audit_box_id');
        $adjustments = AuditDurationAdjustment::query()->whereIn('audit_box_id', $ids)->orderBy('id')->get()->keyBy('audit_box_id');

        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = $this->row(
                $firstPhotos->get($id),
                $submissions->get($id) ?? new Collection,
                $pauses->get($id) ?? new Collection,
                $adjustments->get($id),
            );
        }

        return $rows;
    }

    /**
     * @throws WorkflowException
     */
    public function adjust(User $supervisor, AuditBox $box, int $seconds, string $justification): AuditDurationAdjustment
    {
        $box->loadMissing('audit');
        $this->guard->ensureDesignOffice($box->audit);
        $justification = trim($justification);

        if (mb_strlen($justification) < 5) {
            throw new WorkflowException('justification_required', 422);
        }

        if ($seconds < 0 || $seconds > self::MAX_SECONDS) {
            throw new WorkflowException('duration_invalid', 422);
        }

        $row = $this->of($box);

        if ($row['computed'] === null) {
            throw new WorkflowException('duration_unknown', 409);
        }

        return DB::transaction(function () use ($supervisor, $box, $seconds, $justification, $row): AuditDurationAdjustment {
            $adjustment = AuditDurationAdjustment::query()->create([
                'audit_box_id' => $box->id, 'original_seconds' => $row['computed'], 'adjusted_seconds' => $seconds,
                'justification' => $justification, 'adjusted_by' => $supervisor->id, 'created_at' => now(),
            ]);

            $this->journal->record('duration_adjusted', $adjustment, $supervisor, ['box' => $box->name, 'original' => $row['computed'], 'old' => $row['effective'], 'new' => $seconds, 'reason' => $justification]);

            return $adjustment;
        });
    }

    /**
     * « 1 h 05 min », « 12 min », or « — » when unknown.
     */
    public static function format(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $minutes = intdiv($seconds + 30, 60);

        return $minutes >= 60
            ? __('design_office_workflow.indicators.hours', ['hours' => intdiv($minutes, 60), 'minutes' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)])
            : __('design_office_workflow.indicators.minutes_only', ['minutes' => $minutes]);
    }

    /**
     * @param  Collection<int, AuditSubmission>  $submissions
     * @param  Collection<int, AuditPause>  $pauses
     * @return Row
     */
    private function row(mixed $firstPhoto, Collection $submissions, Collection $pauses, ?AuditDurationAdjustment $adjustment): array
    {
        $completing = $submissions->first(fn (AuditSubmission $submission) => $submission->validationRequest?->decision?->type->outcome->validatesInspection() === true);
        $start = $firstPhoto === null ? null : CarbonImmutable::parse((string) $firstPhoto);
        $end = $completing === null ? null : CarbonImmutable::parse($completing->received_at);
        $row = ['start' => $start, 'end' => $end, 'gross' => null, 'waiting' => 0, 'paused' => 0, 'computed' => null, 'adjusted' => $adjustment?->adjusted_seconds, 'effective' => $adjustment?->adjusted_seconds, 'technician_id' => $completing?->submitted_by, 'adjustment' => $adjustment];

        if ($start === null || $end === null || $end->lt($start)) {
            return $row;
        }

        $waiting = [];

        foreach ($submissions as $submission) {
            $request = $submission->validationRequest;

            if ($submission->is($completing) || $request === null || CarbonImmutable::parse($submission->received_at)->gt($end)) {
                continue;
            }

            $waiting[] = [CarbonImmutable::parse($request->queued_at), $request->decided_at === null ? $end : CarbonImmutable::parse($request->decided_at)];
        }

        $paused = $pauses->map(fn (AuditPause $pause) => [CarbonImmutable::parse($pause->started_at), $pause->ended_at === null ? $end : CarbonImmutable::parse($pause->ended_at)])->all();
        $gross = (int) $start->diffInSeconds($end);
        $excluded = $this->covered([...$waiting, ...$paused], $start, $end);

        $row['gross'] = $gross;
        $row['waiting'] = $this->covered($waiting, $start, $end);
        $row['paused'] = $this->covered($paused, $start, $end);
        $row['computed'] = max(0, $gross - $excluded);
        $row['effective'] = $row['adjusted'] ?? $row['computed'];

        return $row;
    }

    /**
     * Seconds covered by the union of the intervals, within [from, to].
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     */
    private function covered(array $intervals, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $clamped = [];

        foreach ($intervals as [$start, $end]) {
            $start = $start->max($from);
            $end = $end->min($to);

            if ($end->gt($start)) {
                $clamped[] = [$start->getTimestamp(), $end->getTimestamp()];
            }
        }

        usort($clamped, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $total = 0;
        $current = null;

        foreach ($clamped as [$start, $end]) {
            if ($current === null || $start > $current[1]) {
                $total += $current === null ? 0 : $current[1] - $current[0];
                $current = [$start, $end];
            } else {
                $current[1] = max($current[1], $end);
            }
        }

        return $total + ($current === null ? 0 : $current[1] - $current[0]);
    }
}
