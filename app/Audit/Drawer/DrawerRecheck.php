<?php

namespace App\Audit\Drawer;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\Recheck\RecheckPlanException;
use App\Enums\AuditPlanKind;
use App\Models\Audit;
use App\Models\AuditDrawerRecheck;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The recheck of the drawer plan of a cabinet, kept on the server: the positions changed against the plan, the PBO
 * confirmed, and the RECNOK workbook once saved.
 *
 * @phpstan-import-type Cells from DrawerPlan
 * @phpstan-import-type Drawer from DrawerPlan
 * @phpstan-import-type Segment from DrawerPlan
 *
 * @phpstan-type EditSegment array{t: int, L: string, a: int, b: int, cable: string, tubes: array<int|string, int>, fibres: array<int|string, int>, rings?: array<int|string, int>}
 */
class DrawerRecheck
{
    public const string CONFORM = 'conform';

    public const string MODIFIED = 'modified';

    public const string UNCHECKED = 'unchecked';

    public function __construct(private DrawerReader $reader, private DrawerWriter $writer, private StoreUploadedFile $files) {}

    public function planFor(Audit $audit): ?AuditPlan
    {
        return $audit->plans()->with('file')->where('kind', AuditPlanKind::Drawer->value)->get()
            ->first(fn (AuditPlan $plan) => str_ends_with(mb_strtolower($plan->name), '.xlsx'));
    }

    public function record(Audit $audit): AuditDrawerRecheck
    {
        return AuditDrawerRecheck::query()->firstOrCreate(['audit_id' => $audit->id]);
    }

    /**
     * The plan as written, and the plan with what was found on site on top.
     *
     * @return array{drawers: list<Drawer>, original: Cells, current: Cells}
     *
     * @throws RecheckPlanException
     */
    public function state(AuditPlan $plan, AuditDrawerRecheck $record): array
    {
        $file = $plan->file;

        try {
            $parsed = Cache::rememberForever('audit-drawer-plan:'.$file->sha256, fn () => $this->reader->read(Storage::disk($file->disk)->path($file->path)));
        } catch (RecheckPlanException $exception) {
            Log::warning('Drawer plan cannot be shown for the recheck', ['plan' => $plan->id, 'reason' => $exception->getMessage()]);

            throw $exception;
        }

        $current = $parsed['cells'];

        foreach ($record->changes ?? [] as $key => $cell) {
            if (isset($current[$key])) {
                $current[$key] = $cell;
            }
        }

        return ['drawers' => $parsed['drawers'], 'original' => $parsed['cells'], 'current' => $current];
    }

    /**
     * A PBO left unnamed in the plan (« ??? »): it stays to check until the technician renames it.
     */
    public static function isUnnamed(string $name): bool
    {
        return preg_match('/^\?+$/', trim($name)) === 1;
    }

    /**
     * @param  list<Drawer>  $drawers
     * @param  Cells  $original
     * @param  Cells  $current
     * @return array<string, string> Status of each PBO named in the plan.
     */
    public function statuses(array $drawers, array $original, array $current, AuditDrawerRecheck $record): array
    {
        $now = DrawerPlan::byPbo($drawers, $current);
        $was = DrawerPlan::byPbo($drawers, $original);
        $statuses = [];

        foreach (array_filter(array_unique([...array_keys($now), ...array_keys($was)]), fn (string $name) => $name !== '') as $name) {
            $statuses[$name] = self::isUnnamed($name) && isset($now[$name]) ? self::UNCHECKED : (DrawerPlan::signature($now[$name] ?? null) !== DrawerPlan::signature($was[$name] ?? null) ? self::MODIFIED : (in_array($name, $record->confirmed ?? [], true) ? self::CONFORM : self::UNCHECKED));
        }

        natcasesort($statuses);
        uksort($statuses, 'strnatcasecmp');

        return $statuses;
    }

    /**
     * @param  list<string>  $names
     */
    public function confirm(AuditDrawerRecheck $record, array $names): void
    {
        $names = array_filter($names, fn (string $name) => ! self::isUnnamed($name));

        if ($names === []) {
            return;
        }

        $record->update(['confirmed' => array_values(array_unique([...($record->confirmed ?? []), ...$names])), 'has_unsaved_changes' => true]);
    }

    /**
     * Puts every position of a PBO back as in the plan.
     *
     * @param  Cells  $original
     * @param  Cells  $current
     */
    public function revert(AuditDrawerRecheck $record, array $original, array $current, string $pbo): void
    {
        foreach ($current as $key => $cell) {
            if ($cell['pb'] === $pbo || $original[$key]['pb'] === $pbo) {
                $current[$key] = $original[$key];
            }
        }

        $this->store($record, $original, $current, array_values(array_diff($record->confirmed ?? [], [$pbo])));
    }

