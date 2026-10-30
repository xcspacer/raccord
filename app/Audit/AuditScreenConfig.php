<?php

namespace App\Audit;

use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\Audit;
use App\Models\User;
use App\Support\FeatureAccess;

/**
 * What the pages of the field screens need to start on a phone: the urls to talk to, the texts and labels in the
 * language of the person. The pages themselves hold no data of the audit.
 */
class AuditScreenConfig
{
    public function __construct(private FeatureAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Audit $audit, User $user): array
    {
        $key = $audit->cabinet_key;
        $designOffice = $this->designOffice($audit, $user);

        return [
            'cabinet' => $key,
            'userId' => $user->id,
            'timeZone' => config('platform.timezone'),
            'urls' => [
                'snapshot' => route('audits.snapshot', ['cabinet' => $key], false),
                'operations' => route('audits.operations', ['cabinet' => $key], false),
                'photos' => route('audits.photos.store', ['cabinet' => $key], false),
                'page' => route('audits.show', ['cabinet' => $key], false),
                'login' => route('login', [], false),
                'reverse' => route('geo.reverse', [], false),
                'clientNoteAck' => route('client-notes.acknowledge', ['remark' => '__ID__'], false),
            ],
            'texts' => __('audit.field') + ['drawer_recheck' => __('audit.toolbar.drawer_recheck'), 'drawer_after_works' => __('audit.toolbar.drawer_after_works')],
            'clientNoteTexts' => __('client_portal.staff'),
            'errors' => $designOffice === null ? __('audit.errors') : __('audit.errors') + __('design_office_workflow.errors'),
            'camera' => __('vehicle_inspection.camera') + __('audit.camera'),
            'navigation' => config('audit.navigation'),
            'navigationLabels' => ['waze' => 'Waze', 'maps' => 'Google Maps'],
            'labels' => [
                'folders' => __('enums.audit_folders'), 'kinds' => __('enums.audit_box_kinds'), 'supports' => __('enums.audit_supports'), 'poleSections' => __('enums.audit_pole_sections'),
                'statuses' => __('enums.audit_box_statuses'), 'fibres' => __('enums.audit_fibre_types'),
            ],
            'designOffice' => $designOffice,
        ];
    }

    /**
     * What the box screen needs for the design office workflow, for whoever may use it (the audit itself says, in its
     * snapshot, whether it follows that workflow). Box, message, occurrence and photo ids replace the placeholders of
     * the urls.
     *
     * @return array<string, mixed>|null
     */
    private function designOffice(Audit $audit, User $user): ?array
    {
        if (! $this->access->allowsDesignOfficeWorkflow($user)) {
            return null;
        }

        $box = ['box' => '__BOX__'];
        $occurrence = ['occurrence' => '__ID__'];

        return [
            'role' => match (true) {
                WorkflowRoles::isSupervisor($user) => 'supervisor',
                WorkflowRoles::isReviewer($user) => 'reviewer',
                WorkflowRoles::isTechnician($user) => 'technician',
                default => null,
            },
            'urls' => [
                'status' => route('design-office.boxes.show', $box, false),
                'claim' => route('design-office.boxes.claim', $box, false),
                'handOver' => route('design-office.boxes.hand-over', $box, false),
                'submit' => route('design-office.boxes.submit', $box, false),
                'submitRepairs' => route('design-office.boxes.submit-repairs', $box, false),
                'messages' => route('design-office.boxes.messages.store', $box, false),
                'resolve' => route('design-office.messages.resolve', ['message' => '__ID__'], false),
                'annotations' => route('design-office.photos.annotations', ['photo' => '__ID__'], false),
                'occurrenceCreate' => route('design-office.boxes.occurrences.store', $box, false),
                'occurrencePhoto' => route('design-office.occurrences.photos', $occurrence, false),
                'occurrenceHold' => route('design-office.occurrences.hold', $occurrence, false),
                'occurrenceCorrect' => route('design-office.occurrences.correct', $occurrence, false),
                'occurrenceBox' => route('design-office.occurrences.boxes.propose', $occurrence, false),
                'occurrenceResult' => route('design-office.occurrences.boxes.result', [...$occurrence, ...$box], false),
                'positionPropose' => route('design-office.boxes.positions.propose', $box, false),
                'synopticCorrection' => route('design-office.audits.synoptic-corrections.store', ['audit' => $audit->id], false),
                'pause' => route('design-office.boxes.pause', $box, false),
                'resume' => route('design-office.boxes.resume', $box, false),
            ],
            'texts' => __('design_office_workflow.field'),
            'kinds' => __('enums.audit_box_message_kinds'),
            'photoRoles' => __('enums.audit_occurrence_photo_roles'),
        ];
    }
}
