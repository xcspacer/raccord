<?php

namespace App\ClientPortal;

use App\Audit\AuditTree;
use App\Audit\BoxProgress;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The « Boxes & folders » tab of the client portal: one row per box still in the audit, the control points, and the
 * detail drawer of a box or a control point (facts, comments, report, photos by folder).
 *
 * @phpstan-type Row array{id: int, name: string, kind: string, type: string, support: string, support_number: string, status: string, reason: string, percent: int, photos: int, last: CarbonImmutable|null, pdf: bool}
 * @phpstan-type Point array{id: int, name: string, photos: int, pdf: bool}
 * @phpstan-type Group array{name: string, photos: list<array{id: int, at: CarbonImmutable}>}
 * @phpstan-type Detail array{type: string, id: int, name: string, groups: list<Group>, report: list<array{label: string, qty: int}>, pdf: bool, box: AuditBox|null, point: AuditControlPoint|null, status: string, reason: string, percent: int, support: string, hidden: bool}
 *
 * On a design office audit, the photos and PDF of a box the design office has not accepted yet stay hidden
 * (PortalPhotoVisibility); the row and the drawer say so.
 */
class PortalBoxes
{
    public function __construct(private PortalOverview $overview, private BoxProgress $progress, private AuditTree $tree, private PortalPhotoVisibility $visibility) {}

    /**
     * @return list<Row>
     */
    public function rows(Audit $audit): array
    {
        $audit->loadMissing(['boxes.photos:id,audit_box_id,folder,subfolder,captured_at,is_imported', 'boxes.cables']);
        $mode = $audit->effectiveMode();

        return $audit->boxes
            ->reject(fn (AuditBox $box) => $box->isRemoved())
            ->map(fn (AuditBox $box) => [
                'id' => $box->id,
                'name' => $box->name,
                'kind' => $box->kind->value,
                'type' => (string) $box->type,
                'support' => $this->overview->support($box),
                'support_number' => (string) $box->support_number,
                'status' => $this->overview->status($box),
                'reason' => $this->overview->status($box) === 'anomaly' ? (string) $box->reason : '',
                'percent' => $this->progress->percent($box, $mode),
                'photos' => $this->visibility->box($audit, $box) ? $box->photos->count() : 0,
                'last' => $this->lastActivity($box),
                'pdf' => $box->pdf_file_id !== null && $this->visibility->box($audit, $box),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @return list<Point>
     */
    public function points(Audit $audit): array
    {
        $visible = $this->visibility->controlPoints($audit);

        return $audit->controlPoints()->withCount('photos')->orderBy('id')->get()
            ->map(fn (AuditControlPoint $point) => ['id' => $point->id, 'name' => $point->name, 'photos' => $visible ? (int) $point->getAttribute('photos_count') : 0, 'pdf' => $visible && $point->pdf_file_id !== null])
            ->all();
    }

    /**
     * @return Detail|null
     */
    public function detail(Audit $audit, string $type, int $id): ?array
    {
        if ($type === 'box') {
            $box = $audit->boxes()->with(['photos' => fn ($query) => $query->orderBy('captured_at'), 'cables'])->whereKey($id)->first();

            if ($box === null || $box->isRemoved()) {
                return null;
            }

            $visible = $this->visibility->box($audit, $box);

            return [
                'type' => 'box', 'id' => $box->id, 'name' => $box->name,
                'groups' => $visible ? $this->groups($box->photos, fn (AuditPhoto $photo) => str_replace('/', ' / ', $this->tree->photoFolder($photo))) : [],
                'report' => $this->report($box->report_items),
                'pdf' => $visible && $box->pdf_file_id !== null,
                'box' => $box, 'point' => null,
                'status' => $this->overview->status($box),
                'reason' => (string) $box->reason,
                'percent' => $this->progress->percent($box, $audit->effectiveMode()),
                'support' => $this->overview->support($box),
                'hidden' => ! $visible,
            ];
        }

        $point = $audit->controlPoints()->with(['photos' => fn ($query) => $query->orderBy('captured_at')])->whereKey($id)->first();

        if ($point === null) {
            return null;
        }

        $visible = $this->visibility->controlPoints($audit);

        return [
            'type' => 'point', 'id' => $point->id, 'name' => $point->name,
            'groups' => $visible ? $this->groups($point->photos, fn (AuditPhoto $photo) => $photo->subfolder === '' ? $point->name : $point->name.' - '.$photo->subfolder) : [],
            'report' => $this->report($point->report_items),
            'pdf' => $visible && $point->pdf_file_id !== null,
            'box' => null, 'point' => $point,
            'status' => '', 'reason' => '', 'percent' => 0, 'support' => '',
            'hidden' => ! $visible,
        ];
    }

    /**
     * Last photo taken on site, else the time the status was set.
     */
    private function lastActivity(AuditBox $box): ?CarbonImmutable
    {
        $photo = $box->photos->reject(fn (AuditPhoto $photo) => $photo->is_imported)->max('captured_at');
        $at = $photo ?? $box->status_at;

        return $at === null ? null : CarbonImmutable::parse($at);
    }

    /**
     * Photos grouped by the folder people see, in the order folders first appear.
     *
     * @param  Collection<int, AuditPhoto>  $photos
     * @param  callable(AuditPhoto): string  $label
     * @return list<Group>
     */
    private function groups(Collection $photos, callable $label): array
    {
        return $photos
            ->groupBy($label)
            ->map(fn (Collection $group, string $name) => ['name' => $name, 'photos' => $group->map(fn (AuditPhoto $photo) => ['id' => $photo->id, 'at' => CarbonImmutable::parse($photo->captured_at)])->values()->all()])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: string, label: string, qty: int}>|null  $items
     * @return list<array{label: string, qty: int}>
     */
    private function report(?array $items): array
    {
        return array_map(fn (array $item) => ['label' => $item['label'], 'qty' => max(1, (int) $item['qty'])], $items ?? []);
    }
}
