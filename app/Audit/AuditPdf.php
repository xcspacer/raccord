<?php

namespace App\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditPoleSection;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditCable;
use App\Models\AuditControlPoint;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Planning\ReverseGeocoder;
use App\Settings\CompanySettings;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * The PDF of a box or of a control point: its facts, its comments, its report and its photos in a grid. It is the
 * client deliverable, so it is always written in the installation's default language, whoever asks for it, and never
 * carries the office's internal exchanges (box messages, the comment of a box sent back, folder validations). Only one
 * PDF is kept for each box or point: a new one replaces the previous.
 *
 * @phpstan-type Cell array{photo: AuditPhoto, caption: string}
 * @phpstan-type Section array{title: string, count: int, note: string|null, cells: list<Cell>}
 * @phpstan-type Fact array{label: string, value: string, url: string|null}
 */
class AuditPdf
{
    private const int COLUMNS = 4;

    public function __construct(
        private BoxFolders $folders,
        private BoxProgress $progress,
        private CompanySettings $company,
        private ReverseGeocoder $geocoder,
        private StoreUploadedFile $files,
    ) {}

    public function forBox(AuditBox $box): void
    {
        $this->inLanguage(function () use ($box): void {
            $box->load(['audit', 'cables', 'photos.file']);
            $contents = $this->render($box->audit, $box->name, $this->boxFacts($box), $this->boxSections($box), $box->report_items ?? []);

            $this->store($box, $contents, $this->boxFileName($box));
        });
    }

    public function forPoint(AuditControlPoint $point): void
    {
        $this->inLanguage(function () use ($point): void {
            $point->load(['audit', 'photos.file']);
            $contents = $this->render($point->audit, $point->name, $this->pointFacts($point), $this->pointSections($point), $point->report_items ?? []);

            $this->store($point, $contents, $this->fileName($point->name));
        });
    }

