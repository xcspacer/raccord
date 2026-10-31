import { openBurstCamera } from '../components/burst-camera';
import { logError } from '../components/error-log';
import { showToast } from '../components/toast';
import { storage } from './db';
import boxWorkflow from './box-workflow';
import boxWorkflowActions from './box-workflow-actions';
import { boxPoint, decidePresence, manualDistance, MANUAL_WITHIN } from './geo';
import { deletableIds, deleteLocalCopies, formatSize, loadLocalPhotos, storageUsage } from './local-photos';
import { buildView, forgetBlob, urlOfBlob } from './replay';
import { boxItems, boxPercent, cableComplete, isFinal, photosOf, requiredFolders } from './rules';
import { SyncEngine } from './sync';
import { downscaleImage } from '../components/downscale';

const STATUSES = ['ok', 'anomaly', 'not_installed', 'partial'];
const BUSY_TAGS = ['INPUT', 'TEXTAREA', 'SELECT'];
const TOUCH_QUIET = 2500;
const RETRY_EVERY = 3000;
const BLOCKED_TOAST_EVERY = 10000;
const UPDATE_RELOAD_AFTER = 2500;
const UPDATE_RELOAD_KEY = 'audit_update_reload_at';

let lastTouchAt = 0;

for (const type of ['touchstart', 'pointerdown', 'keydown', 'scroll', 'wheel']) {
    document.addEventListener(type, () => { lastTouchAt = Date.now(); }, { passive: true, capture: true });
}

const text = (template, values = {}) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), template ?? '');
const newCode = () => `C${Math.random().toString(36).slice(2, 7).toUpperCase().padEnd(5, '0')}`;

/**
 * Builds an object from several, keeping getters as getters (a plain spread would freeze their value).
 */
const compose = (...parts) => {
    const result = {};

    for (const part of parts) {
        Object.defineProperties(result, Object.getOwnPropertyDescriptors(part));
    }

    return result;
};

/**
 * The page of a box for the technician on site. It works from the audit kept on the phone and what is waiting in
 * the queue, so it behaves the same with or without a network. On an audit of the design office workflow it also
 * carries that workflow (box-workflow.js).
 */
