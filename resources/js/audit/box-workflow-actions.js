import { showToast } from '../components/toast';
import { queuedFlows } from './box-workflow';
import { xsrf } from './sync';

const TO_DEAL = ['detected', 'to_repair', 'refused'];
const MIN_DESCRIPTION = 3;
const MIN_HOLD = 10;
const MIN_ACTION = 3;
const MIN_IMPACT = 5;
const MIN_SYNOPTIC = 5;
const OCCURRENCE_ACTIONS = ['occurrence_create', 'occurrence_photo', 'occurrence_hold', 'occurrence_correct', 'occurrence_box', 'occurrence_result'];
const SHEET_TITLES = { occHold: 'hold_title', occCorrect: 'correct_title', occLink: 'link_title', occBox: 'box_title', occResult: 'result_title' };
const NONE = { hold: false, correct: false, link: false, propose: false, result: false };

/**
 * What the person may do on an occurrence from the box screen, as far as the phone knows (the server decides): put an
 * optical anomaly on hold, record its correction (optical ones in phase 1, any in phase 2 inside a planned
 * intervention the person belongs to), link photos, propose another impacted box (optical), and give the result on
 * this box when the occurrence comes from another one.
 *
 * @param {object} occurrence As the server describes it (OccurrenceView).
 * @param {{field: boolean, phase: number, inIntervention: boolean, boxId: number}} context
 */
export function occurrenceActions(occurrence, { field, phase, inIntervention, boxId }) {
    if (!field || occurrence.is_closed) {
        return NONE;
    }

    const optical = occurrence.nature === 'optical';
    const toDeal = TO_DEAL.includes(occurrence.state);
    const here = (occurrence.boxes ?? []).find((link) => link.box_id === boxId);

    return {
        hold: optical && toDeal,
        correct: toDeal && (phase <= 1 ? optical : inIntervention),
        link: true,
        propose: optical,
        result: Boolean(occurrence.is_inherited) && here?.state === 'confirmed',
    };
}

/**
 * Occurrences declared on this phone and not sent yet (they get their reference from the server).
 */
export const waitingOccurrences = (queued) => queued.filter((item) => item.flow.action === 'occurrence_create').map((item) => ({
    key: `q${item.id}`, type: item.flow.label ?? '', description: item.flow.body.description,
}));

/**
 * The other technician actions of the design office workflow on the box screen (spec 13 §3.9): the occurrences of the
 * box (declare one with its « before » photos, link photos, put an optical one on hold, record a correction with its
 * « after » photos, propose another impacted box, give the result on this box), a proposal of the position of the
 * box, a correction of the synoptic, and the pauses. Everything but the pauses goes through the queue of the phone
 * (type « flow ») and so works offline: photos still waiting on the phone are named by their queue id and get their
 * server id before the action is sent (sync.js). Pauses need the network, so that their times are the server's.
 */
