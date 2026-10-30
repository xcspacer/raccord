<?php

namespace App\Audit;

use App\Enums\AuditFolder;
use App\Enums\AuditPoleSection;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxRecheck;
use App\Models\AuditControlPoint;
use App\Models\AuditDrawerRecheck;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Retours travaux »: the deliverable folder tree of every audit, built from the database. Each audit gives its
 * folders (empty ones included, as created from the configuration), its files (photos, PDFs, RECNOK workbooks) and
 * its units (a box or a control point, the level people open).
 *
 * @phpstan-type TreeFile array{path: string, file: StoredFile, at: CarbonImmutable, author: string|null, photo: bool}
 * @phpstan-type Unit array{path: string, name: string, cabinet: string, group: string, kind: string}
 * @phpstan-type Tree array{audit: Audit, name: string, dirs: list<string>, files: list<TreeFile>, units: list<Unit>}
 */
class AuditDeliverables
{
    public function __construct(private AuditTree $tree, private BoxFolders $folders) {}

    /**
     * Trees of every audit still open (archived audits are gone from it).
     *
     * @return list<Tree>
     */
    public function all(): array
    {
        return $this->build(Audit::query());
    }

    /**
     * The tree of the audit a path starts with, or null.
     *
     * @return Tree|null
     */
    public function forPath(string $path): ?array
    {
        $root = explode('/', $path)[0];

        $audit = Audit::query()->get(['id', 'folder', 'cabinet_key'])->first(fn (Audit $candidate) => $this->tree->segment($candidate->folder ?? $candidate->cabinet_key) === $root);

        return $audit === null ? null : ($this->build(Audit::query()->whereKey($audit->id))[0] ?? null);
    }

    /**
     * @param  Builder<Audit>  $query
     * @return list<Tree>
     */
    private function build(Builder $query): array
    {
        $audits = $query->with([
            'boxes.photos.file', 'boxes.pdfFile', 'boxes.recheck.recnokFile',
            'controlPoints.photos.file', 'controlPoints.pdfFile',
        ])->get();
        $drawers = AuditDrawerRecheck::query()->with('recnokFile')->whereIn('audit_id', $audits->modelKeys())->whereNotNull('recnok_file_id')->get()->keyBy('audit_id');

        return $audits->map(fn (Audit $audit) => $this->of($audit, $drawers->get($audit->id)))->sortBy('name', SORT_NATURAL)->values()->all();
    }

    /**
     * @return Tree
     */
    public function of(Audit $audit, ?AuditDrawerRecheck $drawer = null): array
    {
        $root = $this->tree->segment($audit->folder ?? $audit->cabinet_key);
        $dirs = [$root];
        $files = [];
        $units = [];

        foreach ($audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved()) as $box) {
            $folder = $this->tree->boxFolder($audit, $box);
            $dirs[] = dirname($folder);
            $dirs[] = $folder;
            $units[] = ['path' => $folder, 'name' => $box->name, 'cabinet' => $audit->cabinet_key, 'group' => basename(dirname($folder)), 'kind' => $box->kind->value];

            foreach ($this->folders->for($box, $audit->effectiveMode(), $box->photos->contains('folder', AuditFolder::Tco->value)) as $expected) {
                $dirs[] = $folder.'/'.$this->tree->segment($expected->label());

                if ($expected === AuditFolder::Pole) {
                    foreach ([AuditPoleSection::Whole, AuditPoleSection::Head, AuditPoleSection::Label, AuditPoleSection::Conduit] as $section) {
                        $dirs[] = $folder.'/'.$this->tree->segment($expected->label()).'/'.$this->tree->segment($section->label());
                    }
                }
            }

            $this->addEntries($files, $dirs, $this->tree->boxEntries($audit, $box));

            if ($box->recheck instanceof AuditBoxRecheck && $box->recheck->recnokFile instanceof StoredFile) {
                $name = $this->tree->segment((string) $box->recheck->recnok_name);
                $at = CarbonImmutable::parse($box->recheck->saved_at ?? $box->recheck->updated_at ?? now());
                $files[] = ['path' => $folder.'/'.$name, 'file' => $box->recheck->recnokFile, 'at' => $at, 'author' => $box->recheck->saved_by_name, 'photo' => false];
                $plans = $root.'/'.$this->tree->segment(__('audit.tree.plans_folder', ['cabinet' => $audit->cabinet_key]));
                $dirs[] = $plans;
                $files[] = ['path' => $plans.'/'.$name, 'file' => $box->recheck->recnokFile, 'at' => $at, 'author' => $box->recheck->saved_by_name, 'photo' => false];
            }
        }

        foreach ($audit->controlPoints as $point) {
            /** @var AuditControlPoint $point */
            $folder = $this->tree->pointFolder($audit, $point);
            $dirs[] = dirname($folder);
            $dirs[] = $folder;
            $units[] = ['path' => $folder, 'name' => $point->name, 'cabinet' => $audit->cabinet_key, 'group' => basename(dirname($folder)), 'kind' => 'controls'];

            foreach ($point->subfolders ?? [] as $subfolder) {
                $dirs[] = $folder.'/'.$this->tree->segment($subfolder);
            }

            $this->addEntries($files, $dirs, $this->tree->pointEntries($audit, $point));
        }

        if ($drawer?->recnokFile instanceof StoredFile) {
            $files[] = ['path' => $root.'/'.$this->tree->segment((string) $drawer->recnok_name), 'file' => $drawer->recnokFile, 'at' => CarbonImmutable::parse($drawer->saved_at ?? $drawer->updated_at ?? now()), 'author' => null, 'photo' => false];
        }

        return ['audit' => $audit, 'name' => $root, 'dirs' => array_values(array_unique($dirs)), 'files' => $files, 'units' => $units];
    }

    /**
     * @param  list<TreeFile>  $files
     * @param  list<string>  $dirs
     * @param  list<array{path: string, file: StoredFile, photo?: AuditPhoto}>  $entries
     */
    private function addEntries(array &$files, array &$dirs, array $entries): void
    {
        foreach ($entries as $entry) {
            $photo = $entry['photo'] ?? null;
            $dirs[] = dirname($entry['path']);
            $files[] = [
                'path' => $entry['path'],
                'file' => $entry['file'],
                'at' => CarbonImmutable::parse($photo->captured_at ?? $entry['file']->updated_at ?? now()),
                'author' => $photo?->user_name,
                'photo' => $photo instanceof AuditPhoto,
            ];
        }
    }
}