    /**
     * @param  list<Fact>  $facts
     * @param  list<Section>  $sections
     * @param  list<array{id: string, label: string, qty: int}>  $report
     */
    private function render(Audit $audit, string $title, array $facts, array $sections, array $report): string
    {
        $company = $this->company->displayName();
        $now = now(config('platform.timezone'));

        $pdf = Pdf::loadView('pdf.audit-unit', [
            'title' => $title,
            'folder' => $audit->folder,
            'header' => __('pdf.audit.header', ['audit' => trim($audit->cabinet_key.' - '.$audit->sro, ' -')]),
            'logo' => PdfImage::logoDataUri($this->company->logo_path),
            'company' => $company,
            'facts' => $facts,
            'sections' => $sections,
            'report' => array_map(fn (array $item) => '- '.$this->clean($item['label']).' x '.$item['qty'], $report),
            'columns' => self::COLUMNS,
        ])->setPaper('a4');

        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $text = __('pdf.audit.footer', ['company' => $company, 'date' => $now->translatedFormat('d/m/Y H:i'), 'page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $canvas->page_text(($canvas->get_width() / 2) - 110, $canvas->get_height() - 28, $text, $font, 7.5, [0.42, 0.45, 0.5]);

        return $pdf->output();
    }

    /**
     * @return list<Fact>
     */
    private function boxFacts(AuditBox $box): array
    {
        $audit = $box->audit;
        $mode = $audit->effectiveMode();
        $optical = $mode === AuditMode::Optical;
        $facts = [];

        $authors = $box->photos->pluck('user_name')->filter()->countBy()->sortDesc()->keys()->all();
        $facts[] = $this->fact(__('pdf.audit.technician'), implode(', ', $authors === [] ? array_filter([$box->status_by_name]) : $authors));
        $facts[] = $this->fact(__('pdf.audit.type'), implode(' - ', array_filter([$box->kind->label(), $box->type])));

        if ($optical) {
            $facts[] = $this->fact(__('pdf.audit.mode'), $mode->label());

            if ($box->confirmed_at !== null) {
                $facts[] = $this->fact(__('pdf.audit.position'), number_format((float) $box->confirmed_latitude, 6, '.', '').', '.number_format((float) $box->confirmed_longitude, 6, '.', '').($box->confirmed_accuracy !== null ? ' (±'.$box->confirmed_accuracy.' m)' : ''));
            }
        } else {
            $facts[] = $this->fact(__('pdf.audit.support'), implode(' ', array_filter([$box->support?->label(), $box->support_number])));
        }

        $facts[] = $this->fact($optical ? __('pdf.audit.cables') : __('pdf.audit.cable_type'), $optical ? $this->cablesText($box) : (string) $box->fibre_type?->label());
        $facts[] = $this->fact(__('pdf.audit.status'), trim(implode(' - ', array_filter([$box->status?->label(), $this->clean((string) $box->reason)]))));
        $facts[] = $this->fact(__('pdf.audit.progress'), $this->progress->percent($box, $mode).' %');

        return [...$facts, ...$this->placeFacts($box->address, $this->averagePosition($box->photos, $box))];
    }

    /**
     * @return list<Fact>
     */
    private function pointFacts(AuditControlPoint $point): array
    {
        $authors = $point->photos->pluck('user_name')->filter()->countBy()->sortDesc()->keys()->all();

        return [$this->fact(__('pdf.audit.technician'), implode(', ', $authors)), ...$this->placeFacts(null, $this->averagePosition($point->photos))];
    }

    /**
     * The address (given, or looked up under the photos) and the link to the place on the map.
     *
     * @param  array{float, float}|null  $position
     * @return list<Fact>
     */
    private function placeFacts(?string $address, ?array $position): array
    {
        $facts = [];

        if ($address === null && $position !== null) {
            $address = $this->geocoder->lookup($position[0], $position[1], (string) config('app.locale'))['address'] ?? null;
        }

        if ($address !== null && $address !== '') {
            $facts[] = $this->fact(__('pdf.audit.address'), $address);
        }

        if ($position !== null) {
            $facts[] = $this->fact(__('pdf.audit.location'), __('pdf.audit.open_map'), 'https://www.google.com/maps?q='.number_format($position[0], 6, '.', '').','.number_format($position[1], 6, '.', ''));
        }

        return $facts;
    }

    /**
     * @param  Collection<int, AuditPhoto>  $photos
     * @return array{float, float}|null
     */
    private function averagePosition(Collection $photos, ?AuditBox $box = null): ?array
    {
        $located = $photos->filter(fn (AuditPhoto $photo) => $photo->latitude !== null && $photo->longitude !== null);

        if ($located->isNotEmpty()) {
            return [(float) $located->avg('latitude'), (float) $located->avg('longitude')];
        }

        if ($box?->confirmed_at !== null) {
            return [(float) $box->confirmed_latitude, (float) $box->confirmed_longitude];
        }

        return $box?->latitude !== null ? [(float) $box->latitude, (float) $box->longitude] : null;
    }

    private function cablesText(AuditBox $box): string
    {
        if ($box->cables->isEmpty()) {
            return '';
        }

        $list = $box->cables->map(fn (AuditCable $cable) => trim(implode(' ', array_filter([$cable->capacity, $cable->fibre_type?->label()])).($cable->note ? ' ('.$this->clean($cable->note).')' : '')))->implode(', ');

        return trans_choice('pdf.audit.cable_count', $box->cables->count(), ['count' => $box->cables->count()]).' : '.$list;
    }

    /**
     * @return list<Section>
     */
    private function boxSections(AuditBox $box): array
    {
        $mode = $box->audit->effectiveMode();
        $sections = [];
        $handled = [];
        $folders = [...($mode === AuditMode::Full ? [AuditFolder::Before] : []), ...$this->folders->for($box, $mode, $box->photos->contains('folder', AuditFolder::Tco->value)), AuditFolder::Recheck];

        foreach ($folders as $folder) {
            $handled[] = $folder->value;
            $photos = $this->inFolder($box->photos, $folder);
            $note = match ($folder) {
                AuditFolder::Before => $box->note_before,
                AuditFolder::Tco => $box->note_tco,
                default => null,
            };

            if ($folder === AuditFolder::Pole) {
                foreach (AuditPoleSection::cases() as $section) {
                    $sections[] = $this->section($folder->label().' - '.$section->label(), $photos->where('subfolder', $section->value), null);
                }
            } elseif (in_array($folder, [AuditFolder::Labels, AuditFolder::Lashing], true) && $mode === AuditMode::Optical) {
                foreach ($box->cables as $index => $cable) {
                    $sections[] = $this->section($folder->label().' - '.__('audit.field.cable_n', ['n' => $index + 1], (string) config('app.locale')), $photos->where('subfolder', $cable->code), null);
                }
            } else {
                $sections[] = $this->section($folder->label(), $photos, $note);
            }
        }

        foreach ($box->photos->reject(fn (AuditPhoto $photo) => in_array($photo->folder, $handled, true))->groupBy('folder') as $folder => $photos) {
            $sections[] = $this->section(AuditFolder::tryFrom((string) $folder)?->label() ?? (string) $folder, $photos, null);
        }

        $sections[] = $this->section(__('pdf.audit.route'), collect(), $box->note_route);

        return array_values(array_filter($sections));
    }

    /**
     * @return list<Section>
     */
    private function pointSections(AuditControlPoint $point): array
    {
        $subfolders = $point->subfolders ?? [];
        $sections = [];

        foreach ($subfolders === [] ? [''] : $subfolders as $sub) {
            $title = $sub === '' ? $point->name : $point->name.' - '.(AuditPoleSection::tryFrom($sub)?->label() ?? $sub);
            $sections[] = $this->section($title, $point->photos->where('subfolder', $sub), null);
        }

        $sections[] = $this->section(__('pdf.audit.note'), collect(), $point->note);

        return array_values(array_filter($sections));
    }

    /**
     * @param  Collection<int, AuditPhoto>  $photos
     * @return Collection<int, AuditPhoto>
     */
    private function inFolder(Collection $photos, AuditFolder $folder): Collection
    {
        return $photos->where('folder', $folder->value);
    }

    /**
     * A section is only written when it has photos or a text.
     *
     * @param  Collection<int, AuditPhoto>  $photos
     * @return Section|null
     */
    private function section(string $title, Collection $photos, ?string $note): ?array
    {
        $note = $note === null ? null : $this->clean($note);

        if ($photos->isEmpty() && ($note === null || $note === '')) {
            return null;
        }

        $timezone = (string) config('platform.timezone');

        return [
            'title' => $this->clean($title),
            'count' => $photos->count(),
            'note' => $note === '' ? null : $note,
            'cells' => $photos->values()->map(fn (AuditPhoto $photo) => ['photo' => $photo, 'caption' => $photo->captured_at->timezone($timezone)->format('d/m/Y H:i')])->all(),
        ];
    }

    /**
     * @return Fact
     */
    private function fact(string $label, string $value, ?string $url = null): array
    {
        return ['label' => $label, 'value' => $this->clean($value), 'url' => $url];
    }

    /**
     * Emojis and pictograms do not exist in the fonts of the PDF: they are dropped.
     */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text));
    }

    private function boxFileName(AuditBox $box): string
    {
        $suffix = $box->status === null ? null : __('audit.pdf.suffix.'.$box->status->value);

        return $this->fileName($suffix === null ? $box->name : $box->name.' - '.$suffix);
    }

    private function fileName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $name)), 0, 150).'.pdf';
    }

    /**
     * Keeps the new PDF and lets go of the previous one.
     */
    private function store(AuditBox|AuditControlPoint $unit, string $contents, string $name): void
    {
        $previous = $unit->pdfFile;
        $stored = $this->files->handleContents($contents, 'application/pdf', 'pdf');

        $unit->forceFill(['pdf_file_id' => $stored->id, 'pdf_name' => $name, 'pdf_generated_at' => now()])->save();

        if ($previous instanceof StoredFile) {
            $this->files->release($previous);
        }
    }

    /**
     * @param  callable(): void  $work
     */
    private function inLanguage(callable $work): void
    {
        $previous = app()->getLocale();
        app()->setLocale((string) config('app.locale'));

        try {
            $work();
        } finally {
            app()->setLocale($previous);
        }
    }
}