    /**
     * Applies the edit of a PBO: its positions, cable and tubes. The old positions of the PBO are freed (the physical
     * cable and tube stay).
     *
     * @param  list<Drawer>  $drawers
     * @param  Cells  $original
     * @param  Cells  $current
     * @param  list<EditSegment>  $segments
     * @return array{error: string|null, clash: list<string>}
     */
    public function apply(AuditDrawerRecheck $record, array $drawers, array $original, array $current, string $oldName, string $name, array $segments, bool $replace): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['error' => 'name', 'clash' => []];
        }

        if (self::isUnnamed($name)) {
            return ['error' => 'unnamed', 'clash' => []];
        }

        $taken = [];

        foreach ($segments as $segment) {
            $drawer = collect($drawers)->firstWhere('n', $segment['t']);

            if ($drawer === null || ! in_array($segment['L'], array_column($drawer['mods'], 'L'), true) || $segment['a'] < 1 || $segment['b'] < $segment['a'] || $segment['b'] > $drawer['npos']) {
                return ['error' => 'position', 'clash' => []];
            }

            if ($segment['cable'] === '') {
                return ['error' => 'cable', 'clash' => []];
            }

            for ($slot = DrawerPlan::slotOf($segment['a']); $slot <= DrawerPlan::slotOf($segment['b']); $slot++) {
                if (($segment['tubes'][$slot] ?? 0) < 1) {
                    return ['error' => 'tube', 'clash' => []];
                }
            }

            for ($position = $segment['a']; $position <= $segment['b']; $position++) {
                $key = DrawerPlan::key($segment['t'], $segment['L'], $position);

                if (isset($taken[$key])) {
                    return ['error' => 'overlap', 'clash' => []];
                }

                $taken[$key] = true;
            }
        }

        $clash = [];

        foreach (array_keys($taken) as $key) {
            $owner = $current[$key]['pb'] ?? '';

            if ($owner !== '' && $owner !== $oldName && $owner !== $name) {
                $clash[$owner] = $owner;
            }
        }

        if ($clash !== [] && ! $replace) {
            return ['error' => null, 'clash' => array_values($clash)];
        }

        if ($oldName !== '') {
            foreach ($current as $key => $cell) {
                if ($cell['pb'] === $oldName) {
                    $current[$key]['pb'] = '';
                }
            }
        }

        foreach ($segments as $segment) {
            for ($position = $segment['a']; $position <= $segment['b']; $position++) {
                $slot = DrawerPlan::slotOf($position);
                $start = max($segment['a'], $slot * 12 + 1);
                $current[DrawerPlan::key($segment['t'], $segment['L'], $position)] = [
                    'pb' => $name, 'cable' => $segment['cable'], 'tube' => (int) $segment['tubes'][$slot],
                    'fibre' => (int) (($segment['fibres'][$slot] ?? 0) ?: DrawerPlan::fibreOf($start)) + $position - $start,
                ];
            }
        }

        $confirmed = array_values(array_diff($record->confirmed ?? [], [$oldName, $name]));
        $this->store($record, $original, $current, $confirmed);

        return ['error' => null, 'clash' => []];
    }

    /**
     * Writes the RECNOK workbook (« <cabinet> - Plan de tiroir après travaux.xlsx », replacing the previous one).
     *
     * @throws RecheckPlanException
     */
    public function save(Audit $audit, AuditPlan $plan, AuditDrawerRecheck $record, User $actor): void
    {
        $state = $this->state($plan, $record);
        $contents = $this->writer->write(Storage::disk($plan->file->disk)->path($plan->file->path), $state['drawers'], $state['original'], $state['current'], $record->confirmed ?? []);
        $previous = $record->recnokFile;
        $stored = $this->files->handleContents($contents, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');

        $record->update([
            'recnok_file_id' => $stored->id, 'recnok_name' => mb_substr(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $audit->cabinet_key).' - '.__('audit.drawer.file_suffix').'.xlsx', 0, 160),
            'saved_at' => now(), 'saved_by' => $actor->id, 'saved_by_name' => $actor->name, 'has_unsaved_changes' => false,
        ]);

        if ($previous instanceof StoredFile) {
            $this->files->release($previous);
        }

        $audit->increment('revision');
    }

    /**
     * @param  Cells  $original
     * @param  Cells  $current
     * @param  list<string>  $confirmed
     */
    private function store(AuditDrawerRecheck $record, array $original, array $current, array $confirmed): void
    {
        $changes = [];

        foreach ($current as $key => $cell) {
            if (! DrawerPlan::sameCell($cell, $original[$key] ?? null)) {
                $changes[$key] = $cell;
            }
        }

        $record->update(['changes' => $changes, 'confirmed' => $confirmed, 'has_unsaved_changes' => true]);
    }
}
