<?php

namespace App\Livewire\MediaLibrary;

use App\Audit\AuditDeliverables;
use App\Enums\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @phpstan-import-type Tree from AuditDeliverables
 * @phpstan-import-type TreeFile from AuditDeliverables
 * @phpstan-import-type Unit from AuditDeliverables
 *
 * « Retours travaux » in the media library: the latest activity per box or control point, or the folders of every
 * audit down to one unit, with selection and ZIP download.
 */
class AuditDeliverablesPage extends Component
{
    public const array PERIODS = ['today', 'yesterday', 'week', 'month', 'all'];

    public const array TYPES = ['all', 'bpe', 'pbo', 'jd', 'controls'];

    private const string PREFERENCES = 'audit_deliverables';

    #[Url(except: '')]
    public string $path = '';

    public string $tab = 'activity';

    public string $period = 'week';

    public string $day = '';

    public string $type = 'all';

    public string $search = '';

    public bool $hideEmpty = false;

    public string $sort = 'recent';

    /** @var list<string> */
    public array $selected = [];

    public function mount(): void
    {
        $this->guard();

        foreach ((array) session(self::PREFERENCES, []) as $key => $value) {
            if (in_array($key, ['tab', 'period', 'type', 'hideEmpty', 'sort'], true)) {
                $this->{$key} = $value;
            }
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'day') {
            $this->period = $this->day === '' ? 'today' : 'day';
        }

        session([self::PREFERENCES => ['tab' => $this->tab, 'period' => $this->period === 'day' ? 'today' : $this->period, 'type' => $this->type, 'hideEmpty' => $this->hideEmpty, 'sort' => $this->sort]]);
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab === 'folders' ? 'folders' : 'activity';
        $this->updated('tab');
    }

    public function choosePeriod(string $period): void
    {
        $this->period = in_array($period, self::PERIODS, true) ? $period : 'week';
        $this->day = '';
        $this->updated('period');
    }

    public function chooseType(string $type): void
    {
        $this->type = in_array($type, self::TYPES, true) ? $type : 'all';
        $this->updated('type');
    }

    public function go(string $path): void
    {
        $this->path = $path;
        $this->selected = [];
    }

