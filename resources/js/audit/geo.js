/**
 * Where the technician is compared with the boxes of the audit: the distance to a box, which box they are on, and
 * when their presence is announced on their behalf. Pure functions, so the rules can be checked without a phone.
 */
export const ARRIVED_WITHIN = 120;
export const LEFT_BEYOND = 250;
export const MAX_ACCURACY = 120;
export const MANUAL_WITHIN = 150;
export const REPEAT_EVERY = 120000;

const EARTH_RADIUS = 6371000;
const radians = (degrees) => (degrees * Math.PI) / 180;

/**
 * Distance in metres between two points ({latitude, longitude}).
 */
export function distance(a, b) {
    const dLat = radians(b.latitude - a.latitude);
    const dLng = radians(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(radians(a.latitude)) * Math.cos(radians(b.latitude)) * Math.sin(dLng / 2) ** 2;

    return Math.round(2 * EARTH_RADIUS * Math.asin(Math.sqrt(h)));
}

/**
 * Known position of a box: the official one approved by the design office (design office workflow), else the one of
 * the plans, else the one confirmed on site.
 */
export const boxPoint = (box) => box.official ?? box.position ?? box.confirmed ?? null;

/**
 * Nearest box with a known position, ignoring the boxes that are done (except the one that is open).
 */
export function nearestBox(boxes, here, openName) {
    let best = null;

    for (const box of boxes) {
        const point = boxPoint(box);

        if (box.removed || !point || (['ok', 'anomaly', 'not_installed'].includes(box.status) && !box.returned && box.name !== openName)) {
            continue;
        }

        const metres = distance(here, point);

        if (best === null || metres < best.metres) {
            best = { box, metres };
        }
    }

    return best;
}

/**
 * What to announce for a new position fix, if anything. `announced` is what was last announced
 * ({box, state, at}) or null.
 *
 * @returns {{state: 'on_box'|'en_route', box: string}|null}
 */
export function decidePresence({ fix, boxes, announced, openName, now }) {
    const here = { latitude: fix.latitude, longitude: fix.longitude };
    const nearest = nearestBox(boxes, here, openName);

    if (nearest !== null && nearest.metres <= ARRIVED_WITHIN && fix.accuracy <= MAX_ACCURACY) {
        const sameBox = announced?.state === 'on_box' && announced.box === nearest.box.name;

        return sameBox && now - announced.at < REPEAT_EVERY ? null : { state: 'on_box', box: nearest.box.name };
    }

    if (announced?.state === 'on_box') {
        const box = boxes.find((candidate) => candidate.name === announced.box);
        const point = box ? boxPoint(box) : null;

        if (point && distance(here, point) > LEFT_BEYOND) {
            return { state: 'en_route', box: announced.box };
        }
    }

    return null;
}

/**
 * How a manual « I'm arriving » compares with the position of the box: the distance, or null when unknown.
 */
export function manualDistance(fix, box) {
    const point = fix && boxPoint(box);

    return point ? distance({ latitude: fix.latitude, longitude: fix.longitude }, point) : null;
}
