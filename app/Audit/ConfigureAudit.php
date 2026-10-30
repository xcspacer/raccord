<?php

namespace App\Audit;

use App\Actions\Files\StoreUploadedFile;
use App\Audit\Import\KmlReader;
use App\DesignOfficeWorkflow\DocumentVersions;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditPlanKind;
use App\Enums\AuditSupport;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPlan;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prepares a cabinet audit from the documents of the client: the boxes read from the synoptic, the positions of the
 * map file, the plans. A box found on both the plans and the map but absent from the synoptic is added; one found
 * in a single source is only reported to be checked. Everything is saved at once, for managers only.
 *
 * @phpstan-type Parsed array{name: string, kind: AuditBoxKind, support: AuditSupport|null, support_number: string, type: string, address: string}
 * @phpstan-type Map array{boxes: array<string, array{float, float}>, cabinet: array{latitude: float, longitude: float, name: string}|null}
 * @phpstan-type Result array{added: int, updated: int, located: int, to_verify: list<string>, plans_linked: int, plans_unmatched: list<string>}
 */
class ConfigureAudit
{
    public function __construct(private AuditOperations $operations, private StoreUploadedFile $files) {}

    /**
     * @param  array{sro?: string|null, mode?: string|null, workflow?: string|null}  $settings
     * @param  list<Parsed>  $listed  Boxes of the synoptic (read from its text or pasted).
     * @param  Map|null  $map
     * @param  array{synoptic?: UploadedFile|null, map?: UploadedFile|null, drawer?: UploadedFile|null, boxes?: list<UploadedFile>}  $uploads
     * @return Result
     *
     * @throws AuditOperationException
     */
    public function handle(User $actor, Audit $audit, array $settings, array $listed, ?array $map, array $uploads): array
    {
        $this->operations->apply($actor, $audit, 'o_'.bin2hex(random_bytes(6)), array_filter([
            'op' => 'setup', 'sro' => $settings['sro'] ?? null, 'mode' => $settings['mode'] ?? null, 'workflow' => $settings['workflow'] ?? null,
            'cabinet_position' => $map['cabinet'] ?? null,
        ], fn ($value) => $value !== null));

        return DB::transaction(function () use ($actor, $audit, $listed, $map, $uploads): array {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $planFiles = $uploads['boxes'] ?? [];

            ['added' => $added, 'updated' => $updated] = $this->saveListed($actor, $audit, $listed);
            $crossed = $this->crossCheck($actor, $audit, $planFiles, $map);
            $located = $this->locate($audit, $map);
            $this->storeFiles($actor, $audit, $uploads, $map);
            $plans = $this->matchPlans($audit);

            $audit->increment('revision');

            return [
                'added' => $added + $crossed['added'], 'updated' => $updated, 'located' => $located, 'to_verify' => $crossed['to_verify'],
                'plans_linked' => $plans['linked'], 'plans_unmatched' => $plans['unmatched'],
            ];
        });
    }

