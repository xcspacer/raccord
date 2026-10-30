<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Enums\AuditBoxStatus;
use App\Enums\AuditConformity;
use App\Enums\AuditDossierKind;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditReserveKind;
use App\Models\AuditBox;
use App\Models\AuditDecision;
use App\Models\AuditDossierVersion;
use App\Models\AuditMissingProof;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditReserve;
use App\Settings\CompanySettings;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;

/**
 * The report of an official dossier version (PDF): summary, boxes and their conformity, register of the anomalies,
 * one sheet per anomaly with its Before / After photos (small, a few per side), reserves, missing proofs and the trace
 * of the design office's decisions (without their internal comments). Written in the dossier's language, in the
 * company's name from the platform settings.
 */
class DossierReport
{
    private const int PHOTOS_PER_SIDE = 3;

    public function __construct(private CompanySettings $company) {}

    public function render(DossierContents $contents, AuditDossierVersion $version): string
    {
        $audit = $contents->audit;
        $company = $this->company->displayName();
        $cabinet = trim($audit->cabinet_key.($audit->sro ? ' · '.$audit->sro : ''));

        $pdf = Pdf::loadView('pdf.design-office-dossier', [
            'title' => __('design_office_dossier.report.title', ['cabinet' => $audit->cabinet_key]),
            'cabinet' => $cabinet,
            'company' => $company,
            'logo' => PdfImage::logoDataUri($this->company->logo_path),
            'facts' => array_values(array_filter([
                [__('design_office_dossier.report.version'), __('design_office_dossier.report.version_value', ['number' => $version->version_number, 'kind' => $version->kind->label()])],
                [__('design_office_dossier.report.closed'), trim($this->date($audit->closed_at).' '.($contents->name($audit->closed_by) !== '' ? __('design_office_dossier.report.by', ['name' => $contents->name($audit->closed_by)]) : ''))],
                [__('design_office_dossier.report.authorized_by'), $version->authorized_by !== null ? (string) $version->authorizer?->name : __('design_office_dossier.report.automatic')],
                $version->kind === AuditDossierKind::Update ? [__('design_office_dossier.report.reason'), $version->reason ?? __('design_office_dossier.reasons.automatic_update')] : null,
                [__('design_office_dossier.report.generated'), $this->date(now())],
            ])),
            'summary' => $this->summary($contents),
            'boxes' => $contents->boxes->map(fn (AuditBox $box) => [
                $box->name, $box->kind->label(), trim($box->support?->label().' '.$box->support_number),
                $box->status === AuditBoxStatus::NotInstalled ? $box->status->label() : $box->conformity->label(),
                $contents->reserves->where('audit_box_id', $box->id)->map(fn (AuditReserve $reserve) => trim(($reserve->occurrence->reference ?? '').' '.$reserve->kind->label()))->implode(' ; '),
            ])->all(),
            'register' => $contents->occurrences->map(fn (AuditOccurrence $occurrence) => [
                $occurrence->reference, implode(', ', $contents->occurrenceBoxes($occurrence)), $occurrence->nature->label(), $occurrence->state->label(),
                $occurrence->type->displayLabel(), trim($contents->name($occurrence->validated_by).' '.$this->date($occurrence->validated_at)), (string) $occurrence->action_taken,
            ])->all(),
            'sheets' => $contents->occurrences->map(fn (AuditOccurrence $occurrence) => $this->sheet($contents, $occurrence))->all(),
            'reserves' => $contents->reserves->map(fn (AuditReserve $reserve) => [
                (string) $reserve->occurrence?->reference, $reserve->box->name, $reserve->kind->label(), $reserve->reason,
                trim($contents->name($reserve->accepted_by).' '.$this->date($reserve->accepted_at)),
                $reserve->closed_at !== null ? trim($this->date($reserve->closed_at).' '.$reserve->closing_reason) : '',
            ])->all(),
            'proofs' => $contents->missingProofs->map(fn (AuditMissingProof $proof) => [
                $proof->box->name, $proof->folder->label(), $proof->justification,
                match ($proof->is_accepted) {
                    true => __('design_office_dossier.report.proof_accepted'),
                    false => __('design_office_dossier.report.proof_refused'),
                    null => __('design_office_dossier.report.proof_undecided'),
                },
                $contents->name($proof->decided_by),
            ])->all(),
            'decisions' => $contents->decisions->map(fn (AuditDecision $decision) => [
                $decision->request->box->name, $decision->request->submission->kind->label(), $decision->type->displayLabel(),
                trim($contents->name($decision->decided_by).' · '.$this->date($decision->decided_at), ' ·'),
            ])->all(),
        ])->setPaper('a4');

        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $footer = __('design_office_dossier.report.footer', ['company' => $company, 'cabinet' => $audit->cabinet_key, 'version' => $version->version_number, 'page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $canvas->page_text(($canvas->get_width() / 2) - 110, $canvas->get_height() - 28, $footer, $font, 7.5, [0.42, 0.45, 0.5]);

        return $pdf->output();
    }

    /**
     * @return list<string>
     */
    private function summary(DossierContents $contents): array
    {
        $counted = $contents->boxes->reject(fn (AuditBox $box) => $box->status === AuditBoxStatus::NotInstalled);
        $conforming = $counted->where('conformity', AuditConformity::Conforming)->count();
        $withReserve = $counted->where('conformity', AuditConformity::ConformingWithReserve)->count();
        $occurrences = $contents->occurrences;

        return [
            __('design_office_dossier.report.summary_boxes', ['total' => $counted->count(), 'conforming' => $conforming, 'reserve' => $withReserve, 'other' => $counted->count() - $conforming - $withReserve]),
            __('design_office_dossier.report.summary_occurrences', [
                'total' => $occurrences->count(),
                'validated' => $occurrences->where('state', AuditOccurrenceState::Validated)->count(),
                'reserve' => $occurrences->where('state', AuditOccurrenceState::ReserveAccepted)->count(),
                'open' => $occurrences->reject(fn (AuditOccurrence $occurrence) => $occurrence->state->isClosed())->count(),
            ]),
            __('design_office_dossier.report.summary_reserves', [
                'follow_up' => $contents->reserves->where('kind', AuditReserveKind::FollowUp)->count(),
                'closed' => $contents->reserves->where('kind', AuditReserveKind::AdministrativeClosure)->count(),
            ]),
            __('design_office_dossier.report.reserve_notice'),
        ];
    }

    /**
     * @return array{title: string, lines: list<string>, before: list<string|null>, after: list<string|null>}
     */
    private function sheet(DossierContents $contents, AuditOccurrence $occurrence): array
    {
        $photos = fn (AuditOccurrencePhotoRole $role) => $occurrence->photoLinks->where('role', $role)->take(self::PHOTOS_PER_SIDE)
            ->map(fn (AuditOccurrencePhoto $link) => PdfImage::thumbnailDataUri($link->photo->file))->values()->all();

        return [
            'title' => $occurrence->reference.' — '.$occurrence->type->displayLabel().' — '.implode(', ', $contents->occurrenceBoxes($occurrence)),
            'lines' => array_values(array_filter([
                $occurrence->description,
                $occurrence->location_note ? __('design_office_dossier.report.location', ['text' => $occurrence->location_note]) : null,
                $occurrence->pending_justification ? __('design_office_dossier.report.pending', ['text' => $occurrence->pending_justification]) : null,
                $occurrence->action_taken ? __('design_office_dossier.report.action', ['text' => $occurrence->action_taken]) : null,
                $occurrence->checks_done ? __('design_office_dossier.report.checks', ['text' => $occurrence->checks_done]) : null,
                $occurrence->observations ? __('design_office_dossier.report.observations', ['text' => $occurrence->observations]) : null,
            ])),
            'before' => $photos(AuditOccurrencePhotoRole::Before),
            'after' => $photos(AuditOccurrencePhotoRole::After),
        ];
    }

    private function date(?CarbonInterface $date): string
    {
        return $date?->timezone((string) config('platform.timezone'))->format('d/m/Y H:i') ?? '';
    }
}
