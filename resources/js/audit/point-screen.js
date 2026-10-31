import { openBurstCamera } from '../components/burst-camera';
import boxScreen from './box-screen';

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
 * The page of a control point (the cabinet itself, a chamber…): photos by sub-folder, a note and, for the cabinet,
 * its report. It reuses what the box page does for storage, synchronisation, camera and confirmation sheets.
 */
const pointMembers = {
    pointId: 0,

    locate() {
        this.pointId = Number(new URLSearchParams(window.location.search).get('id') ?? 0);
    },

    loadDrafts() {
        this.drafts.note = this.point?.note ?? '';
    },

    startWatch() {},

    get point() {
        return this.view?.points.find((candidate) => candidate.id === this.pointId) ?? null;
    },

    // Nothing of a box applies here: the shared getters that read the box see nothing.
    get box() {
        return null;
    },

    get blocked() {
        return this.locked;
    },

    get clientNotes() {
        return (this.view?.client_notes ?? []).filter((note) => this.point !== null && note.point_id === this.point.id);
    },

    // Photos of a control point are never moved to another folder.
    canDrag() {
        return false;
    },

    get subfolders() {
        return this.point.subfolders.length > 0 ? this.point.subfolders : [''];
    },

    get sections() {
        return this.subfolders.map((sub) => ({ key: sub, title: sub === '' ? this.point.name : this.config.labels.poleSections[sub], photos: this.point.photos.filter((photo) => photo.subfolder === sub) }));
    },

    get pointTitle() {
        return this.point?.name ?? '';
    },

    get isCabinet() {
        return this.point?.kind === 'cabinet';
    },

    // On the cabinet itself, the directions lead to the cabinet and its drawer plan can be rechecked.
    get navigationTarget() {
        return this.isCabinet ? (this.view?.audit?.cabinet_position ?? null) : null;
    },

    get navigationName() {
        return this.point?.name ?? '';
    },

    get drawerRecheckUrl() {
        return this.isCabinet ? (this.view?.audit?.drawer?.recheck_url ?? null) : null;
    },

    get drawerAfterWorksUrl() {
        return this.isCabinet ? (this.view?.audit?.drawer?.after_works_url ?? null) : null;
    },

    get drawerTools() {
        return this.drawerRecheckUrl !== null || this.drawerAfterWorksUrl !== null;
    },

    get drawerRecheckText() {
        return `🔧 ${this.t('drawer_recheck')}`;
    },

    get drawerAfterWorksText() {
        return `📥 ${this.t('drawer_after_works')}`;
    },

    get reportList() {
        return this.point?.kind === 'cabinet' ? this.catalog.cabinet : [];
    },

    get reportSaved() {
        return this.point?.report_items ?? [];
    },

    get reportRequired() {
        return this.reportList.length > 0;
    },

    get reportSubject() {
        return this.point?.name ?? '';
    },

    async send(operation) {
        await this.engine.enqueueOperation({ ...operation, point: this.point.id });
    },

    async sendReport(items) {
        await this.send({ op: 'cp_cr', items });
    },

    async saveNote() {
        const value = (this.drafts.note ?? '').trim();

        if (!this.blocked && (this.point.note ?? '') !== value) {
            await this.send({ op: 'cp_note', text: value });
        }
    },

    async takePhoto(subfolder) {
        if (this.blocked) {
            return;
        }

        const result = await openBurstCamera({
            labels: { ...this.config.camera, reverse_url: this.config.urls.reverse },
            name: this.point.name,
            timeZone: this.config.timeZone,
            allowWithoutLocation: this.manager,
            confirmDiscard: (message) => this.confirm(message),
        });

        for (const shot of result?.shots ?? []) {
            await this.engine.enqueuePhoto({ point: this.point.id, subfolder, captured_at: new Date().toISOString(), latitude: shot.lat, longitude: shot.lng, geo_missing_reason: shot.reason }, shot.blob);
        }
    },

    get pdf() {
        return this.point?.pdf ?? null;
    },

    async requestPdf() {
        await this.send({ op: 'cp_pdf' });
    },

    async leave() {
        if (!this.blocked && !this.manager && (this.point.photos.length > 0 || (this.point.note ?? '') !== '')) {
            await this.requestPdf();
        }

        await this.exit();
    },
};

export default () => compose(boxScreen(), pointMembers);