    /**
     * @param  list<Parsed>  $listed
     * @return array{added: int, updated: int}
     */
    private function saveListed(User $actor, Audit $audit, array $listed): array
    {
        $added = 0;
        $updated = 0;

        foreach ($listed as $item) {
            $name = BoxName::normalise($item['name']);
            $box = $audit->boxes()->where('name', $name)->first();

            if ($box === null) {
                AuditBox::query()->create([
                    'audit_id' => $audit->id, 'name' => $name, 'kind' => $item['kind'], 'support' => $item['support'], 'support_number' => $item['support'] === null ? null : ($item['support_number'] ?: null),
                    'type' => mb_substr($item['type'], 0, 120) ?: null, 'address' => mb_substr($item['address'], 0, 200) ?: null, 'source' => AuditBoxSource::Synoptic, 'created_by' => $actor->id,
                ]);
                $added++;

                continue;
            }

            $box->type = mb_substr($item['type'], 0, 120) ?: $box->type;
            $box->address = mb_substr($item['address'], 0, 200) ?: $box->address;

            if ($box->support === null && $item['support'] !== null) {
                $box->support = $item['support'];
                $box->support_number = $item['support_number'] ?: null;
            }

            $box->removed_at = null;
            $box->removed_by = null;
            $box->save();
            $updated++;
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * @param  list<UploadedFile>  $planFiles
     * @param  Map|null  $map
     * @return array{added: int, to_verify: list<string>}
     */
    private function crossCheck(User $actor, Audit $audit, array $planFiles, ?array $map): array
    {
        $known = $audit->boxes()->pluck('name')->all();
        $fromPlans = collect($planFiles)->map(fn (UploadedFile $file) => BoxName::fromPlanFile($file->getClientOriginalName(), $audit->cabinet_key))->filter(fn (string $name) => BoxName::classify($name) !== null)->unique()->values();
        $fromMap = collect(array_keys($map['boxes'] ?? []))->filter(fn (string $name) => BoxName::classify(mb_strtoupper($name)) !== null)->map(fn (string $name) => mb_strtoupper($name))->unique()->values();
        $isKnown = fn (string $name) => collect($known)->contains(fn (string $other) => KmlReader::sameBox($name, $other));
        $onBoth = fn (string $name, $others) => $others->contains(fn (string $other) => KmlReader::sameBox($name, $other));
        $added = 0;
        $toVerify = [];

        foreach ($fromPlans->reject($isKnown) as $name) {
            if ($onBoth($name, $fromMap)) {
                AuditBox::query()->create(['audit_id' => $audit->id, 'name' => $name, 'kind' => BoxName::classify($name) ?? AuditBoxKind::Pbo, 'source' => AuditBoxSource::CrossReference, 'created_by' => $actor->id]);
                $known[] = $name;
                $added++;
            } else {
                $toVerify[] = $name;
            }
        }

        foreach ($fromMap->reject($isKnown) as $name) {
            if (! $onBoth($name, $fromPlans) && ! collect($known)->contains(fn (string $other) => KmlReader::sameBox($name, $other))) {
                $toVerify[] = $name;
            }
        }

        return ['added' => $added, 'to_verify' => array_values(array_unique($toVerify))];
    }

    /**
     * @param  Map|null  $map
     */
    private function locate(Audit $audit, ?array $map): int
    {
        $located = 0;

        foreach ($map['boxes'] ?? [] as $name => [$latitude, $longitude]) {
            if (abs($latitude) > 90 || abs($longitude) > 180 || (abs($latitude) < 1e-9 && abs($longitude) < 1e-9)) {
                continue;
            }

            $box = $audit->boxes()->get()->first(fn (AuditBox $candidate) => KmlReader::sameBox($candidate->name, (string) $name));

            if ($box !== null) {
                $box->update(['latitude' => number_format($latitude, 7, '.', ''), 'longitude' => number_format($longitude, 7, '.', '')]);
                $located++;
            }
        }

        return $located;
    }

    /**
     * In the design office workflow, the synoptic and the map file also become a new version of the PM's documents.
     *
     * @param  array{synoptic?: UploadedFile|null, map?: UploadedFile|null, drawer?: UploadedFile|null, boxes?: list<UploadedFile>}  $uploads
     * @param  Map|null  $map
     */
    private function storeFiles(User $actor, Audit $audit, array $uploads, ?array $map): void
    {
        $versions = $audit->usesDesignOfficeWorkflow() ? app(DocumentVersions::class) : null;

        if (($uploads['synoptic'] ?? null) !== null) {
            $this->release($audit->synoptic_file_id);
            $stored = $this->files->handle($uploads['synoptic']);
            $audit->update(['synoptic_file_id' => $stored->id, 'synoptic_name' => $this->name($uploads['synoptic'])]);
            $versions?->publishStored($actor, $audit, AuditDocumentKind::Synoptic, $stored, $this->name($uploads['synoptic']));
        }

        if (($uploads['map'] ?? null) !== null) {
            $this->release($audit->kmz_file_id);
            $stored = $this->files->handle($uploads['map']);
            $audit->update(['kmz_file_id' => $stored->id, 'kmz_name' => $this->name($uploads['map'])]);
            $versions?->publishStored($actor, $audit, AuditDocumentKind::Map, $stored, $this->name($uploads['map']), $map['boxes'] ?? []);
        }

        if (($uploads['drawer'] ?? null) !== null) {
            $this->replacePlans($audit, AuditPlanKind::Drawer, [$uploads['drawer']], $actor);
        }

        if (($uploads['boxes'] ?? []) !== []) {
            $this->replacePlans($audit, AuditPlanKind::Box, $uploads['boxes'], $actor);
        }
    }

    /**
     * New plans are added; a plan with the same name as an existing one (letter case aside) replaces it. There is
     * only one drawer plan.
     *
     * @param  list<UploadedFile>  $files
     */
    private function replacePlans(Audit $audit, AuditPlanKind $kind, array $files, User $actor): void
    {
        foreach ($files as $file) {
            $name = $this->name($file);

            $audit->plans()->where('kind', $kind->value)->when($kind === AuditPlanKind::Box, fn ($query) => $query->whereRaw('lower(name) = ?', [mb_strtolower($name)]))
                ->get()->each(function (AuditPlan $plan): void {
                    $plan->delete();
                    $this->release($plan->stored_file_id);
                });

            AuditPlan::query()->create(['audit_id' => $audit->id, 'kind' => $kind, 'name' => $name, 'stored_file_id' => $this->files->handle($file)->id, 'uploaded_by' => $actor->id]);
        }
    }

    /**
     * Each box plan should belong to a box still in the audit.
     *
     * @return array{linked: int, unmatched: list<string>}
     */
    private function matchPlans(Audit $audit): array
    {
        $boxes = $audit->boxes()->whereNull('removed_at')->pluck('name');
        $linked = 0;
        $unmatched = [];

        foreach ($audit->plans()->where('kind', AuditPlanKind::Box->value)->get() as $plan) {
            $name = BoxName::fromPlanFile($plan->name, $audit->cabinet_key);

            if ($boxes->contains(fn (string $box) => KmlReader::sameBox($name, $box))) {
                $linked++;
            } else {
                $unmatched[] = $plan->name;
            }
        }

        return ['linked' => $linked, 'unmatched' => $unmatched];
    }

    private function name(UploadedFile $file): string
    {
        return mb_substr(Str::of($file->getClientOriginalName())->replaceMatches('/[\\\\\/\x00-\x1f]/', '')->toString(), 0, 120);
    }

    private function release(?int $storedFileId): void
    {
        $file = $storedFileId === null ? null : StoredFile::query()->find($storedFileId);

        if ($file !== null) {
            $this->files->release($file);
        }
    }
}