    /**
     * @param  list<string>  $paths
     */
    public function selectAll(array $paths, bool $checked): void
    {
        $paths = array_values(array_filter($paths, 'is_string'));
        $this->selected = array_values($checked ? array_unique([...$this->selected, ...$paths]) : array_diff($this->selected, $paths));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function render(AuditDeliverables $deliverables): View
    {
        $this->guard();

        $trees = collect($deliverables->all());
        $tree = $this->path === '' ? null : $trees->firstWhere('name', explode('/', $this->path)[0]);

        if ($this->path !== '' && ($tree === null || ! in_array($this->path, $tree['dirs'], true))) {
            $this->path = '';
            $tree = null;
        }

        $unit = $tree === null ? null : collect($tree['units'])->firstWhere('path', $this->path);
        $now = CarbonImmutable::now(config('platform.timezone'));

        return view('livewire.media-library.audit-deliverables-page', [
            'crumbs' => $this->crumbs(),
            'now' => $now,
            'feed' => collect($this->path === '' && $this->tab === 'activity' ? $this->feed($trees, $now) : []),
            'folders' => collect($unit === null && ($this->path !== '' || $this->tab === 'folders') ? $this->folders($trees, $tree) : []),
            'levelFiles' => $tree !== null && $unit === null ? collect($tree['files'])->filter(fn (array $file) => dirname($file['path']) === $this->path)->values() : collect(),
            'unit' => $unit,
            'unitFiles' => collect($unit !== null ? $this->unitFiles($tree, $unit) : [])->map(fn (array $files) => collect($files)),
        ])->layout('components.layouts.app', ['title' => __('media_library.deliverables.title'), 'page' => 'media-library']);
    }

    /**
     * @return list<array{label: string, path: string|null}>
     */
    private function crumbs(): array
    {
        $crumbs = [];
        $segments = $this->path === '' ? [] : explode('/', $this->path);

        foreach ($segments as $index => $segment) {
            $crumbs[] = ['label' => $segment, 'path' => $index === count($segments) - 1 ? null : implode('/', array_slice($segments, 0, $index + 1))];
        }

        return $crumbs;
    }

    /**
     * One row per box or control point with files, newest first, grouped by day.
     *
     * @param  Collection<int, Tree>  $trees
     * @return array<string, list<array<string, mixed>>>
     */
    private function feed(Collection $trees, CarbonImmutable $now): array
    {
        $search = mb_strtolower(trim($this->search));
        $from = match ($this->period) {
            'today' => $now->startOfDay(),
            'yesterday' => $now->subDay()->startOfDay(),
            'week' => $now->subDays(6)->startOfDay(),
            'month' => $now->subDays(29)->startOfDay(),
            'day' => $this->day !== '' ? CarbonImmutable::parse($this->day, $now->getTimezone())->startOfDay() : $now->startOfDay(),
            default => null,
        };
        $until = match ($this->period) {
            'yesterday' => $now->startOfDay(),
            'day' => $from?->addDay(),
            default => null,
        };

        $rows = $trees->flatMap(fn (array $tree) => collect($tree['units'])->map(function (array $unit) use ($tree): ?array {
            $files = collect($tree['files'])->filter(fn (array $file) => str_starts_with($file['path'], $unit['path'].'/'));

            if ($files->isEmpty()) {
                return null;
            }

            return $unit + [
                'files' => $files->count(),
                'photos' => $files->where('photo', true)->count(),
                'pdfs' => $files->filter(fn (array $file) => dirname($file['path']) === $unit['path'] && $file['file']->extension === 'pdf')->count(),
                'latest' => $this->latest($files->all()),
                'authors' => $files->where('photo', true)->pluck('author')->filter()->countBy()->sortDesc()->keys()->take(6)->values()->all(),
            ];
        })->filter())
            ->filter(fn (array $row) => ($this->type === 'all' || $row['kind'] === $this->type)
                && ($from === null || $row['latest']->gte($from)) && ($until === null || $row['latest']->lt($until))
                && ($search === '' || str_contains(mb_strtolower($row['name'].' '.$row['cabinet']), $search)))
            ->sortByDesc(fn (array $row) => $row['latest']->getTimestamp())
            ->values()
            ->all();

        $days = [];

        foreach ($rows as $row) {
            $days[$row['latest']->setTimezone($now->getTimezone())->toDateString()][] = $row;
        }

        return $days;
    }

    /**
     * Sub-folders of the current folder (the audits at the top) with their photos and latest date.
     *
     * @param  Collection<int, Tree>  $trees
     * @param  Tree|null  $tree
     * @return list<array{path: string, name: string, photos: int, files: int, latest: CarbonImmutable|null}>
     */
    private function folders(Collection $trees, ?array $tree): array
    {
        $candidates = $tree === null
            ? $trees->map(fn (array $item) => ['path' => $item['name'], 'files' => collect($item['files'])])
            : collect($tree['dirs'])->filter(fn (string $dir) => dirname($dir) === $this->path)->map(fn (string $dir) => ['path' => $dir, 'files' => collect($tree['files'])->filter(fn (array $file) => str_starts_with($file['path'], $dir.'/'))]);

        $folders = $candidates->map(fn (array $item) => [
            'path' => $item['path'],
            'name' => basename($item['path']),
            'photos' => $item['files']->where('photo', true)->count(),
            'files' => $item['files']->count(),
            'latest' => $this->latest($item['files']->all()),
        ])->filter(fn (array $item) => ! $this->hideEmpty || $item['files'] > 0);

        return ($this->sort === 'name'
            ? $folders->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            : $folders->sortByDesc(fn (array $item) => $item['latest']?->getTimestamp() ?? 0))->values()->all();
    }

    /**
     * Files of a unit grouped by their sub-folder.
     *
     * @param  Tree  $tree
     * @param  Unit  $unit
     * @return array<string, list<TreeFile>>
     */
    private function unitFiles(array $tree, array $unit): array
    {
        $files = array_values(array_filter($tree['files'], fn (array $file) => str_starts_with($file['path'], $unit['path'].'/')));
        usort($files, fn (array $a, array $b) => [dirname($a['path']), $a['at']->getTimestamp()] <=> [dirname($b['path']), $b['at']->getTimestamp()]);
        $groups = [];

        foreach ($files as $file) {
            $groups[ltrim(substr(dirname($file['path']), strlen($unit['path'])), '/')][] = $file;
        }

        return $groups;
    }

    /**
     * @param  array<int, array{at: CarbonImmutable}>  $files
     */
    private function latest(array $files): ?CarbonImmutable
    {
        $latest = null;

        foreach ($files as $file) {
            if ($latest === null || $file['at']->gt($latest)) {
                $latest = $file['at'];
            }
        }

        return $latest;
    }

    private function guard(): void
    {
        $this->authorize('open-feature', Feature::MediaLibrary);
        $this->authorize('open-feature', Feature::Audits);
    }
}