const boxScreen = () => ({
    config: {},
    engine: null,
    view: null,
    ready: false,
    boxName: '',
    announcedRevision: null,
    editing: false,
    selecting: false,
    selected: [],
    moveTarget: '',
    dragged: [],
    dropTarget: '',
    announced: null,
    watchId: null,
    drafts: { route: '', before: '', tco: '', message: '', reason: '', returnComment: '', note: '' },
    reportDraft: [],
    reportCatalog: [],
    reportSearch: '',
    sheet: null,
    zoom: null,
    savedElsewhere: false,
    tick: 0,
    shownSavedAt: null,
    shownSnapshot: '',
    shownQueue: null,
    waitingIdle: false,
    local: [],
    localUsage: null,
    localOpen: [],
    blockedToastAt: 0,

    /**
     * The sync engine lives outside Alpine's reactivity: reading the counter makes a getter depend on its changes.
     */
    touch() {
        return this.tick;
    },

    paint(element, percent) {
        element.style.setProperty('--width', `${percent}%`);
    },

    /**
     * The audit kept on the phone is shown at once while the server is asked for the latest one; when that answer
     * changes something, the screen is redrawn once the person stops touching it.
     */
    async init() {
        try {
            this.config = JSON.parse(this.$el.dataset.config);
            this.locate();
            this.engine = new SyncEngine({ cabinet: this.config.cabinet, urls: this.config.urls, userId: this.config.userId });
            this.engine.addEventListener('blocked', () => this.offlineBlocked());
            this.engine.addEventListener('revoked', () => window.location.assign(this.config.urls.login));
            this.engine.addEventListener('update', () => this.updateRequired());
            await this.engine.init();

            if (this.engine.record === null) {
                await this.engine.refresh(true);
            } else if (this.engine.queue.length === 0) {
                this.engine.refresh(true);
            }

            this.rebuild();
            this.ready = true;
            this.loadDrafts();
            this.engine.addEventListener('change', () => this.onChange());
            this.engine.addEventListener('rejected', (event) => this.refused(event.detail));
            this.engine.start();
            this.startWatch();
            this.flowStart();
        } catch (error) {
            this.crash(error);
        }
    },

    /**
     * What the person did shows at once (unless they are typing); what came from the server waits until they stop
     * touching the screen.
     */
    onChange() {
        const fromServer = (this.engine.record?.saved_at ?? null) !== this.shownSavedAt;

        (fromServer ? this.whenIdle.bind(this) : this.deferred.bind(this))(() => this.rebuild());
    },

    /**
     * Without storage on the phone, what is done offline cannot be kept: the person is told (not on every automatic
     * presence).
     */
    offlineBlocked() {
        if (Date.now() - this.blockedToastAt > BLOCKED_TOAST_EVERY) {
            this.blockedToastAt = Date.now();
            showToast(this.t('offline_blocked'));
        }
    },

    /**
     * The server expects newer code: the page reloads (once a minute at most, so a mismatch never loops) and the
     * service worker brings the current version.
     */
    updateRequired() {
        let last = 0;

        try {
            last = Number(window.sessionStorage.getItem(UPDATE_RELOAD_KEY) ?? 0);
        } catch {
            last = 0;
        }

        if (Date.now() - last < 60000) {
            return;
        }

        setTimeout(() => {
            try {
                window.sessionStorage.setItem(UPDATE_RELOAD_KEY, String(Date.now()));
            } catch {
                // The page simply reloads.
            }

            window.location.reload();
        }, UPDATE_RELOAD_AFTER);
    },

    /**
     * A screen that cannot be drawn is replaced by a notice and a button to reload it, never left frozen.
     */
    crash(error) {
        logError('render', error);

        const failure = document.querySelector('[data-render-failure]');

        if (failure) {
            this.$root.hidden = true;
            failure.hidden = false;
        }
    },

    /**
     * What this screen is about, from the address (the control point screen reads another parameter).
     */
    locate() {
        this.boxName = new URLSearchParams(window.location.search).get('name') ?? '';
    },

    t(key, values) {
        return text(this.config.texts?.[key] ?? key, values);
    },

    error(code) {
        return this.config.errors?.[code] ?? this.config.errors?.invalid ?? code;
    },

    /**
     * Applies a change coming from the background unless the person is typing or has just touched the screen.
     */
    deferred(apply) {
        if (this.ready && BUSY_TAGS.includes(document.activeElement?.tagName)) {
            setTimeout(() => this.deferred(apply), RETRY_EVERY);

            return;
        }

        apply();
    },

    /**
     * Like deferred, and also while a sheet, a photo or the camera is open or the screen was touched or scrolled
     * in the last 2.5 s.
     */
    whenIdle(apply) {
        const busy = Date.now() - lastTouchAt < TOUCH_QUIET || this.sheet !== null || this.zoom !== null || document.querySelector('.camera') !== null;

        if (this.ready && busy) {
            if (!this.waitingIdle) {
                this.waitingIdle = true;
                setTimeout(() => {
                    this.waitingIdle = false;
                    this.whenIdle(apply);
                }, RETRY_EVERY);
            }

            return;
        }

        this.deferred(apply);
    },

    /**
     * Rebuilds what the screen shows, only when the audit or the queue really changed.
     */
    rebuild() {
        try {
            const record = this.engine.record;
            const queue = this.engine.queue.map((item) => item.id).join(',');
            let snapshotChanged = false;

            if ((record?.saved_at ?? null) !== this.shownSavedAt) {
                const snapshot = JSON.stringify(record?.snapshot ?? null);

                this.shownSavedAt = record?.saved_at ?? null;
                snapshotChanged = snapshot !== this.shownSnapshot;
                this.shownSnapshot = snapshot;
            }

            if (snapshotChanged || queue !== this.shownQueue) {
                this.shownQueue = queue;

                if (record !== null && record.revision !== this.announcedRevision) {
                    this.announcedRevision = record.revision;
                    this.announceMessages();
                }

                this.view = record === null ? null : buildView(record.snapshot, this.engine.queue, { id: this.config.userId, name: record.snapshot.viewer.name });
            }

            this.tick++;
        } catch (error) {
            this.crash(error);
        }
    },

    loadDrafts() {
        this.drafts.route = this.box?.notes.route ?? '';
        this.drafts.before = this.box?.notes.before ?? '';
        this.drafts.tco = this.box?.notes.tco ?? '';
        this.drafts.reason = this.box?.reason ?? '';
    },

    get box() {
        return this.view?.boxes.find((box) => box.name === this.boxName.toUpperCase() && !box.removed) ?? null;
    },

    get mode() {
        return this.view?.audit.mode ?? 'full';
    },

    get manager() {
        return Boolean(this.view?.viewer.manager);
    },

    get locked() {
        return (Boolean(this.view?.audit.validated) && !this.manager) || Boolean(this.view?.audit.closed);
    },

    /**
     * Photos of the box: as everything else, except on a closed design office audit while an occurrence declared by
     * the client is being repaired on the box (its « after » photos are taken then).
     */
    get photoBlocked() {
        return this.blocked && !(Boolean(this.view?.audit.closed) && this.flowClientRepair);
    },

    get percent() {
        return this.box ? boxPercent(this.box, this.mode) : 0;
    },

    get missing() {
        return this.box ? boxItems(this.box, this.mode).filter((item) => !item.done && item.key !== 'status').length : 0;
    },

    /**
     * What still keeps the box under 100 %, one line per missing thing (each incomplete cable says what it lacks).
     */
    get todo() {
        if (!this.box || this.percent === 100) {
            return [];
        }

        const box = this.box;

        return boxItems(box, this.mode).filter((item) => !item.done).flatMap((item) => {
            if (item.key === 'pole') {
                const sections = ['whole', 'head', 'label'].filter((section) => photosOf(box, 'pole', section).length === 0);

                return [this.t('todo_pole', { folder: this.config.labels.folders.pole, sections: sections.map((section) => this.config.labels.poleSections[section]).join(', ') })];
            }

            if (item.key === 'cable') {
                return this.mode === 'optical' ? this.cableTodo(box) : [this.t('todo_fibre')];
            }

            if (['position', 'status'].includes(item.key)) {
                return [this.t(`todo_${item.key}`)];
            }

            return [this.t('todo_photos', { folder: this.config.labels.folders[item.key] })];
        });
    },

    cableTodo(box) {
        const cables = box.cables ?? [];

        if (cables.length === 0) {
            return [this.t('todo_no_cable')];
        }

        return cables.flatMap((cable, index) => {
            const lacks = [
                cable.capacity ? null : this.t('todo_capacity'),
                cable.fibre_type ? null : this.t('todo_fibre_type'),
                cable.has_no_label || photosOf(box, 'labels', cable.code).length > 0 ? null : this.t('todo_cable_label', { no_label: this.t('no_label') }),
            ].filter(Boolean);

            return lacks.length > 0 ? [this.t('todo_cable', { cable: this.t('cable_n', { n: index + 1 }), missing: lacks.join(', ') })] : [];
        });
    },

    get folders() {
        if (!this.box) {
            return [];
        }

        const box = this.box;
        const optical = this.mode === 'optical';
        let list = requiredFolders(box, this.mode, photosOf(box, 'tco').length > 0);

        if (optical) {
            list = list.filter((folder) => !['labels', 'lashing'].includes(folder));
        }

        if (!optical) {
            list = ['before', ...list];
        }

        return list.map((key) => ({
            key,
            title: this.config.labels.folders[key],
            photos: photosOf(box, key).filter((photo) => key !== 'pole'),
            sections: key === 'pole' ? ['whole', 'head', 'label', 'conduit'].map((section) => ({ key: section, title: this.config.labels.poleSections[section], photos: photosOf(box, 'pole', section) })) : [],
            done: boxItems(box, this.mode).find((item) => item.key === key)?.done ?? photosOf(box, key).length > 0,
            validated: this.validation(key),
            realPhotos: photosOf(box, key).filter((photo) => !photo.pending).length,
        }));
    },

    validation(folder) {
        const validation = this.box?.validations?.[folder];

        return validation && validation.count === photosOf(this.box, folder).filter((photo) => !photo.pending).length ? validation : null;
    },

    get status() {
        return this.box?.status ?? null;
    },

    get supportPlaceholder() {
        return this.t(`support_number_${this.box?.support ?? 'chamber'}`);
    },

    get chip() {
        this.touch();

        const pending = this.engine?.pending ?? 0;
        const online = navigator.onLine && this.engine?.online !== false;

        if (this.engine?.problem === 'auth') {
            return { tone: 'off', text: this.t('chip_auth') };
        }

        if (!online) {
            return { tone: 'off', text: pending > 0 ? this.t('chip_offline_pending', { count: pending }) : this.t('chip_offline') };
        }

        return pending > 0 ? { tone: 'wait', text: this.t('chip_pending', { count: pending }) } : { tone: 'ok', text: this.t('chip_synced') };
    },

    get memoryOnly() {
        this.touch();

        return this.ready && !storage.persistent;
    },

    get stalePhotos() {
        this.touch();

        return this.ready ? this.engine.stalePhotos : 0;
    },

    get stalePhotosText() {
        return this.t('stale_photos', { count: this.stalePhotos });
    },

    get refusedCount() {
        this.touch();

        return this.engine?.rejected.length ?? 0;
    },

    get blocked() {
        return this.locked || (this.manager && !this.editing);
    },

    get technician() {
        return !this.manager;
    },

    /* ---- client portal notes waiting to be taken into account (online only) ---- */

    get clientNotes() {
        return (this.view?.client_notes ?? []).filter((note) => this.box !== null && note.box_id === this.box.id);
    },

    get clientNotesTitle() {
        return `📝 ${text(this.config.clientNoteTexts?.title, { count: this.clientNotes.length })}`;
    },

    get clientNoteAckText() {
        return `✓ ${this.config.clientNoteTexts?.acknowledge ?? ''}`;
    },

    clientNoteHead(note) {
        const at = new Date(note.at);
        const time = `${String(at.getDate()).padStart(2, '0')}/${String(at.getMonth() + 1).padStart(2, '0')} ${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;

        return `${this.view.audit.cabinet} · ${note.author} (${this.config.clientNoteTexts?.client ?? ''}) · ${time}`;
    },

    async acknowledgeClientNote(note) {
        const result = await this.engine.postNow(this.config.urls.clientNoteAck.replace('__ID__', note.id));

        showToast(result.ok ? this.config.clientNoteTexts?.acknowledged : (result.error === 'offline' ? this.t('online_required') : this.error(result.error)));
    },

    get messages() {
        return this.box?.remarks ?? [];
    },

    canDelete(photo) {
        if (photo.pending) {
            return true;
        }

        return this.manager || (photo.user_id === this.config.userId && !this.view.audit.finished && !this.view.audit.validated);
    },

    /* ---- actions ---- */

    async send(operation) {
        await this.engine.enqueueOperation({ ...operation, box: this.box.name });
    },

    async setSupport(value) {
        if (this.blocked || this.box.support === value) {
            return;
        }

        await this.send({ op: 'box_set', field: 'support', value });
    },

    async setSupportNumber(value) {
        if (!this.blocked && (this.box.support_number ?? '') !== value.trim()) {
            await this.send({ op: 'box_set', field: 'support_number', value: value.trim() });
        }
    },

    async setFibre(value) {
        if (!this.blocked) {
            await this.send({ op: 'box_set', field: 'fibre_type', value: this.box.fibre_type === value ? '' : value });
        }
    },

    async saveNote(key) {
        const value = (this.drafts[key] ?? '').trim();

        if (!this.blocked && (this.box.notes[key] ?? '') !== value) {
            await this.send({ op: 'box_note', key, text: value });
        }
    },

    cables() {
        return (this.box.cables ?? []).map((cable) => ({ ...cable }));
    },

    async saveCables(cables) {
        await this.send({ op: 'box_set', field: 'cables', value: cables });
    },

    async addCable() {
        await this.saveCables([...this.cables(), { code: newCode(), has_no_label: false, capacity: null, fibre_type: null, note: null }]);
    },

    async updateCable(code, field, value) {
        const cables = this.cables().map((cable) => (cable.code === code ? { ...cable, [field]: value === '' ? null : value } : cable));

        await this.saveCables(cables);
    },

    async removeCable(code) {
        if (await this.confirm(this.t('confirm_remove_cable'))) {
            await this.saveCables(this.cables().filter((cable) => cable.code !== code));
        }
    },

    cableOk(cable) {
        return cableComplete(this.box, cable);
    },

    cablePhotos(code, folder) {
        return photosOf(this.box, folder, code);
    },

    async takePhoto(folder, subfolder = '') {
        if (this.photoBlocked) {
            return;
        }

        const result = await openBurstCamera({
            labels: { ...this.config.camera, reverse_url: this.config.urls.reverse },
            name: this.box.name,
            timeZone: this.config.timeZone,
            allowWithoutLocation: this.manager,
            confirmDiscard: (message) => this.confirm(message),
        });

        if (!result) {
            return;
        }

        if (result.shots.length > 0) {
            await this.flowBeforeFieldWork();
        }

        for (const shot of result.shots) {
            await this.engine.enqueuePhoto({ box: this.box.name, folder, subfolder, captured_at: new Date().toISOString(), latitude: shot.lat, longitude: shot.lng, geo_missing_reason: shot.reason }, shot.blob);
        }
    },

    async deletePhoto(photo) {
        if (!(await this.confirm(this.t('confirm_delete_photo')))) {
            return;
        }

        if (photo.pending) {
            await this.engine.remove(photo.id.replace('pending:', ''));

            return;
        }

        await this.send({ op: 'photo_del', photo: photo.id });
    },

    async chooseStatus(status) {
        if (this.blocked) {
            return;
        }

        if (this.status === status) {
            await this.send({ op: 'box_status', status: '' });

            return;
        }

        if (status === 'anomaly') {
            this.sheet = { type: 'anomaly' };
            this.drafts.reason = this.box.reason ?? '';

            return;
        }

        if (status === 'ok' && !(await this.checkOk())) {
            return;
        }

        await this.send({ op: 'box_status', status });
    },

    async confirmAnomaly() {
        const reason = this.drafts.reason.trim();

        if (reason === '') {
            showToast(this.error('reason_required'));

            return;
        }

        if (!(await this.checkOk(true))) {
            return;
        }

        const thenExit = Boolean(this.sheet?.thenExit);

        this.sheet = null;
        await this.send({ op: 'box_status', status: 'anomaly', reason });

        if (thenExit) {
            await this.exit();
        }
    },

    /**
     * In the full mode a box can only be closed with its « before works » comment and its route comment; with
     * items still missing the person is asked to confirm.
     */
    async checkOk(skipMissing = false) {
        if (this.mode === 'full') {
            if ((this.box.notes.before ?? '').trim() === '') {
                showToast(this.t('before_required'));

                return false;
            }

            if ((this.box.notes.route ?? '').trim() === '') {
                showToast(this.t('route_required'));

                return false;
            }

            if (this.reportRequired && this.reportSaved.length === 0) {
                showToast(this.t('report_required'));

                return false;
            }
        }

        if (!skipMissing && this.missing > 0) {
            return this.confirm(this.t('confirm_missing', { count: this.missing }));
        }

        return true;
    },

    async confirmPosition() {
        if (this.blocked) {
            return;
        }

        const found = await new Promise((resolve) => {
            if (!navigator.geolocation) {
                resolve(null);

                return;
            }

            navigator.geolocation.getCurrentPosition(
                ({ coords }) => resolve({ latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy }),
                () => resolve(null),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 5000 },
            );
        });

        if (!found) {
            showToast(this.t('position_unavailable'));

            return;
        }

        await this.send({ op: 'box_pos', ...found });
    },

    async sendMessage() {
        const message = this.drafts.message.trim();

        if (message === '') {
            return;
        }

        this.drafts.message = '';
        const id = `r_${Math.random().toString(36).slice(2, 12)}`;

        if (this.manager) {
            await this.online({ op: 'remark', text: message, id });

            return;
        }

        await this.send({ op: 'tech_message', text: message, id });
    },

    async acknowledge(remark) {
        await this.send({ op: 'remark_ack', id: remark.id });
    },

    async announce(state) {
        const fix = await this.currentFix();
        const metres = state === 'on_box' ? manualDistance(fix, this.box) : null;

        await this.engine.enqueueOperation({ op: 'presence', box: this.box.name, state, ...(fix ?? {}) });
        this.announced = { box: this.box.name, state, at: Date.now() };

        if (state === 'en_route') {
            showToast(this.t('presence_en_route'));
        } else if (fix === null) {
            showToast(this.t('presence_unverified'));
        } else {
            showToast(metres !== null && metres > MANUAL_WITHIN ? this.t('presence_far', { meters: metres }) : this.t('presence_on_box'));
        }
    },

    currentFix() {
        return new Promise((resolve) => {
            if (!navigator.geolocation) {
                resolve(null);

                return;
            }

            navigator.geolocation.getCurrentPosition(
                ({ coords }) => resolve({ latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy }),
                () => resolve(null),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 5000 },
            );
        });
    },

    /**
     * While the screen is open a technician is announced on their own: on the nearest box they are standing at, on
     * the way again when they move off, with the rules of geo.js. Nothing runs in the background or for managers.
     */
    startWatch() {
        if (this.manager || !navigator.geolocation) {
            return;
        }

        const begin = () => {
            this.watchId ??= navigator.geolocation.watchPosition(({ coords }) => this.onFix(coords), () => {}, { enableHighAccuracy: true, maximumAge: 8000, timeout: 25000 });
        };
        const end = () => {
            if (this.watchId !== null) {
                navigator.geolocation.clearWatch(this.watchId);
                this.watchId = null;
            }
        };

        document.addEventListener('visibilitychange', () => (document.visibilityState === 'visible' ? begin() : end()));

        if (document.visibilityState === 'visible') {
            begin();
        }
    },

    async onFix(coords) {
        if (this.blocked || !this.view || !this.box) {
            return;
        }

        const decision = decidePresence({
            fix: { latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy },
            boxes: this.view.boxes, announced: this.announced, openName: this.box.name, now: Date.now(),
        });

        if (decision === null) {
            return;
        }

        this.announced = { ...decision, at: Date.now() };
        await this.engine.enqueueOperation({ op: 'presence', ...decision, latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy, automatic: true });
    },

    /* ---- directions ---- */

    /** Where the directions lead: the position of the box (the cabinet on its control point). */
    get navigationTarget() {
        return this.box !== null ? boxPoint(this.box) : null;
    },

    get navigationName() {
        return this.box?.name ?? '';
    },

    get navigable() {
        return this.navigationTarget !== null;
    },

    get navigateText() {
        return `🧭 ${this.t('navigate')}`;
    },

    get navigateTitle() {
        return this.t('navigate_to', { name: this.navigationName });
    },

    get navigateCoordinates() {
        const point = this.navigationTarget;

        return `${point.latitude.toFixed(6)}, ${point.longitude.toFixed(6)}`;
    },

    get navigationLinks() {
        const point = this.navigationTarget;
        const last = window.localStorage?.getItem('audit_navigation');
        const links = Object.entries(this.config.navigation).map(([key, template]) => ({
            key, label: this.config.navigationLabels[key], url: template.replace(':lat', point.latitude).replace(':lng', point.longitude),
        }));

        return links.sort((a, b) => Number(b.key === last) - Number(a.key === last));
    },

    openNavigation() {
        this.sheet = { type: 'navigate' };
    },

    rememberNavigation(key) {
        try {
            window.localStorage?.setItem('audit_navigation', key);
        } catch {
            // Remembering the choice is a convenience only.
        }

        this.sheet = null;
    },

    /**
     * Tells the technician, once, about a message the design office wrote since they last looked.
     */
    announceMessages() {
        const record = this.engine.record?.snapshot;

        if (!record) {
            return;
        }

        const key = `audit_seen_${this.config.cabinet}`;
        let seen = null;

        try {
            seen = JSON.parse(window.localStorage.getItem(key) ?? 'null');
        } catch {
            seen = null;
        }

        const incoming = record.boxes.flatMap((box) => box.remarks.filter((remark) => remark.side === 'office').map((remark) => ({ box: box.name, remark })));

        if (seen !== null && !this.manager) {
            for (const { box, remark } of incoming.filter(({ remark: item }) => !seen.includes(item.id))) {
                showToast(this.t('new_message', { box, who: remark.by, text: remark.text }));
                navigator.vibrate?.(200);
            }
        }

        try {
            window.localStorage.setItem(key, JSON.stringify(incoming.map(({ remark }) => remark.id)));
        } catch {
            // Without storage the message is simply announced again next time.
        }
    },

    myPresence() {
        return this.view.presences.find((presence) => presence.user_id === this.config.userId && presence.box_id === this.box.id)?.state ?? null;
    },

    get others() {
        return this.view.presences.filter((presence) => presence.user_id !== this.config.userId && presence.box_id === this.box.id);
    },

    async leave() {
        if (this.blocked || isFinal(this.box) || this.manager) {
            if (!this.blocked && !this.manager) {
                await this.requestPdf();
            }

            await this.exit();

            return;
        }

        this.sheet = { type: 'exit' };
    },

    async exitWith(status) {
        if (status === 'anomaly') {
            this.sheet = { type: 'anomaly', thenExit: true };

            return;
        }

        if (status === 'ok' && !(await this.checkOk())) {
            return;
        }

        await this.send({ op: 'box_status', status });
        await this.exit();
    },

    async exit() {
        if (this.engine.queue.length > 0 && navigator.onLine) {
            await this.engine.flush();
        }

        window.location.assign(this.config.urls.page);
    },

    confirm(message) {
        return new Promise((resolve) => {
            this.sheet = { type: 'confirm', message, resolve };
        });
    },

    answer(value) {
        const { resolve } = this.sheet ?? {};

        this.sheet = null;
        resolve?.(value);
    },

    closeSheet() {
        if (this.sheet?.type === 'confirm') {
            this.answer(false);

            return;
        }

        if (this.sheet?.type === 'local') {
            this.releaseLocal();
        }

        this.sheet = null;
    },

    /**
     * A conflict with another device opens the list at once so the person chooses which version stays.
     */
    refused(item) {
        if (this.isConflict(item)) {
            showToast(this.conflictText(item));
            this.sheet ??= { type: 'refused' };

            return;
        }

        showToast(item.message ?? this.error(item.reason));
    },

    async dismissRefused(id) {
        await this.engine.dismissRejected(id);
    },

    isConflict(item) {
        return item.reason === 'conflict';
    },

    conflictText(item) {
        const subject = item.op?.box ?? this.view?.points.find((point) => point.id === item.op?.point)?.name ?? '';

        return `✎ ${subject} — ${this.t('conflict', { who: item.conflict?.by ?? '?' })}`;
    },

    /**
     * What the person had entered, when it can be shown in a few words.
     */
    conflictMine(item) {
        const op = item.op ?? {};
        const value = op.text ?? (op.op === 'box_status' ? this.config.labels.statuses?.[op.status] : null) ?? (typeof op.value === 'string' ? op.value : null);

        return value ? `« ${value} »` : '';
    },

    async keepMine(item) {
        await this.engine.keepMine(item.id);
        this.closeRefusedWhenEmpty();
    },

    async keepTheirs(item) {
        await this.engine.dismissRejected(item.id);
        this.closeRefusedWhenEmpty();
    },

    closeRefusedWhenEmpty() {
        if (this.sheet?.type === 'refused' && this.engine.rejected.length === 0) {
            this.sheet = null;
        }
    },

    refusedFileUrl(item) {
        return item.blob ? URL.createObjectURL(item.blob) : null;
    },

    /* ---- photos kept on this phone: those already sent can be removed to free space, never the others ---- */

    get localPhotosText() { return `📱 ${this.t('local_photos')}`; },
    get localEmpty() { return this.local.length === 0; },
    get localUsageShown() { return this.localUsage !== null; },

    get localUsageText() {
        const locale = document.documentElement.lang || undefined;

        return this.localUsage === null ? '' : this.t('local_usage', { used: formatSize(this.localUsage.usage, locale), quota: formatSize(this.localUsage.quota, locale) });
    },

    get localTotalText() {
        const synced = this.local.reduce((sum, audit) => sum + audit.synced, 0);
        const size = this.local.reduce((sum, audit) => sum + audit.size, 0);

        return this.t('local_total', { count: synced, size: formatSize(size, document.documentElement.lang || undefined) });
    },

    async openLocalPhotos() {
        await this.loadLocal();
        this.sheet = { type: 'local' };
    },

    /**
     * Thumbnails are links to the photos kept on the phone; Alpine only holds those links, never the files.
     */
    async loadLocal() {
        const audits = await loadLocalPhotos(this.config.userId);

        this.local = audits.map((audit) => ({
            ...audit,
            subjects: audit.subjects.map((subject) => ({
                ...subject,
                photos: subject.photos.map(({ blob, ...photo }) => {
                    const url = blob ? urlOfBlob(photo.id, blob) : '';

                    return { ...photo, pending: !photo.deletable, thumb_url: url, full_url: url };
                }),
            })),
        }));
        this.localUsage = await storageUsage();
    },

    /**
     * The links of the copies are let go when the sheet closes (those of the photos waiting are still on the screen).
     */
    releaseLocal() {
        deletableIds(this.local, {}).forEach((id) => forgetBlob(id));
        this.local = [];
        this.localOpen = [];
    },

    localCountsText(group) {
        return this.t('local_counts', { synced: group.synced, waiting: group.waiting });
    },

    localDeleteText(group) {
        return `🗑 ${this.t('local_delete', { count: group.synced })}`;
    },

    isLocalOpen(subject) {
        return this.localOpen.includes(subject.key);
    },

    localToggleText(subject) {
        return this.isLocalOpen(subject) ? this.t('local_hide') : this.t('local_show');
    },

    toggleLocal(subject) {
        this.localOpen = this.isLocalOpen(subject) ? this.localOpen.filter((key) => key !== subject.key) : [...this.localOpen, subject.key];
    },

    async deleteLocalAudit(audit) {
        await this.deleteLocal({ cabinet: audit.cabinet });
    },

    async deleteLocalSubject(subject) {
        await this.deleteLocal({ cabinet: subject.cabinet, subject: subject.key });
    },

    async deleteLocalPhoto(photo) {
        await this.deleteLocal({ photo: photo.id });
    },

    /**
     * Removes copies from this phone only, after the person confirmed the number of files; nothing is sent to the
     * server, and what is still waiting to be sent is checked again and kept.
     */
    async deleteLocal(scope) {
        const ids = deletableIds(this.local, scope);

        if (ids.length === 0) {
            return;
        }

        const confirmed = await this.confirm(this.t('local_confirm', { count: ids.length }));

        if (confirmed) {
            const removed = await deleteLocalCopies(ids);

            showToast(this.t('local_deleted', { count: removed }));
        }

        await this.loadLocal();
        this.sheet = { type: 'local' };
    },

    /* ---- what the template shows (the CSP-safe Alpine build only understands simple expressions) ---- */

    get leaveText() { return `← ${this.t('leave')}`; },
    get leaveBoxText() { return `${this.t('leave_box')} →`; },
    get chipClass() { return `site-screen__chip--${this.chip.tone}`; },
    get chipText() { return this.chip.text; },
    get memoryText() { return this.t('memory_only'); },
    get authProblem() {
        this.touch();

        return this.engine?.problem === 'auth';
    },
    get authText() { return this.t('auth_needed'); },
    get updateProblem() {
        this.touch();

        return this.engine?.problem === 'update';
    },
    get updateText() { return this.error('update_required'); },
    get signInText() { return this.t('sign_in'); },
    get loginUrl() { return this.config.urls?.login; },
    get loadingText() { return this.t('loading'); },
    get offlineText() { return this.t('not_available_offline'); },
    get unavailableText() { return this.t('box_unavailable'); },
    get removeText() { return this.t('remove'); },
    get shotText() { return `📷 ${this.t('take_photo')}`; },
    get percentText() { return `${this.percent}%`; },
    get full() { return this.mode === 'full'; },
    get optical() { return this.mode === 'optical'; },
    get notInstalled() { return this.status === 'not_installed'; },
    get notInstalledText() { return `⊘ ${this.t('not_installed_banner')}`; },
    get capacities() { return this.view?.rules.capacities ?? []; },
    get supportOptions() { return ['chamber', 'pole', 'facade']; },
    get fibreOptions() { return ['g652', 'g657']; },
    get supportNumberValue() { return this.box.support_number ?? ''; },
    get validatedBanner() { return Boolean(this.view?.audit.validated); },
    get validatedText() { return `✅ ${this.t('audit_validated')}`; },
    get validatedMark() { return `✓ ${this.t('validated')}`; },
    get addCableText() { return `＋ ${this.t('add_cable')}`; },
    get acknowledgeText() { return `✓ ${this.t('acknowledge')}`; },
    get goingText() { return `🚶 ${this.t('going')}`; },
    get arrivingText() { return `📍 ${this.t('arriving')}`; },
    get positionTitle() { return `📍 ${this.t('position')}`; },
    get refused() {
        this.touch();

        return this.engine?.rejected ?? [];
    },
    get sheetOpen() { return this.sheet !== null; },
    get sheetMessage() { return this.sheet?.message ?? ''; },
    get zoomUrl() { return this.zoom?.full_url ?? ''; },

    get subtitle() {
        const kind = this.config.labels.kinds[this.box.kind];

        return [kind, this.box.type, this.box.address].filter(Boolean).join(' · ');
    },

    get returnedText() {
        const returned = this.box.returned;

        return returned ? `↩ ${this.t('returned_by', { who: returned.by, comment: returned.comment })}` : '';
    },

    get positionText() {
        const confirmed = this.box.confirmed;

        return confirmed ? `✓ ${this.t('position_confirmed', { who: confirmed.by, accuracy: confirmed.accuracy ?? '?' })}` : this.t('position_hint');
    },

    get positionButtonText() {
        return this.box.confirmed ? this.t('position_again') : this.t('position_confirm');
    },

    get reasonShown() {
        return this.status === 'anomaly' && Boolean(this.box.reason);
    },

    get reasonText() {
        return `${this.t('reason')} : ${this.box.reason}`;
    },

    isSheet(type) {
        return this.sheet?.type === type;
    },

    openRefused() {
        if (this.refusedCount > 0) {
            this.sheet = { type: 'refused' };
        }
    },

    openZoom(photo) {
        this.zoom = photo;
    },

    closeZoom() {
        this.zoom = null;
    },

    segmentClass(current, option) {
        return current === option ? 'is-active' : '';
    },

    supportLabel(option) {
        return this.config.labels.supports[option];
    },

    fibreLabel(option) {
        return this.config.labels.fibres[option];
    },

    statusLabel(option) {
        return this.config.labels.statuses[option];
    },

    statusIcon(option) {
        return { ok: '✅', anomaly: '⚠️', not_installed: '⊘', partial: '⏸' }[option];
    },

    statusClass(option) {
        return `site-screen__status--${option}${this.status === option ? ' is-active' : ''}`;
    },

    presenceClass(state) {
        return this.myPresence() === state ? 'is-active' : '';
    },

    whoClass(who) {
        return `audit__who--${who.state}`;
    },

    whoText(who) {
        return `${who.state === 'on_box' ? '📍' : '🚶'} ${who.name}`;
    },

    folderClass(folder) {
        return `${folder.done ? 'is-done' : ''} ${this.dropClass(folder.key, '')}`;
    },

    tickClass(folder) {
        return folder.done ? 'is-on' : '';
    },

    photoClass(photo) {
        return `${photo.pending ? 'is-pending' : ''}${this.isSelected(photo) ? ' is-selected' : ''}`;
    },

    selectMark(photo) {
        return this.isSelected(photo) ? '☑' : '☐';
    },

    cableClass(cable) {
        return this.cableOk(cable) ? 'is-complete' : '';
    },

    cableTitle(cable, index) {
        return `${this.cableOk(cable) ? '✓ ' : ''}${this.t('cable_n', { n: index + 1 })}`;
    },

    cableNote(cable) {
        return cable.note ?? '';
    },

    refusedText(item) {
        return this.isConflict(item) ? this.conflictText(item) : `${{ photo: '📷', flow: '📤' }[item.type] ?? '✎'} ${item.message ?? this.error(item.reason)}`;
    },

    messageKey(message) {
        return message.id ?? message.at;
    },

    bubbleClass(message) {
        const mine = message.side === 'tech' ? 'site-screen__bubble--mine' : 'site-screen__bubble--office';

        return message.side === 'office' && !message.acknowledged ? `${mine} is-unanswered` : mine;
    },

    bubbleHeader(message) {
        const when = new Date(message.at).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' });

        return `${message.side === 'office' ? '📣' : '🛠'} ${message.by} · ${when}`;
    },

    canAcknowledge(message) {
        return message.side === 'office' && !message.acknowledged && !this.manager;
    },

    ackText(message) {
        return `✓ ${this.t('acknowledged_by', { who: message.acknowledged?.by })}`;
    },

    async onSupportNumber(event) {
        await this.setSupportNumber(event.target.value);
    },

    async onCableNoLabel(cable, event) {
        await this.updateCable(cable.code, 'has_no_label', event.target.checked);
    },

    async onCableNote(cable, event) {
        await this.updateCable(cable.code, 'note', event.target.value.trim());
    },

    async onCableCapacity(cable, event) {
        await this.updateCable(cable.code, 'capacity', event.target.value);
    },

    async toggleCableFibre(cable, option) {
        await this.updateCable(cable.code, 'fibre_type', cable.fibre_type === option ? '' : option);
    },

    async takeCablePhoto(folder, cable) {
        await this.takePhoto(folder, cable.code);
    },

    /* ---- management (managers work online, on what the phone already shows) ---- */

    get editText() { return `✎ ${this.t('edit')}`; },
    get finishEditText() { return `✓ ${this.t('finish_editing')}`; },
    get selectText() { return `☑ ${this.t('select_photos')}`; },
    get leaveSelectionText() { return `✕ ${this.t('leave_selection')}`; },
    get selectedText() { return this.t('n_selected', { count: this.selected.length }); },
    get returnText() { return `↩ ${this.t('return_box')}`; },
    get removeBoxText() { return `🗑 ${this.t('remove_box')}`; },
    get importText() { return `📥 ${this.t('import_photos')}`; },
    get sendLabel() { return this.manager ? this.t('send_to_technician') : this.t('send'); },
    get messagePlaceholder() { return this.manager ? this.t('report_placeholder') : this.t('message_placeholder'); },
    get canEditMode() { return this.manager && !this.editing && !this.locked; },
    get canSelect() { return this.manager && this.editing && !this.selecting; },
    get showMoveBar() { return this.manager && this.selecting; },
    get noneSelected() { return this.selected.length === 0; },

    get moveTargets() {
        const targets = [];

        for (const folder of this.folders) {
            if (folder.key === 'pole') {
                folder.sections.forEach((section) => targets.push({ value: `pole|${section.key}`, label: `${folder.title} — ${section.title}` }));
            } else {
                targets.push({ value: `${folder.key}|`, label: folder.title });
            }
        }

        return targets;
    },

    startEditing() {
        this.editing = true;
    },

    async stopEditing() {
        this.editing = false;
        this.leaveSelection();
        await this.requestPdf();
    },

    /**
     * The PDF is written by the server once the changes sent before it are saved.
     */
    async requestPdf() {
        await this.send({ op: 'box_pdf' });
    },

    async askPdf() {
        await this.requestPdf();
        showToast(this.t('pdf_requested'));
    },

    get pdf() {
        return this.box?.pdf ?? null;
    },

    /* ---- recheck against the plan (optical mode, box with an Excel plan) ---- */

    get recheck() {
        return this.box?.recheck ?? null;
    },

    /**
     * A manager editing the box opens the recheck in edit mode too (a validated audit is read-only otherwise).
     */
    get recheckUrl() {
        const url = this.recheck?.url ?? '';

        return url !== '' && this.manager && this.editing ? `${url}?edit=1` : url;
    },

    get recheckText() {
        return `🔧 ${this.t('recheck')}${this.recheck?.conform ? ' ✓' : ''}`;
    },

    get recheckPaperShown() {
        return this.recheck !== null && (this.box.recheck_state === 'nonconforming' || this.recheckPhotos.length > 0);
    },

    get recheckPhotos() {
        return photosOf(this.box, 'recheck');
    },


    get pdfUrl() {
        return this.pdf?.url ?? '';
    },

    get pdfCreateText() { return `📄 ${this.t('pdf_create')}`; },
    get pdfOpenText() { return `📄 ${this.t('pdf_open')}`; },

    toggleSelecting() {
        this.selecting = true;
        this.selected = [];
    },

    leaveSelection() {
        this.selecting = false;
        this.selected = [];
        this.moveTarget = '';
    },

    clearSelection() {
        this.selected = [];
    },

    isSelected(photo) {
        return this.selected.includes(photo.id);
    },

    photoClick(photo) {
        if (!this.selecting) {
            this.zoom = photo;

            return;
        }

        if (photo.pending) {
            return;
        }

        this.selected = this.isSelected(photo) ? this.selected.filter((id) => id !== photo.id) : [...this.selected, photo.id];
    },

    async online(operation) {
        const result = await this.engine.sendNow({ ...operation, box: this.box.name });

        if (!result.ok) {
            showToast(result.error === 'offline' ? this.t('online_required') : this.error(result.error));
        }

        return result.ok;
    },

    async movePhotos() {
        if (this.selected.length === 0 || this.moveTarget === '') {
            return;
        }

        const [folder, subfolder] = this.moveTarget.split('|');

        if (await this.online({ op: 'photo_move', folder, subfolder, photos: this.selected })) {
            showToast(this.t('moved', { count: this.selected.length }));
            this.leaveSelection();
        }
    },

    /* ---- drag & drop of photos to another folder (desktop) ---- */

    canDrag(photo) {
        return this.manager && !photo.pending && !this.view?.audit.validated;
    },

    dragPhoto(photo, event) {
        this.dragged = this.selecting && this.isSelected(photo) ? [...this.selected] : [photo.id];
        event.dataTransfer.setData('text/plain', 'photo');
        event.dataTransfer.effectAllowed = 'move';
    },

    dragOver(folder, subfolder, event) {
        if (this.dragged.length === 0) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        this.dropTarget = `${folder}|${subfolder}`;
    },

    dropClass(folder, subfolder) {
        return this.dropTarget === `${folder}|${subfolder}` ? 'is-drop-target' : '';
    },

    async dropPhotos(folder, subfolder, event) {
        if (this.dragged.length === 0) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const photos = this.dragged;

        this.dragEnd();

        if (await this.online({ op: 'photo_move', folder, subfolder, photos })) {
            showToast(this.t('moved', { count: photos.length }));
            this.leaveSelection();
        }
    },

    dragEnd() {
        this.dragged = [];
        this.dropTarget = '';
    },

    async validateFolder(folder) {
        await this.online({ op: 'folder_validate', folder: folder.key, valid: !folder.validated });
    },

    validateFolderText(folder) {
        return folder.validated ? `✓ ${this.t('validated')}` : this.t('validate_folder');
    },

    showValidate(folder) {
        return this.manager && folder.realPhotos > 0;
    },

    showImport(folder) {
        return this.manager && this.editing && folder.key !== 'pole';
    },

    async importPhotos(event, folder) {
        const files = [...event.target.files];

        event.target.value = '';

        for (const file of files) {
            const blob = await this.shrink(file);

            if (blob) {
                await this.engine.enqueuePhoto({ box: this.box.name, folder: folder.key, subfolder: '', captured_at: new Date(file.lastModified).toISOString(), imported: 1 }, blob);
            }
        }
    },

    /**
     * An imported photo is reduced to 2200 px and re-encoded, so it weighs like the ones taken on site.
     */
    async shrink(file) {
        return downscaleImage(file);
    },

    askReturn() {
        this.drafts.returnComment = '';
        this.sheet = { type: 'return' };
    },

    async confirmReturn() {
        const comment = (this.drafts.returnComment ?? '').trim();

        if (comment === '') {
            showToast(this.error('comment_required'));

            return;
        }

        if (await this.online({ op: 'box_return', comment })) {
            this.sheet = null;
            showToast(this.t('returned'));
        }
    },

    async removeBox() {
        if (!(await this.confirm(this.t('confirm_remove_box')))) {
            return;
        }

        if (await this.online({ op: 'box_admin', removed: true })) {
            window.location.assign(this.config.urls.page);
        }
    },

    /* ---- report (compte rendu): the articles done, picked from the catalogue ---- */

    get catalog() {
        return this.view?.catalog ?? { chamber: [], pole: [], cabinet: [] };
    },

    /**
     * Articles that can be picked for what the screen is about: by support for a box, the cabinet list for the cabinet.
     */
    get reportList() {
        const support = this.box?.support ?? null;

        if (support === null) {
            return [];
        }

        return support === 'chamber' ? this.catalog.chamber : this.catalog.pole;
    },

    get reportSaved() {
        return this.box?.report_items ?? [];
    },

    get reportRequired() {
        return this.full && this.reportList.length > 0;
    },

    get reportShown() {
        return this.reportList.length > 0 || this.reportSaved.length > 0;
    },

    get reportButtonText() {
        return `📋 ${this.t('report')}${this.reportRequired ? ' *' : ''} (${this.reportSaved.length})`;
    },

    get reportOpen() {
        return this.sheet?.type === 'report';
    },

    get reportTitle() {
        return `${this.reportSubject} — ${this.t('report')}`;
    },

    get reportSubject() {
        return this.box?.name ?? '';
    },

    get reportRows() {
        const needle = this.reportSearch.trim().toLowerCase();
        const listed = this.reportCatalog.map((item) => ({ ...item, unlisted: false }));
        const outside = this.reportDraft.filter((item) => !this.reportCatalog.some((entry) => entry.id === item.id)).map((item) => ({ id: item.id, label: item.label, unlisted: true }));

        return [...listed, ...outside].filter((row) => needle === '' || row.label.toLowerCase().includes(needle));
    },

    get reportEmpty() {
        return this.reportRows.length === 0;
    },

    get reportCountText() {
        return this.t('report_count', { count: this.reportDraft.length });
    },

    get reportSearchPlaceholder() {
        return this.t('search');
    },

    openReport() {
        if (this.blocked) {
            return;
        }

        this.reportCatalog = this.reportListFor();
        this.reportDraft = this.reportSavedFor().map((item) => ({ ...item }));
        this.reportSearch = '';
        this.sheet = { type: 'report' };
    },

    reportListFor() {
        return this.reportList;
    },

    reportSavedFor() {
        return this.reportSaved;
    },

    isPicked(row) {
        return this.reportDraft.some((item) => item.id === row.id);
    },

    pickClass(row) {
        return this.isPicked(row) ? 'is-picked' : '';
    },

    pickMark(row) {
        return this.isPicked(row) ? '✓' : '';
    },

    rowLabel(row) {
        return row.unlisted ? `${row.label} ${this.t('unlisted')}` : row.label;
    },

    togglePick(row) {
        if (this.isPicked(row)) {
            this.reportDraft = this.reportDraft.filter((item) => item.id !== row.id);

            return;
        }

        this.reportDraft = [...this.reportDraft, { id: row.id, label: row.label, qty: 1 }];
    },

    /**
     * The articles are saved in the order of the catalogue, those that left it last.
     */
    async saveReport() {
        const order = this.reportCatalog.map((item) => item.id);
        const items = this.reportDraft.map(({ id, label, qty }) => ({ id, label, qty })).sort((a, b) => {
            const first = order.indexOf(a.id);
            const second = order.indexOf(b.id);

            return (first === -1 ? 9999 : first) - (second === -1 ? 9999 : second);
        });

        this.sheet = null;
        await this.sendReport(items);
        showToast(this.t('report_saved'));
    },

    async sendReport(items) {
        await this.send({ op: 'box_cr', items });
    },

    statuses: STATUSES,
});

export default () => compose(boxScreen(), boxWorkflow(), boxWorkflowActions());
