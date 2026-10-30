<?php

namespace App\ClientPortal;

use App\DesignOfficeWorkflow\ClientClaims;
use App\Enums\AuditAiDraftStatus;
use App\Enums\AuditClientClaimKind;
use App\Enums\AuditClientClaimState;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditOccurrencePhotoRole;
use App\Models\Audit;
use App\Models\AuditAiDraft;
use App\Models\AuditClientClaim;
use App\Models\AuditDossierVersion;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditReserve;
use App\Models\ClientAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The « Official dossier » tab of the client portal on a design office audit: the published dossier versions (never
 * one being prepared), the reserves accepted on its boxes with their published photos and approved text, and the
 * claims of the client's company. Nothing internal (messages, comments, annotations, drafts, photos of boxes not
 * accepted) is read here.
 *
 * @phpstan-type Reserve array{id: int, box: string, box_id: int, kind: string, reason: string, open: bool, accepted_at: CarbonImmutable, closed_at: CarbonImmutable|null, closing_reason: string, reference: string, text: string, photos: list<array{id: int, role: string}>, can_dispute: bool, disputes: int}
 * @phpstan-type Claim array{id: int, kind: string, box: string, round: int, state: string, body: string, at: CarbonImmutable, decided_at: CarbonImmutable|null, answer: string, reference: string}
 */
class PortalDesignOffice
{
    public function __construct(private PortalPhotoVisibility $visibility) {}

    /**
     * @return Collection<int, AuditDossierVersion>
     */
    public function dossiers(Audit $audit): Collection
    {
        return $audit->dossierVersions()->where('status', AuditDossierStatus::Published)->orderByDesc('version_number')->get();
    }

    /**
     * @return list<Reserve>
     */
    public function reserves(Audit $audit): array
    {
        $reserves = AuditReserve::query()
            ->whereHas('box', fn ($boxes) => $boxes->where('audit_id', $audit->id)->whereNull('removed_at'))
            ->with(['box', 'occurrence.photoLinks', 'occurrence.aiDrafts' => fn ($drafts) => $drafts->where('status', AuditAiDraftStatus::Approved)->latest('approved_at')->latest('id')])
            ->orderBy('accepted_at')->orderBy('id')->get();
        $disputes = AuditClientClaim::query()->whereIn('audit_reserve_id', $reserves->modelKeys())->where('kind', AuditClientClaimKind::ReserveDispute)->get()->groupBy('audit_reserve_id');

        return $reserves->map(function (AuditReserve $reserve) use ($audit, $disputes): array {
            $claims = $disputes->get($reserve->id) ?? new Collection;
            $occurrence = $reserve->occurrence;
            $photos = $occurrence === null || ! $this->visibility->box($audit, $reserve->box) ? [] : $occurrence->photoLinks
                ->whereIn('role', [AuditOccurrencePhotoRole::Before, AuditOccurrencePhotoRole::After])
                ->map(fn (AuditOccurrencePhoto $link) => ['id' => $link->audit_photo_id, 'role' => $link->role->value])->values()->all();
            /** @var AuditAiDraft|null $text */
            $text = $occurrence?->aiDrafts->first();

            return [
                'id' => $reserve->id,
                'box' => $reserve->box->name,
                'box_id' => $reserve->audit_box_id,
                'kind' => $reserve->kind->label(),
                'reason' => $reserve->reason,
                'open' => $reserve->closed_at === null,
                'accepted_at' => CarbonImmutable::parse($reserve->accepted_at),
                'closed_at' => $reserve->closed_at === null ? null : CarbonImmutable::parse($reserve->closed_at),
                'closing_reason' => (string) $reserve->closing_reason,
                'reference' => (string) $occurrence?->reference,
                'text' => (string) $text?->text,
                'photos' => $photos,
                'can_dispute' => $claims->count() < ClientClaims::MAX_DISPUTES
                    && ! $claims->contains(fn (AuditClientClaim $claim) => $claim->state->isOpen() || $claim->state === AuditClientClaimState::Accepted),
                'disputes' => $claims->count(),
            ];
        })->all();
    }

    /**
     * Claims raised on the audit by any account of the same client company, newest first.
     *
     * @return list<Claim>
     */
    public function claims(Audit $audit, ClientAccount $account): array
    {
        return AuditClientClaim::query()
            ->where('audit_id', $audit->id)
            ->whereHas('clientAccount', fn ($accounts) => $accounts->where('client_id', $account->client_id))
            ->with(['box', 'occurrence'])
            ->latest()->latest('id')->get()
            ->map(fn (AuditClientClaim $claim) => [
                'id' => $claim->id,
                'kind' => $claim->kind->value,
                'box' => (string) $claim->box?->name,
                'round' => $claim->round,
                'state' => $claim->state->isOpen() ? 'open' : $claim->state->value,
                'body' => $claim->body,
                'at' => CarbonImmutable::parse($claim->created_at),
                'decided_at' => $claim->decided_at === null ? null : CarbonImmutable::parse($claim->decided_at),
                'answer' => (string) $claim->decision_reason,
                'reference' => (string) $claim->occurrence?->reference,
            ])->all();
    }
}
