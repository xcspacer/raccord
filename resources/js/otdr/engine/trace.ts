import { EVENT_COMMENTS } from '../export/format';
import { message, OtdrError } from './messages';
import { type SorEvent, type SorFile, timeToIndex } from './sor';

const C_KM_PER_S = 299792.458;

/** Least-squares line on points [i0, i1[; `slopePerPoint` in dB per point. */
function fitLine(data: Float32Array, i0: number, i1: number): { at: (x: number) => number; slopePerPoint: number } | null {
    const f = fit(data, i0, i1);

    if (!f) {
        return null;
    }

    return { at: f, slopePerPoint: f(1) - f(0) };
}

/** Least-squares line on the trace points [i0, i1[. */
function fit(data: Float32Array, i0: number, i1: number): ((x: number) => number) | null {
    const a = Math.max(0, i0);
    const b = Math.min(data.length, i1);
    const n = b - a;

    if (n < 2) {
        return null;
    }

    let sx = 0;
    let sy = 0;

    for (let i = a; i < b; i++) {
        sx += i;
        sy += data[i]!;
    }

    const mx = sx / n;
    const my = sy / n;
    let sxy = 0;
    let sxx = 0;

    for (let i = a; i < b; i++) {
        const dx = i - mx;
        sxy += dx * (data[i]! - my);
        sxx += dx * dx;
    }

    if (sxx === 0) {
        return null;
    }

    const slope = sxy / sxx;

    return (x) => my + slope * (x - mx);
}

/**
 * Total loss between two events (connectors included), like FiberCable: LSA line of the section before `first`
 * extrapolated to its position, LSA line of the section after `last` (or before, when `last` is the fiber end)
 * extrapolated to its position.
 */
export function totalLoss(sor: SorFile, first: SorEvent, last: SorEvent, lastIsEnd = false): number | null {
    const before = fit(sor.data, timeToIndex(sor, first.marks[0]), timeToIndex(sor, first.marks[1]));
    const after = lastIsEnd
        ? fit(sor.data, timeToIndex(sor, last.marks[0]), timeToIndex(sor, last.marks[1]))
        : fit(sor.data, timeToIndex(sor, last.marks[2]), timeToIndex(sor, last.marks[3]));

    if (!before || !after) {
        return null;
    }

    return after(timeToIndex(sor, last.time)) - before(timeToIndex(sor, first.time));
}

/** Rounding to the thousandth of the binary value (like toFixed): closest convention to FiberCable. */
export function round3(x: number): number {
    return Number(x.toFixed(3));
}

/**
 * Bidirectional mean rounded to the thousandth.
 * The .sor files only give losses to the thousandth; FiberCable averages a finer internal value (private Viavi
 * block). When the mean falls exactly on x.xxx5 the rounding may therefore differ by ±0.001 dB (measured: 64 % of
 * these cases identical, 100 % of the others).
 */
export function mean3(a: number, b: number): number {
    return round3((a + b) / 2);
}

export function round2(x: number): number {
    return Number(x.toFixed(2));
}

export type AddEventRefusal = 'out_of_trace' | 'too_close_previous' | 'too_close_next' | 'section_too_short';

/** Manual event that cannot be placed; `detail` is the reason shown on the trace screen. */
export class AddEventError extends OtdrError {
    readonly reason: AddEventRefusal;

    constructor(reason: AddEventRefusal, km: number) {
        super(message(`add_event_refused_${reason}`, { distance: Math.round(km * 1000) }));
        this.reason = reason;
    }
}

/** Dead zone after the event, in pulse lengths (measured on the OTDRs: median 2.4 to 3.3). */
const DZ_AFTER = [3, 2, 1];

/**
 * Event added by hand near `km` (distance on the trace), as the OTDR does:
 * - "magnet": the real start of the event is searched within ± 3 pulses, where the trace leaves the line of the
 *   previous section by more than 4 noise standard deviations;
 * - loss = LSA line of the next section (after a dead zone of 3 → 1 pulses) minus LSA line of the previous section
 *   (up to the event start), extrapolated to the start;
 * - sections bounded by the OTDR markers of the neighbouring events.
 * SR-4731 code "0A…" = added by the user. Updates the slope carried by the next event.
 */
export function addEvent(sor: SorFile, km: number, num: number): SorEvent {
    const kmPerTime = (1e-10 * C_KM_PER_S) / sor.ior;
    const click = Math.round(km / sor.sampleKm);

    if (click <= 0 || click >= sor.data.length - 1) {
        throw new AddEventError('out_of_trace', km);
    }

    const pulsePts = Math.max(1, (sor.pulseNs * 1e-9 * (C_KM_PER_S / sor.ior)) / 2 / sor.sampleKm);
    const ev = sor.events.filter((e) => Math.abs(e.km - km) > 1e-9).sort((a, b) => a.time - b.time);
    const prev = [...ev].reverse().find((e) => e.km < km);
    const next = ev.find((e) => e.km > km);
    const startB = prev ? (prev.marks[2] > prev.time ? timeToIndex(sor, prev.marks[2]) : timeToIndex(sor, prev.time) + Math.ceil(3 * pulsePts)) : 0;
    const endA = next ? (next.marks[1] > 0 && next.marks[1] <= next.time ? timeToIndex(sor, next.marks[1]) : timeToIndex(sor, next.time)) : sor.data.length;

    // Magnet: event start near the touched point.
    const reach = Math.ceil(3 * pulsePts);
    let idx = click;
    const ref = fitLine(sor.data, startB, Math.max(startB + 3, click - reach));

    if (ref && click - reach - startB >= 5) {
        let ss = 0;
        let n = 0;

        for (let i = startB; i < click - reach; i++) {
            const r = sor.data[i]! - ref.at(i);
            ss += r * r;
            n++;
        }

        const sigma = Math.sqrt(ss / Math.max(1, n));
        const hit = (() => {
            for (let i = click - reach; i <= Math.min(endA - 1, click + reach); i++) {
                if (Math.abs(sor.data[i]! - ref.at(i)) > Math.max(4 * sigma, 0.01)) {
                    return i;
                }
            }

            return -1;
        })();

        if (hit > startB + 3) {
            idx = hit - 1;
        }
    }

    const m = DZ_AFTER.map((f) => Math.ceil(f * pulsePts)).find((mm) => idx - startB >= 3 && endA - idx - mm >= 3);

    if (m === undefined || idx - startB < 3) {
        throw new AddEventError(idx - startB < endA - idx ? 'too_close_previous' : 'too_close_next', km);
    }

    const before = fitLine(sor.data, startB, idx);
    const after = fitLine(sor.data, idx + m, endA);

    if (!before || !after) {
        throw new AddEventError('section_too_short', km);
    }

    const toTime = (i: number) => Math.round((i * sor.sampleKm) / kmPerTime);
    const loss = Math.round((after.at(idx) - before.at(idx)) * 1000) / 1000;
    const slopeOf = (f: { slopePerPoint: number }) => Math.round((f.slopePerPoint / sor.sampleKm) * 1000) / 1000;

    if (next) {
        next.slope = slopeOf(after);
    }

    return {
        num, time: toTime(idx), km: idx * sor.sampleKm, slope: slopeOf(before), loss, reflectance: 0, code: '0A9999LS',
        marks: [toTime(startB), toTime(idx), toTime(idx + m), toTime(endA), 0], comment: EVENT_COMMENTS.added(),
    };
}
