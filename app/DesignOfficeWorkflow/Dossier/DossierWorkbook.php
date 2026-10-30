<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Enums\AuditOccurrencePhotoRole;
use App\Models\AuditBox;
use App\Models\AuditMissingProof;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditReserve;
use App\Settings\CompanySettings;
use App\Support\SheetText;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The repairs workbook of an official dossier version: one sheet for the anomalies (with the ZIP paths of their
 * Before / After photos), one for the boxes and their position, one for the reserves and one for the missing proofs.
 * Typed texts are always written as plain text, never as formulas.
 */
class DossierWorkbook
{
    private const string HEADER_FILL = 'FF0E2836';

    public function __construct(private CompanySettings $company) {}

    /**
     * @param  array<int, string>  $photoPaths  ZIP path of each photo, by photo id
     */
    public function build(DossierContents $contents, array $photoPaths): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator($this->company->displayName())->setCompany($this->company->displayName())
            ->setTitle(__('design_office_dossier.workbook.title', ['cabinet' => $contents->audit->cabinet_key]));
        $book->removeSheetByIndex(0);

        $this->sheet($book, 'repairs', ['reference', 'boxes', 'nature', 'type', 'state', 'description', 'location', 'action', 'checks', 'observations', 'detected_by', 'detected_on', 'corrected_by', 'corrected_on', 'validated_by', 'validated_on', 'before_photos', 'after_photos'],
            $contents->occurrences->map(fn (AuditOccurrence $occurrence) => [
                $occurrence->reference, implode(', ', $contents->occurrenceBoxes($occurrence)), $occurrence->nature->label(), $occurrence->type->displayLabel(), $occurrence->state->label(),
                $occurrence->description, (string) $occurrence->location_note, (string) $occurrence->action_taken, (string) $occurrence->checks_done, (string) $occurrence->observations,
                $contents->name($occurrence->created_by), $this->date($occurrence->created_at), $contents->name($occurrence->corrected_by), $this->date($occurrence->corrected_at),
                $contents->name($occurrence->validated_by), $this->date($occurrence->validated_at),
                $this->photos($occurrence, AuditOccurrencePhotoRole::Before, $photoPaths), $this->photos($occurrence, AuditOccurrencePhotoRole::After, $photoPaths),
            ])->all());

        $this->sheet($book, 'boxes', ['box', 'kind', 'support', 'support_number', 'conformity', 'latitude', 'longitude', 'position_source'],
            $contents->boxes->map(function (AuditBox $box) use ($contents): array {
                $position = $contents->positions[$box->id] ?? null;

                return [$box->name, $box->kind->label(), (string) $box->support?->label(), (string) $box->support_number, $box->conformity->label(),
                    $position['latitude'] ?? null, $position['longitude'] ?? null, $position === null ? '' : $position['source']->label()];
            })->all());

        $this->sheet($book, 'reserves', ['box', 'reference', 'reserve_kind', 'justification', 'accepted_by', 'accepted_on', 'closed_on', 'closing_reason'],
            $contents->reserves->map(fn (AuditReserve $reserve) => [
                $reserve->box->name, (string) $reserve->occurrence?->reference, $reserve->kind->label(), $reserve->reason,
                $contents->name($reserve->accepted_by), $this->date($reserve->accepted_at), $this->date($reserve->closed_at), (string) $reserve->closing_reason,
            ])->all());

        $this->sheet($book, 'proofs', ['box', 'folder', 'justification', 'decision', 'decided_by'],
            $contents->missingProofs->map(fn (AuditMissingProof $proof) => [
                $proof->box->name, $proof->folder->label(), $proof->justification,
                match ($proof->is_accepted) {
                    true => __('design_office_dossier.report.proof_accepted'),
                    false => __('design_office_dossier.report.proof_refused'),
                    null => __('design_office_dossier.report.proof_undecided'),
                },
                $contents->name($proof->decided_by),
            ])->all());

        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function sheet(Spreadsheet $book, string $name, array $columns, array $rows): Worksheet
    {
        $sheet = $book->createSheet();
        $sheet->setTitle(mb_substr(__('design_office_dossier.workbook.sheets.'.$name), 0, 31));
        SheetText::row($sheet, array_map(fn (string $column) => __('design_office_dossier.columns.'.$column), $columns), 1);

        foreach ($rows as $index => $row) {
            SheetText::row($sheet, $row, $index + 2);
        }

        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->freezePane('A2');

        foreach (range(1, count($columns)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(22);
        }

        return $sheet;
    }

    /**
     * @param  array<int, string>  $photoPaths
     */
    private function photos(AuditOccurrence $occurrence, AuditOccurrencePhotoRole $role, array $photoPaths): string
    {
        return $occurrence->photoLinks->where('role', $role)
            ->map(fn (AuditOccurrencePhoto $link) => $photoPaths[$link->audit_photo_id] ?? null)->filter()->implode(' | ');
    }

    private function date(?CarbonInterface $date): string
    {
        return $date?->timezone((string) config('platform.timezone'))->format('d/m/Y H:i') ?? '';
    }
}
