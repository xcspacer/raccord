<?php

namespace App\Audit;

use App\Audit\Recheck\BoxRecheck;
use App\ClientPortal\ClientRemarks;
use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditPlanKind;
use App\Enums\AuditPoleSection;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditCable;
use App\Models\AuditControlPoint;
use App\Models\AuditDrawerRecheck;
use App\Models\AuditFolderValidation;
use App\Models\AuditPhoto;
use App\Models\AuditPlan;
use App\Models\AuditPresence;
use App\Models\AuditRemark;
use App\Models\ClientRemark;
use App\Models\User;

/**
 * The whole audit as the phones keep it: what the screen needs to work without a network, reloaded when the
 * revision changes. Names and values are the ones of the operations, so a phone can replay its queue on top of it.
 */
class AuditSnapshot
{
    public function __construct(private AuditCatalog $catalog, private BoxRecheck $recheck, private ClientRemarks $clientRemarks) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Audit $audit, User $viewer): array
    {
        $audit->load(['boxes.cables', 'boxes.photos', 'boxes.remarks', 'boxes.folderValidations', 'boxes.recheck', 'plans.file', 'controlPoints.photos', 'presences.user:id,name']);

        if ($audit->usesDesignOfficeWorkflow()) {
            $audit->boxes->load('officialPosition');
        }

        return [
            'revision' => $audit->revision,
            'server_time' => now()->toIso8601String(),
            'viewer' => ['id' => $viewer->id, 'name' => $viewer->name, 'manager' => $viewer->can('manage', Audit::class)],
            'audit' => [
                'cabinet' => $audit->cabinet_key, 'sro' => $audit->sro, 'mode' => $audit->effectiveMode()->value, 'workflow' => $audit->workflow->value,
                'finished' => $audit->isFinished() ? ['by' => $audit->finished_by_name, 'at' => $audit->finished_at?->toIso8601String()] : null,
                'validated' => $audit->isValidated() ? ['by' => $audit->validated_by_name, 'at' => $audit->validated_at?->toIso8601String()] : null,
                'closed' => $audit->usesDesignOfficeWorkflow() && $audit->isClosed() ? ['at' => $audit->closed_at?->toIso8601String()] : null,
                'cabinet_position' => $audit->cabinet_latitude !== null && $audit->cabinet_longitude !== null ? ['latitude' => (float) $audit->cabinet_latitude, 'longitude' => (float) $audit->cabinet_longitude] : null,
                'drawer' => $this->drawerInfo($audit),
            ],
            'boxes' => $audit->boxes->map(fn (AuditBox $box) => $this->box($box, $audit))->values()->all(),
            'points' => $audit->controlPoints->map(fn (AuditControlPoint $point) => $this->point($point, $audit))->values()->all(),
            'presences' => $audit->presences->filter(fn (AuditPresence $presence) => $presence->seen_at->gt(now()->subHours(12)))->map(fn (AuditPresence $presence) => [
                'user_id' => $presence->user_id, 'name' => $presence->user?->name, 'box_id' => $presence->audit_box_id, 'state' => $presence->state->value,
                'automatic' => $presence->is_automatic, 'since' => $presence->since->toIso8601String(),
            ])->values()->all(),
            'client_notes' => $this->clientRemarks->pendingFor($viewer, $audit)->map(fn (ClientRemark $note) => [
                'id' => $note->id, 'box_id' => $note->audit_box_id, 'point_id' => $note->audit_control_point_id,
                'author' => $note->author_name, 'at' => $note->created_at->toIso8601String(), 'text' => $note->body,
            ])->values()->all(),
            'catalog' => $this->catalog->all(),
            'rules' => [
                'capacities' => config('audit.capacities'),
                'pole_sections' => array_map(fn (AuditPoleSection $section) => $section->value, AuditPoleSection::cases()),
                'folders' => array_map(fn (AuditFolder $folder) => $folder->value, AuditFolder::cases()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function box(AuditBox $box, Audit $audit): array
    {
        return [
            'id' => $box->id, 'name' => $box->name, 'kind' => $box->kind->value, 'type' => $box->type, 'address' => $box->address,
            'support' => $box->support?->value, 'support_number' => $box->support_number, 'source' => $box->source->value, 'fibre_type' => $box->fibre_type?->value,
            'notes' => ['route' => $box->note_route, 'before' => $box->note_before, 'tco' => $box->note_tco], 'recheck_state' => $box->recheck_state,
            'status' => $box->status?->value, 'reason' => $box->reason, 'removed' => $box->isRemoved(),
            'position' => $box->latitude === null ? null : ['latitude' => (float) $box->latitude, 'longitude' => (float) $box->longitude],
            'confirmed' => $box->confirmed_at === null ? null : [
                'latitude' => (float) $box->confirmed_latitude, 'longitude' => (float) $box->confirmed_longitude, 'accuracy' => $box->confirmed_accuracy,
                'by' => $box->confirmed_by_name, 'at' => $box->confirmed_at->toIso8601String(),
            ],
            ...($audit->usesDesignOfficeWorkflow() ? ['official' => $box->officialPosition === null ? null : ['latitude' => (float) $box->officialPosition->latitude, 'longitude' => (float) $box->officialPosition->longitude]] : []),
            'returned' => $box->returned_at === null ? null : ['comment' => $box->returned_comment, 'by' => $box->returned_by_name, 'at' => $box->returned_at->toIso8601String()],
            'report_items' => $box->report_items ?? [], 'pdf' => $this->pdf($box, $audit), 'recheck' => $this->recheckInfo($box, $audit),
            'cables' => $box->cables->map(fn (AuditCable $cable) => [
                'code' => $cable->code, 'has_no_label' => $cable->has_no_label, 'capacity' => $cable->capacity, 'fibre_type' => $cable->fibre_type?->value, 'note' => $cable->note,
            ])->values()->all(),
            'photos' => $box->photos->map(fn (AuditPhoto $photo) => $this->photo($photo))->values()->all(),
            'validations' => $box->folderValidations->mapWithKeys(fn (AuditFolderValidation $validation) => [$validation->folder => ['count' => $validation->photos_count, 'by' => $validation->user_name, 'at' => $validation->validated_at->toIso8601String()]])->all(),
            'remarks' => $box->remarks->map(fn (AuditRemark $remark) => [
                'id' => $remark->client_ref, 'side' => $remark->author_side, 'text' => $remark->text, 'by' => $remark->user_name, 'at' => $remark->created_at->toIso8601String(),
                'acknowledged' => $remark->acknowledged_at === null ? null : ['by' => $remark->acknowledged_by_name, 'at' => $remark->acknowledged_at->toIso8601String()],
            ])->values()->all(),
        ];
    }

    /**
     * The recheck against the plan (optical mode, box with an Excel plan): where to do it and how far it is.
     *
     * @return array{url: string, conform: bool, saved: bool}|null
     */
    private function recheckInfo(AuditBox $box, Audit $audit): ?array
    {
        if ($audit->effectiveMode() !== AuditMode::Optical || $this->recheck->planFor($box, $audit, $audit->plans) === null) {
            return null;
        }

        return ['url' => route('audits.recheck', ['cabinet' => $audit->cabinet_key, 'box' => $box->id], false), 'conform' => (bool) $box->recheck?->is_conform, 'saved' => $box->recheck?->recnok_file_id !== null];
    }

    /**
     * @return array{name: string, at: string, url: string}|null
     */
    private function pdf(AuditBox|AuditControlPoint $unit, Audit $audit): ?array
    {
        if ($unit->pdf_file_id === null) {
            return null;
        }

        $type = $unit instanceof AuditBox ? 'box' : 'point';

        return ['name' => (string) $unit->pdf_name, 'at' => $unit->pdf_generated_at?->toIso8601String() ?? '', 'url' => route('audits.pdf', ['cabinet' => $audit->cabinet_key, 'type' => $type, 'id' => $unit->id], false)];
    }

    /**
     * The drawer plan of the cabinet: its recheck (optical mode, Excel plan) and the plan as found on site once saved.
     *
     * @return array{recheck_url: string|null, after_works_url: string|null}
     */
    private function drawerInfo(Audit $audit): array
    {
        $plan = $audit->plans->first(fn (AuditPlan $plan) => $plan->kind === AuditPlanKind::Drawer && str_ends_with(mb_strtolower($plan->name), '.xlsx'));
        $saved = AuditDrawerRecheck::query()->where('audit_id', $audit->id)->whereNotNull('recnok_file_id')->exists();

        return [
            'recheck_url' => $audit->effectiveMode() === AuditMode::Optical && $plan !== null ? route('audits.drawer', ['cabinet' => $audit->cabinet_key], false) : null,
            'after_works_url' => $saved ? route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'drawer'], false) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function point(AuditControlPoint $point, Audit $audit): array
    {
        return [
            'id' => $point->id, 'name' => $point->name, 'kind' => $point->kind, 'subfolders' => $point->subfolders ?? [], 'note' => $point->note, 'report_items' => $point->report_items ?? [], 'pdf' => $this->pdf($point, $audit),
            'photos' => $point->photos->map(fn (AuditPhoto $photo) => $this->photo($photo))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function photo(AuditPhoto $photo): array
    {
        return [
            'id' => $photo->id, 'folder' => $photo->folder, 'subfolder' => $photo->subfolder, 'captured_at' => $photo->captured_at->toIso8601String(), 'user_id' => $photo->user_id,
            'user_name' => $photo->user_name, 'imported' => $photo->is_imported,
            'thumb_url' => route('audits.photos.show', ['photo' => $photo->id, 'variant' => 'thumb'], false), 'full_url' => route('audits.photos.show', ['photo' => $photo->id, 'variant' => 'full'], false),
        ];
    }
}
