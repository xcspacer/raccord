import { afterEach, describe, expect, it } from 'vitest';
import { isInTeam, isResponsible, justificationsFor, missingFolders, queuedFlows, threadOf, unseenDecision, unseenMessages } from '../../resources/js/audit/box-workflow';
import { occurrenceActions, waitingOccurrences } from '../../resources/js/audit/box-workflow-actions';
import { outcomeOf, resolvedBody, SyncEngine, withPhotoId } from '../../resources/js/audit/sync';
import { elementsOf, MAX_POINTS, shapeFrom, thinPoints, toFraction } from '../../resources/js/design-office-workflow/photo-markup';

const flow = (action, box, body = {}, extra = {}) => ({ id: `o_${action}${box}`, type: 'flow', created_at: Date.UTC(2026, 9, 30, 10), flow: { action, box, url: `/design-office/boxes/${box}/${action}`, body, ...extra } });
const photo = (box, folder) => ({ id: `o_p${folder}`, type: 'photo', meta: { box, folder } });

describe('what the server answer means for a queued item', () => {
    it('keeps the behaviour of the edits and photos', () => {
        expect(outcomeOf('op', 200, null)).toBe('done');
        expect(outcomeOf('op', 409, 'conflict')).toBe('conflict');
        expect(outcomeOf('op', 409, 'already')).toBe('done');
        expect(outcomeOf('op', 409, 'validated')).toBe('reject');
        expect(outcomeOf('photo', 422, 'invalid')).toBe('reject');
        expect(outcomeOf('op', 419, null)).toBe('auth');
        expect(outcomeOf('photo', 503, null)).toBe('retry');
        expect(outcomeOf('op', 429, null)).toBe('retry');
    });

    it('never drops a refused design office action silently', () => {
        expect(outcomeOf('flow', 201, null)).toBe('done');
        expect(outcomeOf('flow', 409, 'blocked_by_pending')).toBe('reject');
        expect(outcomeOf('flow', 409, 'conflict')).toBe('reject');
        expect(outcomeOf('flow', 422, 'missing_proofs')).toBe('reject');
        expect(outcomeOf('flow', 403, 'not_responsible')).toBe('reject');
        expect(outcomeOf('flow', 500, null)).toBe('retry');
        expect(outcomeOf('flow', 401, null)).toBe('auth');
    });
});

describe('design office actions in the offline queue', () => {
    afterEach(() => {
        delete globalThis.document;
    });

    it('sends each action to its own address with the queue id as operation id and the time it was made', () => {
        globalThis.document = { cookie: 'XSRF-TOKEN=abc' };
        const engine = new SyncEngine({ cabinet: 'PM1', urls: { operations: '/ops', photos: '/photos' }, userId: 7 });
        const item = { ...flow('submit', 3, { justifications: { before: 'No access to the chamber' } }), waited: true };

        expect(engine.urlOf(item)).toBe('/design-office/boxes/3/submit');
        expect(engine.urlOf({ type: 'op' })).toBe('/ops');
        expect(engine.urlOf({ type: 'photo' })).toBe('/photos');

        const request = engine.request(item);
        const body = JSON.parse(request.body);

        expect(request.method).toBe('POST');
        expect(request.headers['X-XSRF-TOKEN']).toBe('abc');
        expect(body).toEqual({ justifications: { before: 'No access to the chamber' }, operation_id: 'o_submit3', client_created_at: '2026-10-30T10:00:00.000Z', queued: true });
    });

    it('finds the actions of one box', () => {
        const queue = [flow('claim', 3), photo('BPE-3', 'labels'), flow('submit', 4), flow('submit', 3)];

        expect(queuedFlows(queue, 3).map((item) => item.flow.action)).toEqual(['claim', 'submit']);
    });
});

describe('submitting a box', () => {
    const status = { box: { id: 3 }, missing_folders: [{ folder: 'before', label: 'Before' }, { folder: 'labels', label: 'Labels' }, { folder: 'tco', label: 'TCO' }] };

    it('asks no justification for a folder whose photo waits on the phone', () => {
        const missing = missingFolders(status, [photo('bpe-3 ', 'labels'), photo('BPE-4', 'tco')], 'BPE-3');

        expect(missing.map((folder) => [folder.folder, folder.waiting])).toEqual([['before', false], ['labels', true], ['tco', false]]);
    });

    it('needs a written justification of 5 characters at least for every other missing folder', () => {
        const missing = missingFolders(status, [photo('BPE-3', 'labels')], 'BPE-3');

        expect(justificationsFor(missing, { before: '  No access  ', tco: 'no' })).toEqual({ justifications: { before: 'No access' }, lacking: ['TCO'] });
        expect(justificationsFor(missing, { before: 'No access', tco: 'Locked cabinet' }).lacking).toEqual([]);
    });

    it('knows nothing is missing before the server said so', () => {
        expect(missingFolders(null, [], 'BPE-3')).toEqual([]);
    });
});

