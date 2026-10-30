/**
 * What the screen shows: the audit as the server last sent it, with everything still waiting in the queue
 * applied on top, in order. The server remains the judge: this replay only has to look right until it answers.
 */
const normaliseName = (name) => String(name ?? '').replace(/\s+/g, ' ').trim().toUpperCase();

const clone = (value) => (typeof structuredClone === 'function' ? structuredClone(value) : JSON.parse(JSON.stringify(value)));

const blobUrls = new Map();

export const urlOfBlob = (id, blob) => {
    if (!blobUrls.has(id)) {
        blobUrls.set(id, URL.createObjectURL(blob));
    }

    return blobUrls.get(id);
};

export const forgetBlob = (id) => {
    if (blobUrls.has(id)) {
        URL.revokeObjectURL(blobUrls.get(id));
        blobUrls.delete(id);
    }
};

const findBox = (view, name) => view.boxes.find((box) => normaliseName(box.name) === normaliseName(name));

const applyOperation = (view, operation, viewer, createdAt) => {
    const box = findBox(view, operation.box);

    switch (operation.op) {
        case 'box_set': {
            if (!box) {
                break;
            }

            if (operation.field === 'cables') {
                box.cables = (operation.value ?? []).map((cable) => ({ code: cable.code, has_no_label: Boolean(cable.has_no_label), capacity: cable.capacity || null, fibre_type: cable.fibre_type || null, note: cable.note || null }));
            } else if (['fibre_type', 'support', 'support_number', 'recheck_state'].includes(operation.field)) {
                box[operation.field] = operation.value === '' ? null : operation.value;
            }

            break;
        }
        case 'box_note':
            if (box && ['route', 'before', 'tco'].includes(operation.key)) {
                box.notes[operation.key] = operation.text || null;
            }

            break;
        case 'box_status':
            if (box) {
                box.status = operation.status === '' ? null : operation.status;
                box.reason = operation.status === 'anomaly' ? operation.reason : null;
                box.returned = null;
            }

            break;
        case 'box_pos':
            if (box) {
                box.confirmed = { latitude: operation.latitude, longitude: operation.longitude, accuracy: operation.accuracy ?? null, by: viewer.name, at: createdAt };
            }

            break;
        case 'tech_message':
            if (box && !box.remarks.some((remark) => remark.id === operation.id)) {
                box.remarks.push({ id: operation.id, side: 'tech', text: operation.text, by: viewer.name, at: createdAt, acknowledged: null, pending: true });
            }

            break;
        case 'remark_ack':
            box?.remarks.filter((remark) => remark.id === operation.id && !remark.acknowledged).forEach((remark) => {
                remark.acknowledged = { by: viewer.name, at: createdAt };
            });

            break;
        case 'box_cr':
            if (box) {
                box.report_items = operation.items ?? [];
            }

            break;
        case 'cp_note':
        case 'cp_cr': {
            const point = view.points.find((candidate) => candidate.id === Number(operation.point));

            if (point && operation.op === 'cp_note') {
                point.note = operation.text || null;
            } else if (point) {
                point.report_items = operation.items ?? [];
            }

            break;
        }
        case 'photo_del':
            [...view.boxes, ...view.points].forEach((candidate) => {
                candidate.photos = candidate.photos.filter((photo) => photo.id !== operation.photo);
            });

            break;
        default:
            break;
    }
};

/**
 * @param {object} snapshot Last audit received from the server.
 * @param {Array} queue Queue items, oldest first.
 * @param {{id: number, name: string}} viewer
 */
export function buildView(snapshot, queue, viewer) {
    const view = clone(snapshot);
    const queued = new Set();

    for (const item of queue) {
        const at = new Date(item.created_at).toISOString();

        if (item.type === 'op') {
            applyOperation(view, item.op, viewer, at);

            continue;
        }

        if (item.type !== 'photo') {
            continue;
        }

        const box = findBox(view, item.meta.box);
        const target = box ?? view.points.find((point) => point.id === Number(item.meta.point));

        if (target) {
            const url = urlOfBlob(item.id, item.blob);
            queued.add(item.id);
            target.photos.push({ id: `pending:${item.id}`, pending: true, folder: item.meta.folder ?? 'point', subfolder: item.meta.subfolder ?? '', captured_at: item.meta.captured_at, user_id: viewer.id, user_name: viewer.name, thumb_url: url, full_url: url });
        }
    }

    return view;
}
