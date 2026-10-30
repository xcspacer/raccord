<?php

namespace App\Livewire\ClientPortal;

use App\ClientPortal\ClientRemarks;
use App\ClientPortal\PortalBoxes;
use App\ClientPortal\PortalCharts;
use App\ClientPortal\PortalDesignOffice;
use App\ClientPortal\PortalOverview;
use App\ClientPortal\PortalSession;
use App\DesignOfficeWorkflow\ClientClaims;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditClientClaimKind;
use App\Enums\AuditDossierStatus;
use App\Enums\ClientRemarkSide;
use App\Enums\ClientRemarkTarget;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditClientClaim;
use App\Models\AuditDossierVersion;
use App\Models\AuditPresence;
use App\Models\AuditReserve;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientRemark;
use App\Models\ClientRemarkReply;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * @phpstan-import-type Overview from PortalOverview
 * @phpstan-import-type Row from PortalBoxes
 *
 * The client portal of a client company: pick an audit (PM) and follow it live, its boxes and their folders. On a
 * design office audit, the « Official dossier » tab lists the published dossier versions, the reserves (which the
 * client may dispute) and, once the audit is closed, lets the client declare a new occurrence.
 */
class PortalPage extends Component
{
    private const string CABINET_KEY = 'client_portal_cabinet';

    /**
     * The page polls every 15 s: the overview of an account is worked out at most once in that time (PORT-10), unless
     * an audit or a crew presence changed meanwhile.
     */
    public const int OVERVIEW_CACHE_SECONDS = 15;

    public const array TABS = ['dashboard', 'boxes', 'notes', 'dossier'];

    /** Tabs shown only on an audit of the design office workflow. */
    public const array DESIGN_OFFICE_TABS = ['dossier'];

    public const array SORTS = ['name', 'kind', 'support', 'status', 'percent', 'photos', 'last'];

    #[Locked]
    public int $clientId;

    public string $cabinet = '';

    public string $tab = 'dashboard';

    public string $search = '';

    public string $kind = 'all';

    public string $support = 'all';

    public string $status = 'all';

    public string $sort = 'name';

    public int $direction = 1;

    /** @var list<int|string> */
    public array $selected = [];

    /** « box:<id> » or « point:<id> » of the open drawer. */
    public string $open = '';

    public string $generalNote = '';

    public string $drawerNote = '';

    /** Box of the quick note dialog. */
    #[Locked]
    public ?int $noteBox = null;

    public string $noteText = '';

    #[Locked]
    public ?int $replyingTo = null;

    public string $replyText = '';

    /** Reserve being disputed. */
    #[Locked]
    public ?int $disputeFor = null;

    public string $disputeText = '';

    public string $occurrenceBox = '';

    public string $occurrenceText = '';

    /** When the notifications were last seen before the bell was opened, so the open menu still shows what was new. */
    #[Locked]
    public ?string $seenBefore = null;

    /** @var list<string>|null notification events already shown, to toast only the new ones */
    #[Locked]
    public ?array $knownEvents = null;

    public function mount(Client $portalClient): void
    {
        $this->clientId = $portalClient->id;
        $this->cabinet = (string) session(self::CABINET_KEY, '');
    }

