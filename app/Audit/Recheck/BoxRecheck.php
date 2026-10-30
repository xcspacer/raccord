<?php

namespace App\Audit\Recheck;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\BoxName;
use App\Audit\Import\KmlReader;
use App\Enums\AuditPlanKind;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxRecheck;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The recheck of a box: finds its plan, reads its lines and keeps what the technician changes as a draft on the
 * server (nothing is lost when the phone changes), until the RECNOK workbook is saved.
 *
 * @phpstan-import-type PlanLine from RecheckPlanReader
 *
 * @phpstan-type Line array{index: int, row: int, cassette: string, plan_word: string, cable_a: string, tube_a: string, fibre_a: string, type: string, fibre_b: string, tube_b: string, cable_b: string, original: array<string, string>, changed: bool}
 */
class BoxRecheck
{
    public const array FIELDS = ['type', 'fibre_b', 'tube_b', 'cable_b', 'cable_a', 'tube_a', 'fibre_a'];

    public function __construct(private RecheckPlanReader $reader, private RecnokWriter $writer, private StoreUploadedFile $files) {}

    /**
     * The plan of a box: an Excel box plan whose file name designates the box.
     *
     * @param  Collection<int, AuditPlan>|null  $plans  The audit's plans, when already loaded.
     */
    public function planFor(AuditBox $box, Audit $audit, ?Collection $plans = null): ?AuditPlan
    {
        $plans ??= $audit->plans()->with('file')->get();

        return $plans->first(fn (AuditPlan $plan) => $plan->kind === AuditPlanKind::Box
            && str_ends_with(mb_strtolower($plan->name), '.xlsx')
            && KmlReader::sameBox(BoxName::fromPlanFile($plan->name, $audit->cabinet_key), $box->name));
    }

    public function record(AuditBox $box): AuditBoxRecheck
    {
        return AuditBoxRecheck::query()->firstOrCreate(['audit_box_id' => $box->id]);
    }

    /**
     * The lines as they are now: the plan, with the draft changes on top.
     *
     * @return list<Line>
     *
     * @throws RecheckPlanException
     */
    public function lines(AuditPlan $plan, AuditBoxRecheck $recheck): array
    {
        $changes = $recheck->draft['changes'] ?? [];
        $lines = [];

        foreach ($this->planLines($plan) as $index => $line) {
            $original = array_intersect_key($line, array_flip(self::FIELDS));
            $current = array_merge($original, array_intersect_key($changes[$index] ?? [], $original));
            $lines[] = ['index' => $index, 'row' => $line['row'], 'cassette' => $line['cassette'], 'plan_word' => $line['plan_word']] + $current + ['original' => $original, 'changed' => $current !== $original];
        }

        return $lines;
    }

    /**
     * Default cassette numbers: the first number of the cassette name on two digits, or the one already written in
     * the plan; then what was typed in the draft.
     *
     * @return array<string, array{default: string, value: string}>
     */
    public function cassetteNumbers(AuditPlan $plan, AuditBoxRecheck $recheck): array
    {
        $numbers = [];

        foreach ($this->planLines($plan) as $line) {
            if (! isset($numbers[$line['cassette']])) {
                $default = preg_match('/^\s*(\d+)/', $line['cassette'], $match) === 1 ? str_pad($match[1], 2, '0', STR_PAD_LEFT) : '';
                $numbers[$line['cassette']] = ['default' => $default, 'value' => $recheck->draft['cassette_numbers'][$line['cassette']] ?? ($line['cassette_number'] ?: $default)];
            }
        }

        return $numbers;
    }

    /**
     * @param  list<int>  $indexes
     * @param  array<string, string>  $patch
     *
     * @throws RecheckPlanException
     */
    public function change(AuditPlan $plan, AuditBoxRecheck $recheck, array $indexes, array $patch): void
    {
        $lines = $this->lines($plan, $recheck);
        $draft = $recheck->draft ?? [];
        $conforming = $recheck->conforming_cassettes ?? [];

        foreach ($indexes as $index) {
            if (! isset($lines[$index])) {
                continue;
            }

            $draft['changes'][$index] = array_merge($draft['changes'][$index] ?? [], array_intersect_key($patch, array_flip(self::FIELDS)));
            $conforming = array_values(array_diff($conforming, [$lines[$index]['cassette']]));
        }

        $recheck->update(['draft' => $draft, 'conforming_cassettes' => $conforming, 'is_conform' => false, 'has_unsaved_changes' => true]);
    }

    /**
     * A cassette is declared conform: its lines go back to the plan.
     */
    public function conformCassette(AuditPlan $plan, AuditBoxRecheck $recheck, string $cassette): void
    {
        $draft = $recheck->draft ?? [];

        foreach ($this->planLines($plan) as $index => $line) {
            if ($line['cassette'] === $cassette) {
                unset($draft['changes'][$index]);
            }
        }

        $recheck->update(['draft' => $draft, 'conforming_cassettes' => array_values(array_unique([...($recheck->conforming_cassettes ?? []), $cassette])), 'has_unsaved_changes' => true]);
    }

