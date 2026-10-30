<?php

namespace App\DesignOfficeWorkflow\Dossier;

use App\Enums\AuditDocumentKind;
use App\Enums\AuditInspectionState;
use App\Enums\AuditPositionSource;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxPosition;
use App\Models\AuditDecision;
use App\Models\AuditDocumentVersion;
use App\Models\AuditMissingProof;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditPhoto;
use App\Models\AuditReserve;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Everything an official dossier is made of, read once from the database: the boxes in service, the photos of the
 * boxes whose inspection the design office accepted (photos not validated never leave), the occurrences with their
 * boxes and photos, the reserves, the missing proofs, the decisions, the current synoptic and the position of each
 * box. Nothing internal (messages, controls, annotations, AI drafts, decision comments) is read.
 *
 * @phpstan-type Position array{latitude: float, longitude: float, source: AuditPositionSource}
 */
final class DossierContents
{
    /**
     * @param  Collection<int, AuditBox>  $boxes
     * @param  Collection<int, AuditPhoto>  $photos
     * @param  Collection<int, AuditOccurrence>  $occurrences
     * @param  Collection<int, AuditReserve>  $reserves
     * @param  Collection<int, AuditMissingProof>  $missingProofs
     * @param  Collection<int, AuditDecision>  $decisions
     * @param  array<int, Position>  $positions  by box id
     * @param  array<int, string>  $names  by user id
     */
    private function __construct(
        public readonly Audit $audit,
        public readonly Collection $boxes,
        public readonly Collection $photos,
        public readonly Collection $occurrences,
        public readonly Collection $reserves,
        public readonly Collection $missingProofs,
        public readonly Collection $decisions,
        public readonly ?AuditDocumentVersion $synoptic,
        public readonly array $positions,
        private readonly array $names,
    ) {}

    public static function of(Audit $audit): self
    {
        $boxes = $audit->boxes()->whereNull('removed_at')->get()
            ->sortBy(fn (AuditBox $box) => $box->name, SORT_NATURAL | SORT_FLAG_CASE)->values();
        $boxIds = $boxes->pluck('id')->all();
        $validated = $boxes->filter(fn (AuditBox $box) => $box->inspection_state === AuditInspectionState::Validated)->pluck('id')->all();

        $photos = AuditPhoto::query()->whereIn('audit_box_id', $validated)->with('file')->orderBy('audit_box_id')->orderBy('folder')->orderBy('subfolder')->orderBy('captured_at')->orderBy('id')->get();

        $occurrences = AuditOccurrence::query()->where('audit_id', $audit->id)
            ->with(['type', 'box', 'boxLinks' => fn ($links) => $links->whereNotNull('confirmed_at')->whereNull('excluded_at')->with('box'), 'photoLinks' => fn ($links) => $links->with('photo.file')->orderBy('audit_photo_id')])
            ->orderBy('sequence')->get();

        $reserves = AuditReserve::query()->whereIn('audit_box_id', $boxIds)->with(['box', 'occurrence'])->orderBy('accepted_at')->orderBy('id')->get();
        $missingProofs = AuditMissingProof::query()->whereIn('audit_box_id', $boxIds)->with('box')->orderBy('id')->get();
        $decisions = AuditDecision::query()->whereHas('request', fn ($requests) => $requests->whereIn('audit_box_id', $boxIds))
            ->with(['type', 'request.box', 'request.submission'])->orderBy('decided_at')->orderBy('id')->get();

        $synoptic = AuditDocumentVersion::query()->where('audit_id', $audit->id)->where('kind', AuditDocumentKind::Synoptic)->where('is_current', true)
            ->with('file')->orderByDesc('version_number')->first();

        $userIds = collect([$audit->closed_by])
            ->merge($occurrences->flatMap(fn (AuditOccurrence $occurrence) => [$occurrence->created_by, $occurrence->corrected_by, $occurrence->validated_by]))
            ->merge($reserves->flatMap(fn (AuditReserve $reserve) => [$reserve->accepted_by, $reserve->closed_by]))
            ->merge($missingProofs->flatMap(fn (AuditMissingProof $proof) => [$proof->justified_by, $proof->decided_by]))
            ->merge($decisions->pluck('decided_by'))
            ->filter()->unique()->values()->all();

        return new self(
            $audit, $boxes, $photos, $occurrences, $reserves, $missingProofs, $decisions, $synoptic, self::positions($boxes),
            User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
        );
    }

    public function name(?int $userId): string
    {
        return $userId === null ? '' : ($this->names[$userId] ?? '');
    }

    /**
     * Names of the boxes an occurrence was confirmed on (its own box first).
     *
     * @return list<string>
     */
    public function occurrenceBoxes(AuditOccurrence $occurrence): array
    {
        $names = $occurrence->boxLinks->sortByDesc(fn (AuditOccurrenceBox $link) => $link->is_origin)->map(fn (AuditOccurrenceBox $link) => $link->box->name)->all();

        return array_values(array_unique($names === [] ? [$occurrence->box->name] : $names));
    }

    /**
     * Where each box is: its official position (approved by the design office), else the position confirmed on site,
     * else the position of the imported map.
     *
     * @param  Collection<int, AuditBox>  $boxes
     * @return array<int, Position>
     */
    private static function positions(Collection $boxes): array
    {
        $recorded = AuditBoxPosition::query()->whereIn('audit_box_id', $boxes->pluck('id'))
            ->whereIn('source', [AuditPositionSource::Official, AuditPositionSource::MapOrigin])
            ->orderBy('id')->get()->groupBy('audit_box_id');
        $positions = [];

        foreach ($boxes as $box) {
            /** @var Collection<int, AuditBoxPosition> $own */
            $own = $recorded->get($box->id, collect());
            $official = $own->where('source', AuditPositionSource::Official)->last();
            $origin = $own->where('source', AuditPositionSource::MapOrigin)->last();

            $position = match (true) {
                $official !== null => [(float) $official->latitude, (float) $official->longitude, AuditPositionSource::Official],
                $box->confirmed_at !== null && $box->confirmed_latitude !== null => [(float) $box->confirmed_latitude, (float) $box->confirmed_longitude, AuditPositionSource::Field],
                $origin !== null => [(float) $origin->latitude, (float) $origin->longitude, AuditPositionSource::MapOrigin],
                $box->latitude !== null && $box->longitude !== null => [(float) $box->latitude, (float) $box->longitude, AuditPositionSource::MapOrigin],
                default => null,
            };

            if ($position !== null) {
                $positions[$box->id] = ['latitude' => $position[0], 'longitude' => $position[1], 'source' => $position[2]];
            }
        }

        return $positions;
    }
}
