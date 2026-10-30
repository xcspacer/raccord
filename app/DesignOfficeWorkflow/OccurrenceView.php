<?php

namespace App\DesignOfficeWorkflow;

use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditOccurrencePhoto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Occurrences as the phone reads them (JSON): those concerning a box (its own, those of other boxes it is confirmed or
 * proposed to be impacted by), each with its photos by role and its boxes, and the anomaly types that can be chosen.
 */
class OccurrenceView
{
    /**
     * @return array{occurrences: list<array<string, mixed>>, anomaly_types: list<array{id: int, label: string, nature: string}>}
     */
    public function forBox(AuditBox $box): array
    {
        $occurrences = AuditOccurrence::query()
            ->whereHas('boxLinks', fn (Builder $links) => $links->where('audit_box_id', $box->id)->whereNull('excluded_at'))
            ->with(['type', 'photoLinks', 'boxLinks.box'])
            ->orderBy('id')->get();

        return [
            'occurrences' => $occurrences->map(fn (AuditOccurrence $occurrence) => [...$this->one($occurrence), 'is_inherited' => $occurrence->audit_box_id !== $box->id])->values()->all(),
            'anomaly_types' => $this->types(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function one(AuditOccurrence $occurrence): array
    {
        $occurrence->loadMissing(['type', 'photoLinks', 'boxLinks.box']);

        return [
            'id' => $occurrence->id,
            'reference' => $occurrence->reference,
            'box_id' => $occurrence->audit_box_id,
            'type_id' => $occurrence->audit_anomaly_type_id,
            'type' => $occurrence->type->displayLabel(),
            'nature' => $occurrence->nature->value,
            'nature_label' => $occurrence->nature->label(),
            'state' => $occurrence->state->value,
            'state_label' => $occurrence->state->label(),
            'is_closed' => $occurrence->state->isClosed(),
            'description' => $occurrence->description,
            'location_note' => $occurrence->location_note,
            'action_taken' => $occurrence->action_taken,
            'checks_done' => $occurrence->checks_done,
            'observations' => $occurrence->observations,
            'pending_justification' => $occurrence->pending_justification,
            'is_continuation_authorized' => $occurrence->pending_authorized_at !== null,
            'created_at' => $occurrence->created_at->toIso8601String(),
            'photos' => $occurrence->photoLinks->sortBy('id')->map(fn (AuditOccurrencePhoto $link) => ['id' => $link->audit_photo_id, 'role' => $link->role->value])->values()->all(),
            'boxes' => $occurrence->boxLinks->sortBy('id')->map(fn (AuditOccurrenceBox $link) => [
                'box_id' => $link->audit_box_id,
                'name' => $link->box->name,
                'is_origin' => $link->is_origin,
                'state' => self::linkState($link),
                'justification' => $link->justification,
                'exclusion_reason' => $link->exclusion_reason,
                'conformity' => $link->conformity->value,
                'note' => $link->note,
            ])->values()->all(),
        ];
    }

    /**
     * Where an impacted box stands: « origin », « proposed », « confirmed » or « excluded ».
     */
    public static function linkState(AuditOccurrenceBox $link): string
    {
        return match (true) {
            $link->is_origin => 'origin',
            $link->excluded_at !== null => 'excluded',
            $link->confirmed_at !== null => 'confirmed',
            default => 'proposed',
        };
    }

    /**
     * @return list<array{id: int, label: string, nature: string}>
     */
    private function types(): array
    {
        return AuditAnomalyType::query()->where('is_active', true)->orderBy('nature')->orderBy('position')->orderBy('id')->get()
            ->map(fn (AuditAnomalyType $type) => ['id' => $type->id, 'label' => $type->displayLabel(), 'nature' => $type->nature->value])
            ->values()->all();
    }
}