    public function setCassetteNumber(AuditBoxRecheck $recheck, string $cassette, string $value): void
    {
        $digits = mb_substr((string) preg_replace('/\D/', '', $value), 0, 3);
        $draft = $recheck->draft ?? [];
        $draft['cassette_numbers'][$cassette] = strlen($digits) === 1 ? '0'.$digits : $digits;
        $recheck->update(['draft' => $draft, 'has_unsaved_changes' => true]);
    }

    public function addManualCable(AuditBoxRecheck $recheck, string $name): string
    {
        $name = mb_substr(trim(str_replace(["\r", "\n"], ' ', $name)), 0, 160);
        $draft = $recheck->draft ?? [];

        if ($name !== '' && ! in_array($name, $draft['manual_cables'] ?? [], true)) {
            $draft['manual_cables'][] = $name;
            $recheck->update(['draft' => $draft]);
        }

        return $name;
    }

    /**
     * @param  list<Line>  $lines
     */
    public function isCassetteConform(AuditBoxRecheck $recheck, array $lines, string $cassette): bool
    {
        return in_array($cassette, $recheck->conforming_cassettes ?? [], true)
            && collect($lines)->where('cassette', $cassette)->every(fn (array $line) => ! $line['changed']);
    }

    /**
     * The box is conform when every cassette is.
     *
     * @param  list<Line>  $lines
     */
    public function isBoxConform(AuditBoxRecheck $recheck, array $lines): bool
    {
        $cassettes = array_values(array_unique(array_column($lines, 'cassette')));

        return $cassettes !== [] && collect($cassettes)->every(fn (string $cassette) => $this->isCassetteConform($recheck, $lines, $cassette));
    }

    /**
     * Cable names written in the plan, as cable A or cable B.
     *
     * @return list<string>
     */
    public function planCables(AuditPlan $plan): array
    {
        return collect($this->planLines($plan))->flatMap(fn (array $line) => [$line['cable_a'], $line['cable_b']])->filter()->unique()->values()->all();
    }

    /**
     * A plan cable « <prefix> <id> 144 Fo - A <origin> O <destination> E » shown as « origin → destination »
     * (only the destination when the origin is the cabinet).
     */
    public static function compactCable(string $name): string
    {
        if (preg_match('/^\S+\s+\S+\s+(\d+)\s*Fo\s*-\s*A\s+(\S+)\s+O\s+(\S+)\s+E\s*(?:\n+([\s\S]*))?$/u', $name, $match) !== 1) {
            return str_replace("\n", ' · ', $name);
        }

        $route = preg_match('/^\d{3}-PM/i', $match[2]) === 1 ? $match[3] : $match[2].' → '.$match[3];

        return $route.(trim($match[4] ?? '') !== '' ? ' · '.trim($match[4]) : '');
    }

    /**
     * @return list<PlanLine>
     *
     * @throws RecheckPlanException
     */
    private function planLines(AuditPlan $plan): array
    {
        $file = $plan->file;

        try {
            return Cache::rememberForever('audit-recheck-lines:'.$file->sha256, fn () => $this->reader->read(Storage::disk($file->disk)->path($file->path), true));
        } catch (RecheckPlanException $exception) {
            Log::warning('Box plan cannot be shown for the recheck', ['plan' => $plan->id, 'reason' => $exception->getMessage()]);

            throw $exception;
        }
    }

    /**
     * Writes the RECNOK workbook of the box (« <BOX>_RECNOK.xlsx », replacing the previous one). A conform box gets a
     * copy of the plan with its cassette numbers and every cassette is declared conform.
     *
     * @throws RecheckPlanException
     */
    public function save(AuditBox $box, AuditPlan $plan, AuditBoxRecheck $recheck, User $actor, bool $conform): void
    {
        $lines = $this->lines($plan, $recheck);
        $numbers = $this->cassetteNumbers($plan, $recheck);
        $contents = $this->writer->write(Storage::disk($plan->file->disk)->path($plan->file->path), $lines, $numbers, $conform);
        $previous = $recheck->recnokFile;
        $stored = $this->files->handleContents($contents, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
        $cassettes = array_keys($this->cassetteNumbers($plan, $recheck));

        $recheck->update([
            'recnok_file_id' => $stored->id, 'recnok_name' => mb_substr(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $box->name).'_RECNOK.xlsx', 0, 160),
            'saved_at' => now(), 'saved_by' => $actor->id, 'saved_by_name' => $actor->name, 'has_unsaved_changes' => false,
            'is_conform' => $conform, 'conforming_cassettes' => $conform ? $cassettes : $recheck->conforming_cassettes,
            'draft' => $conform ? ['cassette_numbers' => $recheck->draft['cassette_numbers'] ?? [], 'manual_cables' => $recheck->draft['manual_cables'] ?? []] : $recheck->draft,
        ]);

        if ($previous instanceof StoredFile) {
            $this->files->release($previous);
        }

        $box->audit()->increment('revision');
    }

    /**
     * « Non conform » / back: the state shown on the box and the paper recheck section.
     */
    public function setNonConforming(AuditBox $box, bool $value): void
    {
        $box->update(['recheck_state' => $value ? 'nonconforming' : null]);
        $box->audit()->increment('revision');
    }
}
