import { showToast } from '../components/toast';
import { dbGet, dbPut } from './db';
import { xsrf } from './sync';

const POLL_EVERY = 15000;
const MIN_JUSTIFICATION = 5;
const SUBMITTABLE = ['in_progress', 'refused', 'control_requested'];
const OUTCOME_TONES = { validate: 'ok', validate_with_reserves: 'ok', authorize_continuation: 'ok', repairs_validated: 'ok', refuse: 'alert', repairs_refused: 'alert' };

const normaliseName = (name) => String(name ?? '').replace(/\s+/g, ' ').trim().toUpperCase();
const fill = (template, values = {}) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value ?? '')), template ?? '');

/**
 * The design office actions of one box still waiting on this phone, oldest first.
 */
export const queuedFlows = (queue, boxId) => queue.filter((item) => item.type === 'flow' && item.flow?.box === boxId);

/**
 * The mandatory folders still missing, as the server last said, with those for which a photo waits on this phone set
 * apart (they need no justification: the server checks again when the submission arrives after them).
 *
 * @returns {Array<{folder: string, label: string, waiting: boolean}>}
 */
export function missingFolders(status, queue, boxName) {
    const waiting = new Set(queue.filter((item) => item.type === 'photo' && normaliseName(item.meta?.box) === normaliseName(boxName)).map((item) => item.meta.folder));

    return (status?.missing_folders ?? []).map((folder) => ({ ...folder, waiting: waiting.has(folder.folder) }));
}

/**
 * The written justifications to send: one per missing folder without a photo waiting, long enough, or the folders
 * that still lack one.
 *
 * @returns {{justifications: Record<string, string>, lacking: string[]}}
 */
export function justificationsFor(missing, drafts) {
    const justifications = {};
    const lacking = [];

    for (const folder of missing.filter((candidate) => !candidate.waiting)) {
        const text = String(drafts[folder.folder] ?? '').trim();

        if (text.length >= MIN_JUSTIFICATION) {
            justifications[folder.folder] = text;
        } else {
            lacking.push(folder.label);
        }
    }

    return { justifications, lacking };
}

/**
 * The messages of the box: those the server has, then those written on this phone and not sent yet.
 */
export function threadOf(status, queue, boxId, viewerName) {
    const sent = (status?.messages ?? []).map((message) => ({ ...message, key: `m${message.id}`, pending: false }));
    const waiting = queuedFlows(queue, boxId).filter((item) => item.flow.action === 'message').map((item) => ({
        key: `q${item.id}`, kind: item.flow.body.kind ?? 'chat', body: item.flow.body.body, is_blocking: (item.flow.body.kind ?? 'chat') !== 'chat',
        photo_id: item.flow.body.photo_id ?? null, by: viewerName, side: item.flow.side ?? 'team', is_mine: true, at: new Date(item.created_at).toISOString(), resolved: null, pending: true,
    }));

    return [...sent, ...waiting];
}

/**
 * Whether the person is (or will be, once their start reaches the server) the responsible of the box.
 */
export function isResponsible(status, queued) {
    if (status?.box?.is_responsible) {
        return true;
    }

    return queued.some((item) => item.flow.action === 'claim') && !status?.box?.responsible;
}

/**
 * Whether the technician already works on the box (responsible or partner), as far as the phone knows.
 */
export function isInTeam(status, queued, viewerId) {
    return Boolean(status?.box?.is_responsible) || (status?.box?.participants ?? []).some((person) => person.id === viewerId) || queued.some((item) => item.flow.action === 'claim');
}

/**
 * A decision the person has not been told about yet (the time it was taken identifies it).
 */
export const unseenDecision = (status, seenAt) => (status?.decision && status.decision.decided_at !== seenAt ? status.decision : null);

/**
 * Messages of the design office or a supervisor the person has not been told about yet.
 */
export const unseenMessages = (status, seenIds) => (status?.messages ?? []).filter((message) => message.side === 'office' && !message.is_mine && !seenIds.includes(message.id));

