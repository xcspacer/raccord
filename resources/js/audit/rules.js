/**
 * Which photo folders a box needs and how far it is done. This mirrors App\Audit\BoxFolders and
 * App\Audit\BoxProgress on the server (a test runs both on the same boxes), because the phone has to compute the
 * percentage without a network.
 */
export const photosOf = (box, folder, subfolder) => (box.photos ?? []).filter((photo) => photo.folder === folder && (subfolder === undefined || photo.subfolder === subfolder));

export function requiredFolders(box, mode, hasTcoPhotos = false) {
    const support = box.support ?? null;
    const folders = [];

    if (mode === 'optical') {
        if (support === 'chamber') {
            folders.push('surroundings', 'interior');
        }

        if (box.kind === 'pbo' && support === 'facade') {
            folders.push('facade_surroundings');
        }

        if (support === 'pole') {
            folders.push('pole');
        }

        folders.push('closed', 'open', 'cassettes');

        if (box.kind === 'pbo' || hasTcoPhotos) {
            folders.push('tco');
        }

        folders.push('labels', 'lashing');

        return folders;
    }

    if ((support ?? 'chamber') === 'chamber') {
        folders.push('surroundings', 'interior');
    }

    if (box.kind === 'pbo' && support === 'facade') {
        folders.push('facade_surroundings');
    }

    folders.push('closed', 'open', 'labels', 'entries', 'storage', support === 'pole' || support === 'facade' ? 'fixing' : 'placed', 'cassettes');

    if (box.kind === 'pbo') {
        folders.push('tco');
    }

    if (support === 'pole') {
        folders.push('pole');
    }

    return folders;
}

const poleDone = (box) => {
    const has = (section) => photosOf(box, 'pole', section).length > 0;

    return (has('whole') && has('head') && has('label')) || (has('before') && has('after'));
};

export const cableComplete = (box, cable) => Boolean(cable.capacity && cable.fibre_type && (cable.has_no_label || photosOf(box, 'labels', cable.code).length > 0));

const cablesDone = (box) => (box.cables ?? []).length > 0 && box.cables.every((cable) => cableComplete(box, cable));

export function boxItems(box, mode) {
    const optical = mode === 'optical';
    const items = [];

    for (const folder of requiredFolders(box, mode, photosOf(box, 'tco').length > 0)) {
        if (optical && ['labels', 'cassettes', 'lashing'].includes(folder)) {
            continue;
        }

        items.push({ key: folder, done: folder === 'pole' ? poleDone(box) : photosOf(box, folder).length > 0 });
    }

    items.push({ key: 'cable', done: optical ? cablesDone(box) : box.fibre_type != null });

    if (optical) {
        items.push({ key: 'position', done: box.confirmed != null });
    }

    items.push({ key: 'status', done: ['ok', 'anomaly'].includes(box.status) && !box.returned });

    return items;
}

export function boxPercent(box, mode) {
    if (box.status === 'not_installed' && !box.returned) {
        return 100;
    }

    const items = boxItems(box, mode);

    return Math.round((items.filter((item) => item.done).length * 100) / items.length);
}

export const isFinal = (box) => ['ok', 'anomaly', 'not_installed'].includes(box.status) && !box.returned;
