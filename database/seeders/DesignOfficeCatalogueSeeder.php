<?php

namespace Database\Seeders;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditDecisionOutcome;
use App\Models\AuditAnomalyType;
use App\Models\AuditDecisionType;
use Illuminate\Database\Seeder;

/**
 * Built-in catalogues of the design office workflow (base data, safe in production and idempotent): the decisions of
 * the design office and the initial anomaly types. Their labels come from the translations (by code); supervisors add
 * their own entries, which are never overwritten here, and switch entries off instead of deleting them.
 */
class DesignOfficeCatalogueSeeder extends Seeder
{
    /**
     * Built-in decisions: code (= outcome), whether a reason is required, whether targets are required.
     *
     * @var list<array{AuditDecisionOutcome, bool, bool}>
     */
    public const array DECISIONS = [
        [AuditDecisionOutcome::Validate, false, false],
        [AuditDecisionOutcome::ValidateWithReserves, true, false],
        [AuditDecisionOutcome::Refuse, true, true],
        [AuditDecisionOutcome::RequestControl, true, false],
        [AuditDecisionOutcome::AuthorizeContinuation, true, false],
        [AuditDecisionOutcome::RepairsValidated, false, false],
        [AuditDecisionOutcome::RepairsPartial, true, false],
        [AuditDecisionOutcome::RepairsRefused, true, false],
    ];

    /**
     * Built-in anomaly types: code and nature.
     *
     * @var array<string, AuditAnomalyNature>
     */
    public const array ANOMALY_TYPES = [
        'faulty_tco' => AuditAnomalyNature::Optical,
        'broken_fibre' => AuditAnomalyNature::Optical,
        'poor_splice_or_slack' => AuditAnomalyNature::Optical,
        'rubber_seal_to_replace' => AuditAnomalyNature::Mechanical,
        'cable_entry_sealant' => AuditAnomalyNature::Mechanical,
        'box_not_watertight' => AuditAnomalyNature::Mechanical,
        'fixing' => AuditAnomalyNature::Mechanical,
        'label_to_replace' => AuditAnomalyNature::Mechanical,
        'half_moon_bracket' => AuditAnomalyNature::Mechanical,
        'missing_label_photo' => AuditAnomalyNature::Mechanical,
        'other_anomaly' => AuditAnomalyNature::Mechanical,
    ];

    public function run(): void
    {
        foreach (self::DECISIONS as $position => [$outcome, $requiresReason, $requiresTargets]) {
            AuditDecisionType::query()->firstOrCreate(['code' => $outcome->value], [
                'submission_kind' => $outcome->submissionKind(), 'outcome' => $outcome, 'requires_reason' => $requiresReason,
                'requires_targets' => $requiresTargets, 'is_active' => true, 'position' => $position + 1,
            ]);
        }

        $position = 0;

        foreach (self::ANOMALY_TYPES as $code => $nature) {
            AuditAnomalyType::query()->firstOrCreate(['code' => $code], ['nature' => $nature, 'is_active' => true, 'position' => ++$position]);
        }
    }
}