const readSeen = (key, fallback) => {
    try {
        const kept = window.localStorage.getItem(key);

        return kept === null ? fallback : JSON.parse(kept);
    } catch {
        return fallback;
    }
};

const writeSeen = (key, value) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Without storage the person is simply told again next time.
    }
};

/**
 * The design office workflow on the box screen (spec 13 §3.9): where the box stands (polled from the server and kept
 * on the phone), start it, submit it with the written justification of each mandatory photo that could not be taken,
 * hand it over, the decision once taken, and the internal chat of the box. What the technician does is queued like
 * every other action, so it works offline: a submission waiting for the network is said to be « not yet sent to the
 * design office », and the review clock only starts when the server receives it. The design office and the
 * supervisors write observations and requests for an additional control here and resolve them (online).
 */
export default () => ({
    flowStatus: null,
    flowSavedAt: null,
    flowLoadedBox: null,
    flowJustifications: {},
    flowDraft: { body: '', kind: 'chat', photo: null },
    flowTimer: null,

    get flowConfig() {
        return this.config.designOffice ?? null;
    },

    get flowShown() {
        return this.flowConfig !== null && this.view?.audit?.workflow === 'design_office' && this.box !== null;
    },

    get flowRole() {
        return this.flowConfig?.role ?? null;
    },

    get flowTechnician() {
        return this.flowRole === 'technician';
    },

    get flowOffice() {
        return this.flowRole === 'reviewer' || this.flowRole === 'supervisor';
    },

    ft(key, values) {
        return fill(this.flowConfig?.texts?.[key] ?? key, values);
    },

    flowUrl(name, boxId = this.box?.id) {
        return (this.flowConfig?.urls?.[name] ?? '').replace('__BOX__', String(boxId));
    },

    get flowQueued() {
        this.touch();

        return this.box && this.engine ? queuedFlows(this.engine.queue, this.box.id) : [];
    },

    /* ---- where the box stands: read on the server every 15 s, kept on the phone for when there is no network ---- */

    flowStart() {
        if (this.flowConfig === null || this.flowTimer !== null) {
            return;
        }

        this.engine.addEventListener('flow', (event) => this.flowAnswered(event.detail));
        this.engine.addEventListener('rejected', (event) => event.detail?.type === 'flow' && this.flowRefresh());
        this.flowTimer = setInterval(() => document.visibilityState === 'visible' && this.flowRefresh(), POLL_EVERY);
        document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && this.flowRefresh());
        this.flowRefresh();
    },

    async flowRefresh() {
        if (!this.flowShown) {
            return;
        }

        const boxId = this.box.id;

        if (this.flowLoadedBox !== boxId) {
            this.flowLoadedBox = boxId;
            await this.flowLoadKept(boxId);
        }

        if (!navigator.onLine) {
            return;
        }

        try {
            const response = await fetch(this.flowUrl('status', boxId), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

            if (response.ok) {
                await this.flowKeep(boxId, await response.json());
            }
        } catch {
            // No network: the screen keeps what the phone last read.
        }
    },

    async flowLoadKept(boxId) {
        try {
            const kept = await dbGet('workflow', boxId);

            if (kept && kept.userId === this.config.userId && this.flowStatus === null) {
                this.flowStatus = kept.status;
                this.flowSavedAt = kept.saved_at;
            }
        } catch {
            // Nothing kept: the server will say.
        }
    },

    async flowKeep(boxId, status) {
        if (this.box?.id !== boxId || !status?.box) {
            return;
        }

        this.flowStatus = status;
        this.flowSavedAt = Date.now();
        this.flowAnnounce(status);

        try {
            await dbPut('workflow', { box: boxId, cabinet: this.config.cabinet, userId: this.config.userId, status, saved_at: this.flowSavedAt });
        } catch {
            // Only this page keeps it.
        }
    },

    /**
     * The server has an action sent from the queue: its answer says where the box stands now.
     */
    async flowAnswered({ item, answer }) {
        if (item.flow?.box !== this.box?.id) {
            return;
        }

        if (answer?.status) {
            await this.flowKeep(item.flow.box, answer.status);
        } else {
            await this.flowRefresh();
        }
    },

    /**
     * Tells the technician once about a decision on the box and about new messages of the design office (from the
     * second time the box is read on this phone: what was there before is simply shown).
     */
    flowAnnounce(status) {
        const decisionKey = `audit_seen_decision_${status.box.id}`;
        const messagesKey = `audit_seen_flow_${status.box.id}`;
        const seenDecision = readSeen(decisionKey, undefined);
        const decision = seenDecision === undefined ? null : unseenDecision(status, seenDecision);
        const seenMessages = readSeen(messagesKey, null);

        if (decision !== null && this.flowTechnician) {
            showToast(this.ft('decision_toast', { box: status.box.name, decision: decision.label }));
            navigator.vibrate?.(200);
        }

        if (seenMessages !== null && this.flowTechnician) {
            for (const message of unseenMessages(status, seenMessages)) {
                showToast(this.ft('message_toast', { box: status.box.name, who: message.by, text: message.body }));
            }
        }

        writeSeen(decisionKey, status.decision?.decided_at ?? '');
        writeSeen(messagesKey, (status.messages ?? []).map((message) => message.id));
    },

    /* ---- what the panel shows ---- */

    get flowBox() {
        return this.flowStatus?.box?.id === this.box?.id ? this.flowStatus.box : null;
    },

    get flowKnown() {
        return this.flowBox !== null;
    },

    get flowClientRepair() {
        return this.flowKnown && Boolean(this.flowStatus.client_repair) && isInTeam(this.flowStatus, [], this.config.userId);
    },

    get flowStateText() {
        return this.flowBox?.inspection_state_label ?? this.ft('state_unknown');
    },

    get flowStateClass() {
        return `box-flow__state--${this.flowBox?.inspection_state ?? 'unknown'}`;
    },

    get flowTeamText() {
        const box = this.flowBox;

        if (box === null) {
            return '';
        }

        const responsible = box.responsible ? this.ft('responsible', { name: box.responsible.name }) : this.ft('not_started');
        const partners = box.participants.map((person) => person.name).join(', ');

        return partners === '' ? responsible : `${responsible} · ${this.ft('participants', { names: partners })}`;
    },

    get flowSavedText() {
        this.touch();

        return this.flowSavedAt !== null && !navigator.onLine ? this.ft('saved_at', { time: new Date(this.flowSavedAt).toLocaleTimeString(document.documentElement.lang || undefined, { timeStyle: 'short' }) }) : '';
    },

    get flowSubmitQueued() {
        return this.flowQueued.some((item) => item.flow.action === 'submit');
    },

    get flowClaimQueued() {
        return this.flowQueued.some((item) => item.flow.action === 'claim');
    },

    get flowHandOverQueued() {
        return this.flowQueued.some((item) => item.flow.action === 'hand_over');
    },

    get flowBlockedText() {
        const blocking = this.flowStatus?.blocked_by;

        return blocking && this.flowTechnician && this.flowKnown ? this.ft('blocked', { box: blocking.name }) : '';
    },

    get flowBlockingText() {
        const count = this.flowKnown ? (this.flowStatus.blocking_messages ?? 0) : 0;

        return count > 0 ? this.ft('blocking_messages', { count }) : '';
    },

    get flowHandoverText() {
        return this.flowBox?.handover_requested ? this.ft('handover_requested') : '';
    },

    get flowRequest() {
        return this.flowKnown ? this.flowStatus.request : null;
    },

    get flowRequestText() {
        const request = this.flowRequest;

        if (!request || request.state === 'decided') {
            return '';
        }

        const time = new Date(request.queued_at).toLocaleTimeString(document.documentElement.lang || undefined, { timeStyle: 'short' });

        return this.ft('request_state', { state: request.state_label, time });
    },

    get flowDecision() {
        return this.flowKnown ? this.flowStatus.decision : null;
    },

    get flowDecisionClass() {
        return `box-flow__decision--${OUTCOME_TONES[this.flowDecision?.outcome] ?? 'wait'}`;
    },

    get flowDecisionTitle() {
        const decision = this.flowDecision;

        if (!decision) {
            return '';
        }

        const when = new Date(decision.decided_at).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' });

        return this.ft('decision', { decision: decision.label, who: decision.by, time: when });
    },

    get flowDecisionReason() {
        return this.flowDecision?.reason ?? '';
    },

    get flowTargets() {
        return this.flowDecision?.targets ?? [];
    },

    /* ---- what the technician can do ---- */

    get flowCanClaim() {
        return this.flowTechnician && !isInTeam(this.flowStatus?.box?.id === this.box?.id ? this.flowStatus : null, this.flowQueued, this.config.userId);
    },

    get flowResponsible() {
        return isResponsible(this.flowBox === null ? null : this.flowStatus, this.flowQueued);
    },

    get flowCanSubmit() {
        if (!this.flowTechnician || !this.flowResponsible || this.flowSubmitQueued || this.flowBlockedText !== '') {
            return false;
        }

        return this.flowBox === null || this.flowBox.inspection_state === 'to_do' || SUBMITTABLE.includes(this.flowBox.inspection_state);
    },

    get flowCanHandOver() {
        return this.flowTechnician && Boolean(this.flowBox?.is_responsible) && !this.flowHandOverQueued;
    },

    get flowClaimText() { return `▶ ${this.ft('claim')}`; },
    get flowSubmitText() { return `📤 ${this.ft('submit')}`; },
    get flowHandOverText() { return `🤝 ${this.ft('hand_over')}`; },

    /**
     * Starting a box makes the technician its responsible (or its partner when someone started it first).
     */
    async flowClaim() {
        if (this.flowCanClaim) {
            await this.flowEnqueue('claim', this.flowUrl('claim'), {});
        }
    },

    /**
     * Before the first photo of a technician on a box of the workflow, the box is started for them.
     */
    async flowBeforeFieldWork() {
        if (this.flowShown && this.flowCanClaim) {
            await this.flowClaim();
        }
    },

    async flowEnqueue(action, url, body, side = 'team') {
        return this.engine.enqueueFlow({ action, box: this.box.id, url, body, side });
    },

    /* submit, with a written justification for each mandatory photo that could not be taken */

    get flowMissing() {
        this.touch();

        return this.box && this.engine ? missingFolders(this.flowBox === null ? null : this.flowStatus, this.engine.queue, this.box.name) : [];
    },

    get flowMissingShown() {
        return this.flowMissing.length > 0;
    },

    get flowUnknownMissing() {
        return this.flowBox === null;
    },

    flowJustification(folder) {
        return this.flowJustifications[folder.folder] ?? '';
    },

    setFlowJustification(folder, event) {
        this.flowJustifications = { ...this.flowJustifications, [folder.folder]: event.target.value };
    },

    flowFolderHint(folder) {
        return folder.waiting ? this.ft('photo_waiting') : this.ft('justify');
    },

    flowOpenSubmit() {
        if (!this.flowCanSubmit) {
            return;
        }

        if (this.flowBlockingText !== '') {
            showToast(this.flowBlockingText);

            return;
        }

        this.sheet = { type: 'flowSubmit' };
    },

    async flowConfirmSubmit() {
        const { justifications, lacking } = justificationsFor(this.flowMissing, this.flowJustifications);

        if (lacking.length > 0) {
            showToast(this.ft('justification_required', { folders: lacking.join(', ') }));

            return;
        }

        this.sheet = null;
        await this.flowEnqueue('submit', this.flowUrl('submit'), { justifications });
        this.flowJustifications = {};
        showToast(navigator.onLine ? this.ft('submit_sending') : this.ft('not_sent_yet'));
    },

    /* phase 2: the repairs of the planned intervention, sent together once corrected */

    get flowRepairs() {
        return this.flowKnown ? (this.flowStatus.repairs ?? null) : null;
    },

    get flowRepairsShown() {
        const repairs = this.flowRepairs;

        return repairs !== null && (repairs.intervention !== null || repairs.items.length > 0 || repairs.last_decision !== null);
    },

    get flowRepairItems() {
        return this.flowRepairs?.items ?? [];
    },

    flowRepairText(item) {
        return `${item.reference} · ${item.type} — ${item.state_label}`;
    },

    get flowInterventionText() {
        const intervention = this.flowRepairs?.intervention;

        if (!intervention) {
            return '';
        }

        const day = new Date(`${intervention.planned_on}T12:00:00`).toLocaleDateString(document.documentElement.lang || undefined, { dateStyle: 'short' });
        const names = [intervention.responsible, ...intervention.members.filter((member) => member.id !== intervention.responsible.id)].map((person) => person.name).join(', ');

        return this.ft('intervention', { date: day, names });
    },

    get flowRepairsQueued() {
        return this.flowQueued.some((item) => item.flow.action === 'submit_repairs');
    },

    get flowCanSubmitRepairs() {
        return this.flowTechnician && Boolean(this.flowRepairs?.can_submit) && !this.flowRepairsQueued;
    },

    get flowRepairsDecisionText() {
        const decision = this.flowRepairs?.last_decision;

        if (!decision) {
            return '';
        }

        const verdicts = decision.items.map((item) => `${item.reference} ${item.is_accepted ? '✓' : `✗${item.reason ? ` (${item.reason})` : ''}`}`).join(' · ');

        return `${this.ft('repairs_decision', { decision: decision.label, who: decision.by })}${verdicts === '' ? '' : ` — ${verdicts}`}`;
    },

    get flowSubmitRepairsText() { return `📤 ${this.ft('repairs_submit')}`; },

    async flowSubmitRepairs() {
        if (this.flowCanSubmitRepairs && (await this.confirm(this.ft('repairs_confirm')))) {
            await this.flowEnqueue('submit_repairs', this.flowUrl('submitRepairs'), {});
            showToast(navigator.onLine ? this.ft('submit_sending') : this.ft('not_sent_yet'));
        }
    },

    /* « Passer la main » to a partner of the box, or ask a supervisor to name one */

    get flowPartners() {
        return (this.flowBox?.participants ?? []).filter((person) => person.id !== this.config.userId);
    },

    get flowHandOverHint() {
        return this.flowPartners.length > 0 ? this.ft('hand_over_hint') : this.ft('hand_over_nobody');
    },

    get flowAskSupervisorText() { return this.ft('ask_supervisor'); },

    flowOpenHandOver() {
        if (this.flowCanHandOver) {
            this.sheet = { type: 'flowHandOver' };
        }
    },

    async flowHandOver(person) {
        this.sheet = null;
        await this.flowEnqueue('hand_over', this.flowUrl('handOver'), { to_user_id: person?.id ?? null });
    },

    async flowAskSupervisor() {
        await this.flowHandOver(null);
    },

    /* ---- internal chat of the box (never shown to the client) ---- */

    get flowMessages() {
        this.touch();

        return this.box && this.engine ? threadOf(this.flowBox === null ? null : this.flowStatus, this.engine.queue, this.box.id, this.view?.viewer?.name ?? '') : [];
    },

    get flowNoMessages() {
        return this.flowMessages.length === 0;
    },

    get flowKinds() {
        return this.flowOffice ? ['chat', 'observation', 'control_request'] : ['chat'];
    },

    flowKindLabel(kind) {
        return this.flowConfig?.kinds?.[kind] ?? kind;
    },

    flowBubbleClass(message) {
        const side = message.side === 'office' ? 'site-screen__bubble--office' : 'site-screen__bubble--mine';
        const blocking = message.is_blocking && !message.resolved ? ' box-flow__bubble--blocking' : '';

        return `${side}${blocking}${message.pending ? ' is-pending' : ''}`;
    },

    flowBubbleHeader(message) {
        const when = new Date(message.at).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' });
        const kind = message.kind === 'chat' ? '' : ` · ${this.flowKindLabel(message.kind)}`;
        const pending = message.pending ? ` · ${this.ft('message_waiting')}` : '';

        return `${message.side === 'office' ? '📣' : '🛠'} ${message.by} · ${when}${kind}${pending}`;
    },

    flowResolvedText(message) {
        return message.resolved ? `✓ ${this.ft('resolved_by', { who: message.resolved.by })}` : '';
    },

    flowCanResolve(message) {
        return this.flowOffice && message.is_blocking && !message.resolved && !message.pending;
    },

    get flowResolveText() { return `✓ ${this.ft('resolve')}`; },
    get flowPhotoText() { return `📷 ${this.ft('see_photo')}`; },
    get flowChatTitle() { return this.ft('chat_title'); },
    get flowChatHint() { return this.ft('chat_hint'); },
    get flowEmptyText() { return this.ft('no_messages'); },
    get flowPlaceholder() { return this.ft('message_placeholder'); },
    get flowSendText() { return this.ft('send'); },
    get flowAboutPhoto() { return this.flowDraft.photo !== null; },
    get flowAboutPhotoText() { return `📷 ${this.ft('about_photo')}`; },

    flowForgetPhoto() {
        this.flowDraft = { ...this.flowDraft, photo: null };
    },

    async flowSend() {
        const body = this.flowDraft.body.trim();

        if (body === '' || !this.flowShown) {
            return;
        }

        const kind = this.flowKinds.includes(this.flowDraft.kind) ? this.flowDraft.kind : 'chat';
        const photo = this.flowDraft.photo;

        this.flowDraft = { body: '', kind: 'chat', photo: null };
        await this.flowEnqueue('message', this.flowUrl('messages'), { body, kind, photo_id: photo }, this.flowOffice ? 'office' : 'team');
    },

    /**
     * Resolving a blocking message is an online decision of the design office or a supervisor.
     */
    async flowResolve(message) {
        if (!navigator.onLine) {
            showToast(this.t('online_required'));

            return;
        }

        try {
            const response = await fetch(this.flowConfig.urls.resolve.replace('__ID__', String(message.id)), {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            });
            const answer = await response.json().catch(() => ({}));

            if (!response.ok) {
                showToast(answer.message ?? this.error('invalid'));

                return;
            }

            await this.flowKeep(this.box.id, answer.status);
        } catch {
            showToast(this.t('online_required'));
        }
    },

    flowShowPhoto(message) {
        const photo = this.box?.photos.find((candidate) => candidate.id === message.photo_id);

        if (photo) {
            this.zoom = photo;
        }
    },

    /* ---- on a photo shown full screen: its drawings, and an observation about it ---- */

    get flowZoomShown() {
        return this.flowShown && this.zoom !== null && !this.zoom.pending && typeof this.zoom.id === 'number';
    },

    get flowZoomAnnotated() {
        return this.flowKnown && (this.flowStatus.annotated_photos ?? []).includes(this.zoom?.id);
    },

    get flowAnnotationsShown() {
        return this.flowZoomShown && (this.flowOffice || this.flowZoomAnnotated);
    },

    get flowAnnotationsUrl() {
        return this.flowZoomShown ? this.flowConfig.urls.annotations.replace('__ID__', String(this.zoom.id)) : '';
    },

    get flowAnnotationsText() {
        return `✎ ${this.flowZoomAnnotated ? this.ft('see_annotations') : this.ft('annotate')}`;
    },

    get flowObserveText() { return `📣 ${this.ft('observe_photo')}`; },

    flowObservePhoto() {
        if (!this.flowZoomShown || !this.flowOffice) {
            return;
        }

        this.flowDraft = { ...this.flowDraft, kind: 'observation', photo: this.zoom.id };
        this.zoom = null;
        showToast(this.ft('observe_hint'));
    },
});