describe('who works on the box', () => {
    it('counts a start waiting on the phone when nobody started the box yet', () => {
        const free = { box: { id: 3, responsible: null, is_responsible: false, participants: [] } };
        const taken = { box: { id: 3, responsible: { id: 9 }, is_responsible: false, participants: [] } };

        expect(isResponsible(free, [flow('claim', 3)])).toBe(true);
        expect(isResponsible(taken, [flow('claim', 3)])).toBe(false);
        expect(isResponsible({ box: { is_responsible: true } }, [])).toBe(true);
        expect(isResponsible(null, [])).toBe(false);
    });

    it('treats a partner or a start waiting as part of the team', () => {
        const status = { box: { id: 3, is_responsible: false, participants: [{ id: 7 }] } };

        expect(isInTeam(status, [], 7)).toBe(true);
        expect(isInTeam(status, [], 8)).toBe(false);
        expect(isInTeam(null, [flow('claim', 3)], 8)).toBe(true);
    });
});

describe('the chat of the box', () => {
    it('shows the messages of the server, then those still waiting on the phone', () => {
        const status = { messages: [{ id: 1, body: 'Measure again', side: 'office', kind: 'control_request', is_blocking: true }] };
        const queue = [flow('message', 3, { body: 'Done', kind: 'chat', photo_id: null }), flow('message', 4, { body: 'Other box' })];
        const thread = threadOf(status, queue, 3, 'Alex');

        expect(thread.map((message) => [message.key, message.body, message.pending])).toEqual([['m1', 'Measure again', false], ['qo_message3', 'Done', true]]);
        expect(thread[1]).toMatchObject({ by: 'Alex', side: 'team', is_blocking: false, at: '2026-10-30T10:00:00.000Z' });
    });

    it('marks an observation written offline by the design office as blocking', () => {
        const thread = threadOf(null, [flow('message', 3, { body: 'Label', kind: 'observation', photo_id: 5 }, { side: 'office' })], 3, 'Sam');

        expect(thread[0]).toMatchObject({ is_blocking: true, photo_id: 5, side: 'office' });
    });
});

describe('telling the technician', () => {
    it('announces a decision once', () => {
        const status = { decision: { decided_at: '2026-10-30T10:03:00Z', label: 'Refuse' } };

        expect(unseenDecision(status, null)).toBe(status.decision);
        expect(unseenDecision(status, '2026-10-30T10:03:00Z')).toBeNull();
        expect(unseenDecision({ decision: null }, null)).toBeNull();
    });

    it('announces only the new messages of the design office', () => {
        const status = { messages: [{ id: 1, side: 'office', is_mine: false }, { id: 2, side: 'team', is_mine: false }, { id: 3, side: 'office', is_mine: false }, { id: 4, side: 'office', is_mine: true }] };

        expect(unseenMessages(status, [1]).map((message) => message.id)).toEqual([3]);
    });
});

