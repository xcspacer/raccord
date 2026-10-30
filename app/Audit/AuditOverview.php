<?php

namespace App\Audit;

use App\Enums\AuditMode;
use App\Enums\ProjectType;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The list of cabinets to audit that a person sees: the cabinets planned as audit work sites and those already
 * opened, with their progress. A technician only sees the cabinets they are planned on.
 *
 * @phpstan-type Card array{key: string, reference: string, audit: Audit|null, mode: AuditMode|null, state: string, city: string|null, starts_on: CarbonInterface|null, ends_on: CarbonInterface|null, sro: string|null, percent: int, boxes_done: int, boxes_total: int}
 */
class AuditOverview
{
    public function __construct(private BoxProgress $progress) {}

    /**
     * @return list<Card>
     */
    public function for(User $user, ?AuditMode $mode, CarbonInterface $today): array
    {
        $manager = $user->can('manage', Audit::class);
        $sites = WorkSite::query()->where('project_type', ProjectType::Audit->value)->whereNotNull('cabinet_ref')
            ->when(! $manager, fn ($query) => $query->where(fn ($inner) => $inner->where('lead_technician_id', $user->id)->orWhereHas('members', fn ($members) => $members->whereKey($user->id))))
            ->get(['id', 'cabinet_ref', 'city', 'starts_on', 'ends_on', 'audit_mode'])
            ->groupBy(fn (WorkSite $site) => BoxName::cabinetKey((string) $site->cabinet_ref));

        $audits = Audit::query()->with(['boxes.photos:id,audit_box_id,folder,subfolder', 'boxes.cables'])->get()->keyBy('cabinet_key');
        $keys = $sites->keys()->when($manager, fn (Collection $keys) => $keys->merge($audits->keys()))->unique()->filter()->values();
        $cards = [];

        foreach ($keys as $key) {
            $audit = $audits[$key] ?? null;
            $group = $sites[$key] ?? collect();
            $cabinetMode = ($audit instanceof Audit ? $audit->mode : null) ?? $this->siteMode($group->pluck('audit_mode')->filter()->first());
            $first = $group->first();

            if ($mode !== null && ($cabinetMode ?? AuditMode::Full) !== $mode) {
                continue;
            }

            $boxes = $audit instanceof Audit ? $audit->boxes->reject(fn (AuditBox $box) => $box->isRemoved()) : collect();

            $cards[] = [
                'key' => $key,
                'reference' => $first instanceof WorkSite ? (string) $first->cabinet_ref : $key,
                'audit' => $audit instanceof Audit ? $audit : null,
                'mode' => $cabinetMode,
                'state' => $this->state($audit instanceof Audit ? $audit : null),
                'city' => $group->pluck('city')->filter()->first(),
                'starts_on' => $this->date($group->min('starts_on')),
                'ends_on' => $this->date($group->max('ends_on')),
                'sro' => $audit?->sro,
                'percent' => $audit instanceof Audit ? $this->progress->auditPercent($audit) : 0,
                'boxes_done' => $boxes->filter(fn (AuditBox $box) => $box->isFinal())->count(),
                'boxes_total' => $boxes->count(),
            ];
        }

        usort($cards, fn (array $a, array $b) => [$this->isPast($a, $today), $this->timestamp($a['starts_on'])] <=> [$this->isPast($b, $today), $this->timestamp($b['starts_on'])] ?: strnatcasecmp($a['key'], $b['key']));

        return $cards;
    }

    /**
     * Where an audit stands: validated, finished and waiting for validation, in progress (opened: the SRO is
     * optional) or not configured yet (never opened).
     */
    public function state(?Audit $audit): string
    {
        return match (true) {
            $audit === null => 'not_configured',
            $audit->isValidated() => 'validated',
            $audit->isFinished() => 'to_validate',
            default => 'in_progress',
        };
    }

    private function siteMode(mixed $value): ?AuditMode
    {
        return $value instanceof AuditMode ? $value : (is_string($value) ? AuditMode::tryFrom($value) : null);
    }

    private function date(mixed $value): ?CarbonInterface
    {
        return $value instanceof CarbonInterface ? $value : null;
    }

    private function timestamp(?CarbonInterface $date): int
    {
        return $date === null ? PHP_INT_MAX : $date->getTimestamp();
    }

    /**
     * @param  Card  $card
     */
    private function isPast(array $card, CarbonInterface $today): bool
    {
        return $card['ends_on'] !== null && $card['ends_on']->lt($today);
    }
}