export default () => ({
    occDraft: { type: '', description: '', location: '' },
    occForm: { justification: '', action: '', checks: '', observations: '', role: 'after', box: '', conformity: 'conforming', note: '' },
    occTarget: null,
    pickedPhotos: [],
    synDraft: '',
    pauseBusy: false,

    get flowField() {
        return this.flowRole === 'technician' || this.flowRole === 'supervisor';
    },

    flowActionUrl(name, id) {
        return this.flowUrl(name).replace('__ID__', String(id));
    },

    /* ---- the occurrences of the box ---- */

    get occKnown() {
        return this.flowKnown && Boolean(this.flowStatus.occurrences);
    },

    get occList() {
        return this.occKnown ? this.flowStatus.occurrences.occurrences : [];
    },

    get occTypes() {
        return this.occKnown ? this.flowStatus.occurrences.anomaly_types : [];
    },

    get occWaiting() {
        return waitingOccurrences(this.flowQueued);
    },

    get occEmpty() {
        return this.occList.length === 0 && this.occWaiting.length === 0;
    },

    get occActionsWaiting() {
        const count = this.flowQueued.filter((item) => OCCURRENCE_ACTIONS.includes(item.flow.action) && item.flow.action !== 'occurrence_create').length;

        return count > 0 ? this.ft('actions_waiting', { count }) : '';
    },

    get occCanDeclare() {
        return this.flowField && this.flowShown && !this.view?.audit?.closed;
    },

    get occDeclareText() { return `＋ ${this.ft('occurrence_declare')}`; },

    occActions(occurrence) {
        const intervention = this.flowRepairs?.intervention;

        return occurrenceActions(occurrence, {
            field: this.flowField,
            phase: this.flowRepairs?.phase ?? 1,
            inIntervention: Boolean(intervention?.is_member) && intervention.status === 'planned',
            boxId: this.box?.id,
        });
    },

    occCan(occurrence, action) {
        return this.occActions(occurrence)[action] === true;
    },

    occTitle(occurrence) {
        return `${occurrence.reference} · ${occurrence.type} — ${occurrence.state_label}`;
    },

    occClass(occurrence) {
        return `box-flow__occurrence--${occurrence.is_closed ? 'closed' : occurrence.nature}`;
    },

    occDetails(occurrence) {
        const lines = [];
        const origin = (occurrence.boxes ?? []).find((link) => link.is_origin);
        const before = occurrence.photos.filter((photo) => photo.role === 'before').length;
        const after = occurrence.photos.filter((photo) => photo.role === 'after').length;

        if (occurrence.is_inherited && origin) {
            lines.push(this.ft('occurrence_inherited', { box: origin.name }));
        }

        if (occurrence.location_note) {
            lines.push(occurrence.location_note);
        }

        lines.push(this.ft('occurrence_photos', { before, after }));

        if (occurrence.pending_justification) {
            lines.push(this.ft(occurrence.is_continuation_authorized ? 'occurrence_continuation' : 'occurrence_on_hold', { text: occurrence.pending_justification }));
        }

        if (occurrence.action_taken) {
            lines.push(this.ft('occurrence_action', { text: occurrence.action_taken }));
        }

        return lines;
    },

    occWaitingText(item) {
        return `⏳ ${item.type} — ${item.description} · ${this.ft('message_waiting')}`;
    },

    get occHoldText() { return this.ft('occ_hold'); },
    get occCorrectText() { return `✓ ${this.ft('occ_correct')}`; },
    get occLinkText() { return `📎 ${this.ft('occ_link')}`; },
    get occProposeText() { return this.ft('occ_propose_box'); },
    get occResultText() { return this.ft('occ_result'); },

    /* photos of the box to choose from (sent or still waiting on the phone) */

    get pickable() {
        return this.box?.photos ?? [];
    },

    get pickNone() {
        return this.pickable.length === 0;
    },

    isPicked(photo) {
        return this.pickedPhotos.includes(photo.id);
    },

    pickClass(photo) {
        return `${photo.pending ? 'is-pending' : ''}${this.isPicked(photo) ? ' is-selected' : ''}`;
    },

    pickMark(photo) {
        return this.isPicked(photo) ? '☑' : '☐';
    },

    togglePicked(photo) {
        if (this.sheet?.single) {
            this.pickedPhotos = this.isPicked(photo) ? [] : [photo.id];

            return;
        }

        this.pickedPhotos = this.isPicked(photo) ? this.pickedPhotos.filter((id) => id !== photo.id) : [...this.pickedPhotos, photo.id];
    },

    occOpen(type, occurrence = null, extra = {}) {
        this.occTarget = occurrence;
        this.pickedPhotos = [];
        this.occForm = { justification: '', action: '', checks: '', observations: '', role: 'after', box: '', conformity: 'conforming', note: '' };
        this.sheet = { type, ...extra };
    },

    get occSheetTitle() {
        const key = SHEET_TITLES[this.sheet?.type] ?? null;

        return this.occTarget && key ? this.ft(key, { reference: this.occTarget.reference, box: this.box?.name ?? '' }) : '';
    },

    async occQueue(action, url, body, label = null) {
        this.sheet = null;
        await this.engine.enqueueFlow({ action, box: this.box.id, url, body, side: 'team', label });
        showToast(navigator.onLine ? this.ft('action_sending') : this.ft('action_saved'));
    },

    occRefuse(key) {
        showToast(this.ft(key));

        return false;
    },

    /* declare */

    occOpenCreate() {
        if (this.occCanDeclare) {
            this.occDraft = { type: '', description: '', location: '' };
            this.occOpen('occCreate');
        }
    },

    async occCreate() {
        const type = this.occTypes.find((candidate) => String(candidate.id) === String(this.occDraft.type));
        const description = this.occDraft.description.trim();

        if (!type) {
            return this.occRefuse('type_required');
        }

        if (description.length < MIN_DESCRIPTION) {
            return this.occRefuse('description_required');
        }

        if (this.pickedPhotos.length === 0) {
            return this.occRefuse('before_required');
        }

        await this.occQueue('occurrence_create', this.flowUrl('occurrenceCreate'), {
            anomaly_type_id: type.id, description, location_note: this.occDraft.location.trim() || null, before_photo_ids: [...this.pickedPhotos],
        }, type.label);

        return true;
    },

    /* put on hold */

    async occHold() {
        const justification = this.occForm.justification.trim();

        if (justification.length < MIN_HOLD) {
            return this.occRefuse('hold_required');
        }

        await this.occQueue('occurrence_hold', this.flowActionUrl('occurrenceHold', this.occTarget.id), { justification });

        return true;
    },

    /* correct, with its « after » photos */

    async occCorrect() {
        const action = this.occForm.action.trim();
        const hasAfter = this.occTarget.photos.some((photo) => photo.role === 'after');

        if (action.length < MIN_ACTION) {
            return this.occRefuse('action_required');
        }

        if (this.pickedPhotos.length === 0 && !hasAfter) {
            return this.occRefuse('after_required');
        }

        await this.occQueue('occurrence_correct', this.flowActionUrl('occurrenceCorrect', this.occTarget.id), {
            action, after_photo_ids: [...this.pickedPhotos], checks: this.occForm.checks.trim() || null, observations: this.occForm.observations.trim() || null,
        });

        return true;
    },

    /* link photos in a role: one action per photo */

    get occRoles() {
        return ['before', 'after', 'other'];
    },

    occRoleLabel(role) {
        return this.flowConfig?.photoRoles?.[role] ?? role;
    },

    async occLink() {
        if (this.pickedPhotos.length === 0) {
            return this.occRefuse('photos_required');
        }

        const url = this.flowActionUrl('occurrencePhoto', this.occTarget.id);
        const role = this.occRoles.includes(this.occForm.role) ? this.occForm.role : 'other';
        const photos = [...this.pickedPhotos];

        this.sheet = null;

        for (const photo of photos) {
            await this.engine.enqueueFlow({ action: 'occurrence_photo', box: this.box.id, url, body: { photo_id: photo, role }, side: 'team' });
        }

        showToast(navigator.onLine ? this.ft('action_sending') : this.ft('action_saved'));

        return true;
    },

    /* propose another impacted box */

    get occOtherBoxes() {
        const linked = new Set((this.occTarget?.boxes ?? []).map((link) => link.box_id));

        return (this.view?.boxes ?? []).filter((box) => !box.removed && box.id !== this.box?.id && !linked.has(box.id));
    },

    async occPropose() {
        const box = this.occOtherBoxes.find((candidate) => String(candidate.id) === String(this.occForm.box));
        const justification = this.occForm.justification.trim();

        if (!box) {
            return this.occRefuse('box_required');
        }

        if (justification.length < MIN_IMPACT) {
            return this.occRefuse('impact_required');
        }

        await this.occQueue('occurrence_box', this.flowActionUrl('occurrenceBox', this.occTarget.id), { box_id: box.id, justification });

        return true;
    },

    /* result of an occurrence of another box on this one */

    get occConformities() {
        return ['conforming', 'non_conforming'];
    },

    occConformityLabel(value) {
        return this.ft(`result_${value}`);
    },

    occConformityClass(value) {
        return this.segmentClass(this.occForm.conformity, value);
    },

    occSetConformity(value) {
        this.occForm = { ...this.occForm, conformity: value };
    },

    async occResult() {
        const conformity = this.occConformities.includes(this.occForm.conformity) ? this.occForm.conformity : 'conforming';

        await this.occQueue('occurrence_result', this.flowActionUrl('occurrenceResult', this.occTarget.id), { conformity, note: this.occForm.note.trim() || null });

        return true;
    },

    /* ---- position of the box proposed from where the person stands ---- */

    get positionProposalText() {
        const proposal = this.flowKnown ? this.flowStatus.position_proposal : null;

        if (!proposal) {
            return '';
        }

        return this.ft(`position_${proposal.state}`, { reason: proposal.rejection_reason ?? '' });
    },

    get positionQueued() {
        return this.flowQueued.some((item) => item.flow.action === 'position_propose');
    },

    get canProposePosition() {
        return this.flowTechnician && !this.positionQueued && !this.view?.audit?.closed;
    },

    get proposePositionText() { return `📍 ${this.ft('propose_position')}`; },

    async proposePosition() {
        if (!this.canProposePosition) {
            return;
        }

        const fix = await this.currentFix();

        if (!fix) {
            showToast(this.t('position_unavailable'));

            return;
        }

        await this.engine.enqueueFlow({ action: 'position_propose', box: this.box.id, url: this.flowUrl('positionPropose'), body: fix, side: 'team' });
        showToast(this.ft('position_sent'));
    },

    /* ---- corrections of the synoptic ---- */

    get synopticList() {
        return this.flowKnown ? (this.flowStatus.synoptic_corrections ?? []) : [];
    },

    get synopticWaiting() {
        return this.flowQueued.filter((item) => item.flow.action === 'synoptic_correction').map((item) => ({ key: `q${item.id}`, text: item.flow.body.text }));
    },

    synopticText(correction) {
        return `${correction.state_label} — ${correction.text}`;
    },

    synopticNote(correction) {
        return correction.decision_note ? this.ft('synoptic_note', { note: correction.decision_note }) : '';
    },

    synopticWaitingText(item) {
        return `⏳ ${item.text} · ${this.ft('message_waiting')}`;
    },

    get canProposeSynoptic() {
        return this.flowField && !this.view?.audit?.closed;
    },

    get synopticProposeText() { return `✎ ${this.ft('synoptic_propose')}`; },

    synopticOpen() {
        if (this.canProposeSynoptic) {
            this.synDraft = '';
            this.occOpen('synoptic', null, { single: true });
        }
    },

    async synopticSend() {
        const text = this.synDraft.trim();

        if (text.length < MIN_SYNOPTIC) {
            return this.occRefuse('synoptic_required');
        }

        await this.occQueue('synoptic_correction', this.flowUrl('synopticCorrection'), { text, box: this.box.name, photo: this.pickedPhotos[0] ?? null });

        return true;
    },

    /* ---- pauses: online only, so that their times are the server's ---- */

    get flowPause() {
        return this.flowKnown ? (this.flowStatus.current_pause ?? null) : null;
    },

    get pauseText() {
        const pause = this.flowPause;

        if (!pause) {
            return '';
        }

        if (!pause.is_here) {
            return this.ft('paused_elsewhere', { box: pause.box ?? '' });
        }

        return this.ft('paused_since', { time: new Date(pause.started_at).toLocaleTimeString(document.documentElement.lang || undefined, { timeStyle: 'short' }) });
    },

    get canPause() {
        return this.flowTechnician && this.flowInTeam && this.flowPause === null;
    },

    get canResume() {
        return this.flowTechnician && Boolean(this.flowPause?.is_here);
    },

    get flowInTeam() {
        const box = this.flowBox;

        return box !== null && (box.is_responsible || box.participants.some((person) => person.id === this.config.userId));
    },

    get pauseButtonText() { return `⏸ ${this.ft('pause')}`; },
    get resumeButtonText() { return `▶ ${this.ft('resume')}`; },

    async pauseStart() {
        await this.pausePost('pause');
    },

    async pauseEnd() {
        await this.pausePost('resume');
    },

    async pausePost(name) {
        if (!navigator.onLine) {
            showToast(this.ft('network_required'));

            return;
        }

        if (this.pauseBusy) {
            return;
        }

        this.pauseBusy = true;

        try {
            const response = await fetch(this.flowUrl(name), {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            });
            const answer = await response.json().catch(() => ({}));

            showToast(response.ok ? this.ft(name === 'pause' ? 'pause_started' : 'pause_ended') : (answer.message ?? this.error(answer.reason ?? 'invalid')));
            await this.flowRefresh();
        } catch {
            showToast(this.ft('network_required'));
        } finally {
            this.pauseBusy = false;
        }
    },
});