describe('drawing over a photo', () => {
    it('places the pointer as fractions of the photo, inside it', () => {
        const rect = { left: 100, top: 50, width: 400, height: 200 };

        expect(toFraction(rect, 300, 150)).toEqual([0.5, 0.5]);
        expect(toFraction(rect, 50, 400)).toEqual([0, 1]);
    });

    it('keeps at most the allowed number of points of a free line, ends included', () => {
        const points = Array.from({ length: 1000 }, (_, index) => [index / 1000, 0]);
        const thinned = thinPoints(points);

        expect(thinned).toHaveLength(MAX_POINTS);
        expect(thinned[0]).toEqual([0, 0]);
        expect(thinned[MAX_POINTS - 1]).toEqual([0.999, 0]);
        expect(thinPoints(points.slice(0, 3))).toHaveLength(3);
    });

    it('drops shapes too small to mean anything', () => {
        expect(shapeFrom({ type: 'circle', colour: 'red', x1: 0.5, y1: 0.5, x2: 0.501, y2: 0.502 })).toBeNull();
        expect(shapeFrom({ type: 'arrow', colour: 'red', x1: 0.1, y1: 0.1, x2: 0.3, y2: 0.3 })).not.toBeNull();
        expect(shapeFrom({ type: 'pen', colour: 'red', points: [[0.1, 0.1]] })).toBeNull();
        expect(shapeFrom({ type: 'text', colour: 'red', x: 0.1, y: 0.1, text: '  ' })).toBeNull();
        expect(shapeFrom({ type: 'text', colour: 'red', x: 0.1, y: 0.1, text: ' Splice ' }).text).toBe('Splice');
    });

    it('draws each shape in pixels of the photo with its colour class', () => {
        const [ellipse] = elementsOf({ type: 'circle', colour: 'red', x1: 0.1, y1: 0.2, x2: 0.3, y2: 0.6 }, 1000, 500);

        expect(ellipse.tag).toBe('ellipse');
        expect(ellipse.attrs).toMatchObject({ cx: 200, cy: 200, rx: 100, ry: 100, class: 'photo-markup__shape photo-markup__shape--red' });
        expect(elementsOf({ type: 'arrow', colour: 'blue', x1: 0, y1: 0, x2: 1, y2: 1 }, 1000, 500).map((element) => element.tag)).toEqual(['line', 'line', 'line']);
        expect(elementsOf({ type: 'pen', colour: 'green', points: [[0, 0], [0.5, 0.5]] }, 1000, 500)[0].attrs.points).toBe('0,0 500,250');
        expect(elementsOf({ type: 'text', colour: 'yellow', x: 0.5, y: 0.5, text: 'Here' }, 1000, 500)[0]).toMatchObject({ tag: 'text', text: 'Here' });
        expect(elementsOf({ type: 'star' }, 10, 10)).toEqual([]);
    });
});

describe('photos named by a queued design office action', () => {
    it('gets the server id of a photo once it is received, and leaves out those never received', () => {
        const body = { anomaly_type_id: 3, before_photo_ids: [12, 'pending:o_a', 'pending:o_b'] };
        const received = withPhotoId(body, 'o_a', 41);

        expect(received.before_photo_ids).toEqual([12, 41, 'pending:o_b']);
        expect(withPhotoId(received, 'o_z', 99)).toBe(received);
        expect(resolvedBody(received).before_photo_ids).toEqual([12, 41]);
        expect(resolvedBody({ photo_id: 'pending:o_c', role: 'after' })).toEqual({ photo_id: null, role: 'after' });
        expect(withPhotoId({ text: 'wrong cable', photo: 'pending:o_d' }, 'o_d', 7)).toEqual({ text: 'wrong cable', photo: 7 });
    });

    it('rewrites the waiting actions when the photo they name reaches the server', async () => {
        const engine = new SyncEngine({ cabinet: 'PM1', urls: {}, userId: 7 });
        engine.queue = [{ ...flow('occurrence_create', 3, { before_photo_ids: ['pending:o_p'] }) }];
        globalThis.indexedDB = undefined;

        await engine.photoReceived('o_p', 55);

        expect(engine.queue[0].flow.body.before_photo_ids).toEqual([55]);
        delete globalThis.indexedDB;
    });
});

describe('occurrences on the box screen', () => {
    const occurrence = (extra = {}) => ({ id: 1, nature: 'optical', state: 'detected', is_closed: false, is_inherited: false, boxes: [{ box_id: 3, state: 'origin' }], ...extra });
    const context = { field: true, phase: 1, inIntervention: false, boxId: 3 };

    it('offers what the rules of the occurrence allow', () => {
        expect(occurrenceActions(occurrence(), context)).toEqual({ hold: true, correct: true, link: true, propose: true, result: false });
        expect(occurrenceActions(occurrence({ nature: 'mechanical', state: 'to_repair' }), context).correct).toBe(false);
        expect(occurrenceActions(occurrence({ nature: 'mechanical', state: 'to_repair' }), { ...context, phase: 2, inIntervention: true }).correct).toBe(true);
        expect(occurrenceActions(occurrence({ is_inherited: true, boxes: [{ box_id: 3, state: 'confirmed' }] }), context).result).toBe(true);
        expect(occurrenceActions(occurrence({ is_closed: true }), context).link).toBe(false);
        expect(occurrenceActions(occurrence(), { ...context, field: false }).link).toBe(false);
    });

    it('shows the occurrences declared on the phone and not sent yet', () => {
        const queued = [{ ...flow('occurrence_create', 3, { description: 'tray broken' }), flow: { action: 'occurrence_create', box: 3, body: { description: 'tray broken' }, label: 'Tray' } }];

        expect(waitingOccurrences(queued)).toEqual([{ key: 'qo_occurrence_create3', type: 'Tray', description: 'tray broken' }]);
    });
});