    public function updatedCabinet(): void
    {
        session([self::CABINET_KEY => $this->cabinet]);
        $this->reset('search', 'kind', 'support', 'status', 'sort', 'direction', 'selected', 'open');
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'dashboard';
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTS, true)) {
            return;
        }

        $this->direction = $this->sort === $column ? -$this->direction : 1;
        $this->sort = $column;
    }

    public function openBox(int $id): void
    {
        $this->open = 'box:'.$id;
    }

    public function openPoint(int $id): void
    {
        $this->open = 'point:'.$id;
    }

    public function closeDrawer(): void
    {
        $this->open = '';
        $this->reset('drawerNote', 'replyingTo', 'replyText');
    }

    public function sendGeneralNote(PortalSession $portal, ClientRemarks $remarks): void
    {
        $this->resetValidation();
        $account = $this->account($portal);
        $remarks->create($account, $this->currentAudit($account), ClientRemarkTarget::Audit, null, $this->generalNote, 'generalNote');

        $this->reset('generalNote');
        $this->dispatch('toast', message: __('client_portal.notes.sent', ['company' => app(CompanySettings::class)->displayName()]));
    }

    public function sendDrawerNote(PortalSession $portal, ClientRemarks $remarks): void
    {
        $this->resetValidation();
        $account = $this->account($portal);
        [$type, $id] = array_pad(explode(':', $this->open), 2, '0');
        $target = $type === 'point' ? ClientRemarkTarget::ControlPoint : ClientRemarkTarget::Box;
        $remarks->create($account, $this->currentAudit($account), $target, (int) $id, $this->drawerNote, 'drawerNote');

        $this->reset('drawerNote');
        $this->dispatch('toast', message: __('client_portal.notes.sent', ['company' => app(CompanySettings::class)->displayName()]));
    }

    public function openNoteDialog(int $boxId): void
    {
        $this->noteBox = $boxId;
        $this->reset('noteText');
        $this->resetValidation('noteText');
    }

    public function closeNoteDialog(): void
    {
        $this->reset('noteBox', 'noteText');
    }

    public function sendDialogNote(PortalSession $portal, ClientRemarks $remarks): void
    {
        $this->resetValidation();
        $account = $this->account($portal);
        $remarks->create($account, $this->currentAudit($account), ClientRemarkTarget::Box, $this->noteBox, $this->noteText, 'noteText');

        $this->reset('noteBox', 'noteText');
        $this->dispatch('toast', message: __('client_portal.notes.sent', ['company' => app(CompanySettings::class)->displayName()]));
    }

    public function startReply(int $remarkId): void
    {
        $this->replyingTo = $this->replyingTo === $remarkId ? null : $remarkId;
        $this->reset('replyText');
    }

    public function sendReply(PortalSession $portal, ClientRemarks $remarks): void
    {
        $this->resetValidation();
        $remarks->clientReply($this->account($portal), ClientRemark::query()->findOrFail($this->replyingTo), $this->replyText, 'replyText');

        $this->reset('replyingTo', 'replyText');
        $this->dispatch('toast', message: __('client_portal.notes.updated'));
    }

    public function closeRemark(int $remarkId, PortalSession $portal, ClientRemarks $remarks): void
    {
        $remarks->clientClose($this->account($portal), ClientRemark::query()->findOrFail($remarkId));
        $this->dispatch('toast', message: __('client_portal.notes.updated'));
    }

    public function reopenRemark(int $remarkId, PortalSession $portal, ClientRemarks $remarks): void
    {
        $remarks->clientReopen($this->account($portal), ClientRemark::query()->findOrFail($remarkId));
        $this->dispatch('toast', message: __('client_portal.notes.updated'));
    }

    public function startDispute(int $reserveId): void
    {
        $this->disputeFor = $this->disputeFor === $reserveId ? null : $reserveId;
        $this->reset('disputeText');
        $this->resetValidation('disputeText');
    }

    public function sendDispute(PortalSession $portal, ClientClaims $claims): void
    {
        $this->resetValidation();
        $account = $this->account($portal);
        $audit = $this->currentAudit($account);
        $reserve = AuditReserve::query()->whereKey($this->disputeFor)->whereHas('box', fn ($boxes) => $boxes->where('audit_id', $audit->id)->whereNull('removed_at'))->firstOrFail();
        $this->validate(['disputeText' => ['required', 'string', 'min:10', 'max:2000']]);

        try {
            $claims->dispute($account, $reserve, $this->disputeText);
        } catch (WorkflowException $exception) {
            $this->addError('disputeText', $exception->translated());

            return;
        }

        $this->reset('disputeFor', 'disputeText');
        $this->dispatch('toast', message: __('client_portal.design_office.dispute_sent'));
    }

    public function declareOccurrence(PortalSession $portal, ClientClaims $claims): void
    {
        $this->resetValidation();
        $account = $this->account($portal);
        $audit = $this->currentAudit($account);
        $this->validate([
            'occurrenceBox' => ['required', 'integer'],
            'occurrenceText' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $box = AuditBox::query()->where('audit_id', $audit->id)->whereNull('removed_at')->findOrFail((int) $this->occurrenceBox);

        try {
            $claims->declareOccurrence($account, $box, $this->occurrenceText);
        } catch (WorkflowException $exception) {
            $this->addError('occurrenceText', $exception->translated());

            return;
        }

        $this->reset('occurrenceBox', 'occurrenceText');
        $this->dispatch('toast', message: __('client_portal.design_office.occurrence_sent', ['company' => app(CompanySettings::class)->displayName()]));
    }

    public function markNotificationsSeen(PortalSession $portal): void
    {
        $account = $this->account($portal);
        $this->seenBefore = $account->notifications_seen_at?->toIso8601String() ?? '1970-01-01T00:00:00Z';
        $account->forceFill(['notifications_seen_at' => now()])->save();
    }

    /**
     * @param  list<int>  $ids
     */
    public function selectAll(array $ids, bool $checked): void
    {
        $ids = array_map('intval', $ids);
        $current = array_map('intval', $this->selected);
        $this->selected = array_values($checked ? array_unique([...$current, ...$ids]) : array_diff($current, $ids));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'disputeText' => __('client_portal.design_office.dispute_attribute'),
            'occurrenceBox' => __('client_portal.design_office.box_attribute'),
            'occurrenceText' => __('client_portal.design_office.occurrence_attribute'),
        ];
    }

    public function render(PortalSession $portal, PortalOverview $overview, PortalBoxes $boxes, PortalCharts $charts, PortalDesignOffice $designOffice): View
    {
        $client = Client::query()->findOrFail($this->clientId);
        $account = $portal->account($this->clientId);
        $now = CarbonImmutable::now();

        if ($account === null || $account->must_change_password) {
            $this->redirectRoute($account === null ? 'portal.login' : 'portal.password', $client->portalSlug());

            return view('livewire.client-portal.portal-page', ['client' => $client, 'account' => null, 'overviews' => collect(), 'current' => null, 'charts' => $charts, 'now' => $now]);
        }

        $overviews = $this->overviews($account, $overview);
        $current = $overviews->firstWhere('cabinet', $this->cabinet) ?? $overviews->first();
        $this->cabinet = $current['cabinet'] ?? '';
        $audit = $current['audit'] ?? null;
        $designOfficeAudit = $audit instanceof Audit && $audit->usesDesignOfficeWorkflow();

        if (in_array($this->tab, self::DESIGN_OFFICE_TABS, true) && ! $designOfficeAudit) {
            $this->tab = 'dashboard';
        }

        $dossierTab = $designOfficeAudit && $this->tab === 'dossier';
        $rows = $audit instanceof Audit && $this->tab === 'boxes' ? $boxes->rows($audit) : [];
        [$type, $id] = array_pad(explode(':', $this->open), 2, '0');
        $remarks = $audit instanceof Audit ? $audit->clientRemarks()->with('replies')->latest()->latest('id')->get() : new EloquentCollection;
        $openRemarks = $remarks->reject(fn (ClientRemark $remark) => $remark->isClosed());
        $events = $this->events($account);
        $this->toastNewEvents($events);

        return view('livewire.client-portal.portal-page', [
            'client' => $client,
            'account' => $account,
            'overviews' => $overviews,
            'current' => $current,
            'charts' => $charts,
            'now' => $now,
            'rows' => $rows,
            'filtered' => $this->filter($rows),
            'points' => $audit instanceof Audit && $this->tab === 'boxes' ? $boxes->points($audit) : [],
            'detail' => $audit instanceof Audit && $this->open !== '' ? $boxes->detail($audit, $type, (int) $id) : null,
            'remarks' => $remarks,
            'openRemarks' => $openRemarks->count(),
            'openByBox' => $openRemarks->whereNotNull('audit_box_id')->countBy('audit_box_id')->all(),
            'openByPoint' => $openRemarks->whereNotNull('audit_control_point_id')->countBy('audit_control_point_id')->all(),
            'events' => array_slice($events, 0, 12),
            'unseen' => count(array_filter($events, fn (array $event) => $account->notifications_seen_at === null || $event['at']->gt($account->notifications_seen_at))),
            'noteBoxName' => $this->noteBox !== null && $audit instanceof Audit ? $audit->boxes()->whereKey($this->noteBox)->value('name') : null,
            'company' => app(CompanySettings::class)->displayName(),
            'designOfficeAudit' => $designOfficeAudit,
            'dossiers' => $dossierTab ? $designOffice->dossiers($audit) : new EloquentCollection,
            'reserves' => $dossierTab ? $designOffice->reserves($audit) : [],
            'claims' => $dossierTab ? $designOffice->claims($audit, $account) : [],
            'claimBoxes' => $dossierTab && $audit->isClosed() ? $audit->boxes()->whereNull('removed_at')->orderBy('name')->pluck('name', 'id')->all() : [],
        ])->layout('components.layouts.portal', ['title' => __('client_portal.title')]);
    }

    private function account(PortalSession $portal): ClientAccount
    {
        $account = $portal->account($this->clientId);
        abort_if($account === null, 401);
        abort_if($account->must_change_password, 403);

        return $account;
    }

    private function currentAudit(ClientAccount $account): Audit
    {
        $audits = $account->visibleAudits()->get()->sortBy('cabinet_key', SORT_NATURAL)->values();

        return $audits->firstWhere('cabinet_key', $this->cabinet) ?? $audits->first() ?? abort(404);
    }

    /**
     * What the client is told about its notes, newest first: a technician took one into account, the company answered.
     *
     * @return list<array{id: string, at: CarbonImmutable, text: string, type: string, target: int|null}>
     */
    private function events(ClientAccount $account): array
    {
        $company = app(CompanySettings::class)->displayName();
        $events = [];
        $remarks = ClientRemark::query()->with('replies')->whereIn('audit_id', $account->visibleAudits()->select('id'))->get();

        foreach ($remarks as $remark) {
            $label = match ($remark->target) {
                ClientRemarkTarget::Audit => __('client_portal.notes.general_label'),
                ClientRemarkTarget::ControlPoint => $remark->target_label,
                ClientRemarkTarget::Box => __('client_portal.notes.box_label', ['box' => $remark->target_label]),
            };
            $type = $remark->target === ClientRemarkTarget::ControlPoint ? 'point' : 'box';
            $target = $remark->audit_box_id ?? $remark->audit_control_point_id;

            if ($remark->acknowledged_at !== null) {
                $name = PortalOverview::firstName($remark->acknowledged_by_name);
                $events[] = ['id' => 'ack:'.$remark->id.':'.$remark->acknowledged_at->getTimestamp(), 'at' => CarbonImmutable::parse($remark->acknowledged_at), 'text' => '✓ '.__('client_portal.notes.event_ack', ['name' => $name !== '' ? $name : __('client_portal.notes.a_technician'), 'label' => $label]), 'type' => $type, 'target' => $target];
            }

            foreach ($remark->replies->where('author_side', ClientRemarkSide::Staff) as $reply) {
                /** @var ClientRemarkReply $reply */
                $events[] = ['id' => 'reply:'.$reply->id, 'at' => CarbonImmutable::parse($reply->created_at), 'text' => '💬 '.__('client_portal.notes.event_reply', ['company' => $company, 'label' => $label]), 'type' => $type, 'target' => $target];
            }
        }

        array_push($events, ...$this->designOfficeEvents($account));
        usort($events, fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return $events;
    }

    /**
     * Design office audits: a new version of the official dossier was published (told here only, never by e-mail), and
     * the office answered a claim of the client's company.
     *
     * @return list<array{id: string, at: CarbonImmutable, text: string, type: string, target: int|null}>
     */
    private function designOfficeEvents(ClientAccount $account): array
    {
        $events = [];
        $audits = $account->visibleAudits()->select('id');

        foreach (AuditDossierVersion::query()->with('audit:id,cabinet_key')->whereIn('audit_id', $audits)->where('status', AuditDossierStatus::Published)->get() as $version) {
            $events[] = ['id' => 'dossier:'.$version->id, 'at' => CarbonImmutable::parse($version->published_at), 'text' => '📦 '.__('client_portal.design_office.event_dossier', ['version' => $version->version_number, 'cabinet' => $version->audit->cabinet_key]), 'type' => 'dossier', 'target' => null];
        }

        $answered = AuditClientClaim::query()->with('audit:id,cabinet_key')->whereIn('audit_id', $audits)->whereNotNull('decided_at')
            ->whereHas('clientAccount', fn ($accounts) => $accounts->where('client_id', $account->client_id))->get();

        foreach ($answered as $claim) {
            $key = $claim->kind === AuditClientClaimKind::ReserveDispute ? 'event_dispute' : 'event_occurrence';
            $events[] = ['id' => 'claim:'.$claim->id, 'at' => CarbonImmutable::parse($claim->decided_at), 'text' => '💬 '.__('client_portal.design_office.'.$key, ['cabinet' => $claim->audit->cabinet_key, 'state' => $claim->state->label()]), 'type' => 'dossier', 'target' => null];
        }

        return $events;
    }

    /**
     * After the first load, a new event shows as a toast (the newest one).
     *
     * @param  list<array{id: string, at: CarbonImmutable, text: string, type: string, target: int|null}>  $events
     */
    private function toastNewEvents(array $events): void
    {
        $ids = array_column($events, 'id');

        if ($this->knownEvents !== null) {
            $fresh = array_values(array_filter($events, fn (array $event) => ! in_array($event['id'], $this->knownEvents, true)));

            if ($fresh !== []) {
                $this->dispatch('toast', message: $fresh[0]['text']);
            }
        }

        $this->knownEvents = $ids;
    }

    /**
     * @return Collection<int, Overview>
     */
    private function overviews(ClientAccount $account, PortalOverview $overview): Collection
    {
        $audits = $account->visibleAudits()->get()->sortBy('cabinet_key', SORT_NATURAL)->values();
        $presences = AuditPresence::query()->whereIn('audit_id', $audits->modelKeys())->orderBy('id')->toBase()->get(['id', 'audit_box_id', 'state', 'seen_at']);
        $key = 'portal-overview:'.$account->id.':'.sha1($audits->map(fn (Audit $audit) => $audit->id.'.'.$audit->revision)->implode(',').json_encode($presences));

        /** @var array<int, array<string, mixed>> $cached */
        $cached = Cache::remember($key, self::OVERVIEW_CACHE_SECONDS, function () use ($audits, $overview): array {
            $overview->load($audits);

            return $audits->mapWithKeys(fn (Audit $audit) => [$audit->id => Arr::except($overview->of($audit), ['audit'])])->all();
        });

        /** @var Collection<int, Overview> */
        return $audits->map(fn (Audit $audit) => ['audit' => $audit] + $cached[$audit->id]);
    }

    /**
     * Rows matching the search (box name or support number) and the chips, in the chosen order.
     *
     * @param  list<Row>  $rows
     * @return list<Row>
     */
    private function filter(array $rows): array
    {
        $search = mb_strtolower(trim($this->search));

        $kept = array_filter($rows, fn (array $row) => ($this->kind === 'all' || $row['kind'] === $this->kind)
            && ($this->support === 'all' || $row['support'] === $this->support)
            && ($this->status === 'all' || $row['status'] === $this->status)
            && ($search === '' || str_contains(mb_strtolower($row['name']), $search) || str_contains(mb_strtolower($row['support_number']), $search)));

        $column = in_array($this->sort, self::SORTS, true) ? $this->sort : 'name';

        usort($kept, function (array $a, array $b) use ($column): int {
            $compare = match ($column) {
                'name' => strnatcasecmp($a['name'], $b['name']),
                'last' => ($a['last']?->getTimestamp() ?? 0) <=> ($b['last']?->getTimestamp() ?? 0),
                default => $a[$column] <=> $b[$column],
            };

            return $this->direction * $compare;
        });

        return $kept;
    }
}
