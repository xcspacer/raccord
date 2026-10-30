// Complete analysis of an OTDR trace, like an operator on FiberCable: each trace is read again point by point, the
// events missed by the OTDR are added, each event gets its LSA markers (start of previous section, event start, end
// of dead zone, end of next section), its loss, its reflectance and the slope of each section.
// The values measured by the OTDR on its own events are kept (they are the instrument's).
import { EVENT_COMMENTS } from '../export/format';
import { isEndEvent, type SorEvent, type SorFile, timeToIndex } from './sor';
import { addEvent } from './trace';

const C_KM_PER_S = 299792.458;

export interface DetectionSettings {
    /** Splice detection threshold (|loss| ≥, dB). */
    splice: number;
    /** Reflective event detection threshold (reflectance ≥, dB). */
    reflectance: number;
    /** Fiber end threshold (level drop ≥, dB). */
    end: number;
}

export const DEFAULT_DETECTION: DetectionSettings = { splice: 0.05, reflectance: -72, end: 3 };

/** Event added by the complete analysis (SR-4731 code: 2nd character "T"). */
export const isDetected = (e: SorEvent) => e.code.charAt(1) === 'T';

export interface TraceGeometry {
    /** Pulse length, in trace points. */
    pulsePts: number;
    /** Event dead zone after the start (points). */
    deadPts: number;
    kmPerTime: number;
}

export function geometry(sor: SorFile): TraceGeometry {
    const kmPerTime = (1e-10 * C_KM_PER_S) / sor.ior;
    const pulsePts = Math.max(1, (sor.pulseNs * 1e-9 * (C_KM_PER_S / sor.ior)) / 2 / sor.sampleKm);

    return { pulsePts, deadPts: Math.max(4, Math.ceil(3 * pulsePts)), kmPerTime };
}

/** Prefix sums for O(1) least-squares lines. */
export class Lsa {
    private readonly s1: Float64Array;
    private readonly sx: Float64Array;
    private readonly sy: Float64Array;
    private readonly sxx: Float64Array;
    private readonly sxy: Float64Array;
    private readonly syy: Float64Array;
    private readonly y: Float32Array;

    constructor(y: Float32Array) {
        this.y = y;
        const n = y.length + 1;
        this.s1 = new Float64Array(n);
        this.sx = new Float64Array(n);
        this.sy = new Float64Array(n);
        this.sxx = new Float64Array(n);
        this.sxy = new Float64Array(n);
        this.syy = new Float64Array(n);

        for (let i = 0; i < y.length; i++) {
            const v = y[i]!;
            this.s1[i + 1] = this.s1[i]! + 1;
            this.sx[i + 1] = this.sx[i]! + i;
            this.sy[i + 1] = this.sy[i]! + v;
            this.sxx[i + 1] = this.sxx[i]! + i * i;
            this.sxy[i + 1] = this.sxy[i]! + i * v;
            this.syy[i + 1] = this.syy[i]! + v * v;
        }
    }

    /** Centred sums on [a, b[ (regression shared by several sections). */
    moments(a0: number, b0: number): { n: number; sxx: number; sxy: number } | null {
        const a = Math.max(0, Math.round(a0));
        const b = Math.min(this.y.length, Math.round(b0));
        const n = b - a;

        if (n < 3) {
            return null;
        }

        const sx = this.sx[b]! - this.sx[a]!;
        const sy = this.sy[b]! - this.sy[a]!;

        return { n, sxx: this.sxx[b]! - this.sxx[a]! - (sx * sx) / n, sxy: this.sxy[b]! - this.sxy[a]! - (sx * sy) / n };
    }

    /** Line on [a, b[: level at x, slope per point, standard deviation of the residuals. */
    fit(a0: number, b0: number): { at: (x: number) => number; slope: number; sigma: number; n: number } | null {
        const a = Math.max(0, Math.round(a0));
        const b = Math.min(this.y.length, Math.round(b0));
        const n = b - a;

        if (n < 3) {
            return null;
        }

        const sx = this.sx[b]! - this.sx[a]!;
        const sy = this.sy[b]! - this.sy[a]!;
        const sxx = this.sxx[b]! - this.sxx[a]!;
        const sxy = this.sxy[b]! - this.sxy[a]!;
        const syy = this.syy[b]! - this.syy[a]!;
        const d = n * sxx - sx * sx;

        if (d === 0) {
            return null;
        }

        const slope = (n * sxy - sx * sy) / d;
        const icpt = (sy - slope * sx) / n;
        const sse = Math.max(0, syy - icpt * sy - slope * sxy);

        return { at: (x) => icpt + slope * x, slope, sigma: Math.sqrt(sse / Math.max(1, n - 2)), n };
    }
}

/**
 * Reflectance of a peak of height H (dB, one-way 5·log scale):
 * R = Bs + 10·log10(D) + 10·log10(10^(H/5) − 1), Bs backscatter coefficient for 1 ns, D pulse (ns).
 */
export function reflectanceFromHeight(sor: SorFile, h: number): number {
    const bs = sor.backscatterDb || (sor.wavelengthNm > 1400 ? -81 : -79);

    return bs + 10 * Math.log10(Math.max(1, sor.pulseNs)) + 10 * Math.log10(Math.max(1e-9, Math.pow(10, h / 5) - 1));
}

export interface Candidate {
    /** Event start (trace index). */
    idx: number;
    reflective: boolean;
    /** Estimated loss (line after − line before), dB. */
    step: number;
    /** Peak height above the backscatter, dB (reflective). */
    height: number;
}

/** Fiber end index: OTDR "E" event, otherwise first drop ≥ threshold followed by noise. */
export function endIndex(sor: SorFile, lsa: Lsa, g: TraceGeometry, set: DetectionSettings): number {
    const end = sor.events.find(isEndEvent);

    if (end) {
        return timeToIndex(sor, end.time);
    }

    const n = sor.data.length;
    const w = Math.max(20, Math.ceil(10 * g.pulsePts));

    for (let i = w; i + w < n; i += 1) {
        const before = lsa.fit(i - w, i);
        const after = lsa.fit(i + g.deadPts, i + g.deadPts + w);

        if (before && after && after.at(i) - before.at(i) >= set.end && after.sigma > Math.max(0.1, 5 * before.sigma)) {
            return i;
        }
    }

    return n - 1;
}

/**
 * Events present on the trace between `from` and `to` (indices), detected as an OTDR does:
 * - reflective: peak (level dropping under the section line) whose reflectance exceeds the threshold;
 * - non reflective: step between the LSA line before and the LSA line after the dead zone, |step| ≥ threshold
 *   and ≥ 6 standard deviations of the local noise.
 */
export function detect(sor: SorFile, from: number, to: number, set: DetectionSettings = DEFAULT_DETECTION, lsa = new Lsa(sor.data), shrinkEnd = true): Candidate[] {
    const g = geometry(sor);
    const y = sor.data;
    const W = Math.max(24, Math.ceil(8 * g.pulsePts), Math.round(0.03 / sor.sampleKm));
    const D = g.deadPts;
    const out: Candidate[] = [];
    // Reflective peaks.
    const reflZones: [number, number][] = [];

    for (let i = from + 3; i < to - 1; i++) {
        const ref = lsa.fit(i - W, i - 1);

        if (!ref) {
            continue;
        }

        const thr = Math.max(0.08, 6 * ref.sigma);

        if (ref.at(i) - y[i]! < thr) {
            continue;
        }

        // Peak: minimum level over the following pulse.
        let p = i;

        for (let j = i; j < Math.min(to, i + Math.ceil(2 * g.pulsePts) + 2); j++) {
            if (y[j]! < y[p]!) {
                p = j;
            }
        }

        const h = ref.at(p) - y[p]!;
        let k = p;

        while (k < to && ref.at(k) - y[k]! > thr / 2) {
            k++;
        }

        reflZones.push([i, k]);
        const r = reflectanceFromHeight(sor, h);
        const after = lsa.fit(k + D, k + D + W);
        const step = after ? after.at(i - 1) - ref.at(i - 1) : 0;

        // Visible peak: reflective when above the threshold, otherwise kept as an event when its loss is measurable.
        if (r >= set.reflectance || Math.abs(step) >= set.splice) {
            out.push({ idx: i - 1, reflective: r >= set.reflectance, step, height: h });
        }

        i = k + D;
    }

    // Neither window may touch a peak.
    const inRefl = (i: number) => reflZones.some(([a, b]) => i > a - D - W - 2 && i < b + D + W + 2);
    // Non reflective steps: v(i) = line after the dead zone − line before, extrapolated at i.
    // The noise of v is measured on the trace itself (median absolute deviation): it accounts for the OTDR filtering.
    // A window overlapping an event is no longer a line (high residuals): that point is not judged, otherwise the
    // tilted extrapolated line creates false events of opposite sign on both sides of the real one.
    const v = new Float64Array(Math.max(0, to - from));
    const sig = new Float64Array(Math.max(0, to - from));
    const sigMin = new Float64Array(Math.max(0, to - from));
    const valid: number[] = [];
    const sigmas: number[] = [];
    // Reduced windows near the bounds (short fiber after a closure): at least Wmin points.
    // Near the end (closure splice a few metres from the connector), the dead zone is reduced to 1.5 pulse.
    const Wmin = Math.max(8, Math.ceil(2 * g.pulsePts));
    const Dmin = Math.max(3, Math.ceil(1.5 * g.pulsePts));
    const dz = (i: number) => (shrinkEnd ? Math.min(D, to - i - Wmin) : D);
    const last = to - (shrinkEnd ? Dmin : D) - Wmin;

    for (let i = from + Wmin; i < last; i++) {
        if (inRefl(i)) {
            continue;
        }

        const Di = dz(i);
        const before = lsa.fit(i - Math.min(W, i - from), i);
        const after = lsa.fit(i + Di, i + Di + Math.min(W, to - i - Di));

        if (!before || !after) {
            continue;
        }

        v[i - from] = after.at(i) - before.at(i);
        sig[i - from] = Math.max(before.sigma, after.sigma);
        sigMin[i - from] = Math.min(before.sigma, after.sigma);
        valid.push(Math.abs(v[i - from]!));
        sigmas.push(before.sigma);
    }

    valid.sort((a, b) => a - b);
    sigmas.sort((a, b) => a - b);
    const sigmaV = valid.length ? 1.4826 * valid[Math.floor(valid.length / 2)]! : 0;
    const sigma0 = sigmas.length ? sigmas[Math.floor(sigmas.length / 2)]! : 0;
    const linear = (i: number) => sig[i - from]! <= Math.max(3 * sigMin[i - from]!, 1.6 * sigma0, 0.012);
    const thr = Math.max(set.splice, 5 * sigmaV);
    let best: { i: number; v: number } | null = null;
    // Position: the step maximum is a plateau as wide as the dead zone; the event start is where the trace leaves the
    // previous line (half step height, minus half a pulse).
    const refine = (i: number, step: number) => {
        const before = lsa.fit(i - Math.min(W, i - from), i - D);

        if (!before) {
            return i;
        }

        for (let j = i - D; j <= Math.min(to - 1, i + D); j++) {
            if (Math.sign(step) * (y[j]! - before.at(j)) > Math.abs(step) / 2) {
                return Math.max(i - D, j - Math.round(g.pulsePts / 2));
            }
        }

        return i;
    };
    const flush = () => {
        if (best) {
            out.push({ idx: refine(best.i, best.v), reflective: false, step: best.v, height: 0 });
        }

        best = null;
    };

    for (let i = from + Wmin; i < last; i++) {
        const x = v[i - from]!;
        const ok = !inRefl(i) && linear(i) && Math.abs(x) >= thr && Math.abs(x) >= 3 * sig[i - from]! && (!best || Math.sign(x) === Math.sign(best.v));

        if (ok && (!best || Math.abs(x) > Math.abs(best.v))) {
            best = { i, v: x };
        } else if (!ok && best && i - best.i > D) {
            flush();
        }
    }

    flush();
    // A real event has clean windows: a weaker candidate whose windows touch a stronger one is an echo of it (bent
    // line), it is removed.
    const reach = W + D;
    const kept: Candidate[] = [];

    for (const c of [...out].sort((a, b) => Number(b.reflective) - Number(a.reflective) || Math.abs(b.step) - Math.abs(a.step))) {
        if (c.reflective || !kept.some((k) => Math.abs(k.idx - c.idx) < reach)) {
            kept.push(c);
        }
    }

    return kept.sort((a, b) => a.idx - b.idx);
}

/** Start of the edge of the end reflection (some OTDRs place the end marker at the top of the peak). */
export function endEdge(sor: SorFile, end: number, lsa = new Lsa(sor.data), g = geometry(sor)): number {
    const look = Math.ceil(4 * g.pulsePts);
    const W = Math.max(24, Math.ceil(8 * g.pulsePts));
    const line = lsa.fit(end - look - W, end - look);

    if (!line) {
        return end;
    }

    const thr = Math.max(0.05, 5 * line.sigma);

    for (let j = end - look; j <= end; j++) {
        if (line.at(j) - sor.data[j]! > thr) {
            return j;
        }
    }

    return end;
}

/**
 * Fiber end not marked by the OTDR (last event coded "F" instead of "E"): when the trace is only noise (or drops by
 * more than the end threshold) after the last event, that event is the fiber end. Returns true when the event was
 * requalified.
 */
export function inferEnd(sor: SorFile, set: DetectionSettings = DEFAULT_DETECTION): boolean {
    if (!sor.events.length || sor.events.some(isEndEvent)) {
        return false;
    }

    const g = geometry(sor);
    const lsa = new Lsa(sor.data);
    const last = [...sor.events].sort((a, b) => b.time - a.time)[0]!;
    const i = timeToIndex(sor, last.time);
    const W = Math.max(24, Math.ceil(8 * g.pulsePts));
    const before = lsa.fit(i - W, i - 1);
    const after = lsa.fit(i + 2 * g.deadPts, i + 2 * g.deadPts + W);
    const noise = !after || after.sigma > Math.max(0.1, 5 * (before?.sigma ?? 0.01)) || (before !== null && after.at(i) - before.at(i) >= set.end);

    if (!noise) {
        return false;
    }

    last.code = `${last.code.charAt(0)}E${last.code.slice(2)}`;
    last.loss = 0;

    return true;
}

/** First number of the events found by the complete analysis (+ trace index: stable number). */
export const DETECTED_EVENT_BASE = 100000;

const zeroMarks = (e: SorEvent) => e.marks.every((m) => m === 0);

/** Measured gap between the Viavi reflectance and the formula (SmartOTDR, 3 measurement sets: +1.25 dB). */
const REFLECTANCE_OFFSET = 1.25;

/** Backscatter line too noisy to measure a peak height (event beyond the dynamic range of the trace), dB. */
const NOISY_BACKSCATTER = 0.1;

/** Reflectance calibration on the reflective events measured by the OTDR in this file (peaks above the noise). */
function reflectanceOffset(sor: SorFile, lsa: Lsa, g: TraceGeometry): number {
    const offsets: number[] = [];
    const W = Math.max(24, Math.ceil(8 * g.pulsePts));

    for (const e of sor.events) {
        if (e.reflectance === 0 || isDetected(e)) {
            continue;
        }

        const i = timeToIndex(sor, e.time);
        const ref = lsa.fit(i - W, i - 1);

        if (!ref || ref.sigma > NOISY_BACKSCATTER) {
            continue;
        }

        let p = i;

        for (let j = i; j < Math.min(sor.data.length, i + Math.ceil(2 * g.pulsePts) + 2); j++) {
            if (sor.data[j]! < sor.data[p]!) {
                p = j;
            }
        }

        const h = ref.at(p) - sor.data[p]!;

        if (h > 0.1 && h < 15) {
            offsets.push(e.reflectance - reflectanceFromHeight(sor, h));
        }
    }

    offsets.sort((a, b) => a - b);

    return offsets.length ? offsets[Math.floor(offsets.length / 2)]! : REFLECTANCE_OFFSET;
}

/**
 * Position tolerance between two "identical" events (trace points).
 * 2 pulses: two closer events cannot be separated; typical gap between wavelengths or fibers: 1 to 5 m.
 */
export const sameEventTol = (sor: SorFile) => Math.max(6, 2 * geometry(sor).pulsePts);

/**
 * Events present on the trace between two indices and missing from the OTDR list (not inserted).
 * Loss = step between LSA lines, reflectance calibrated on the OTDR.
 */
export function findMissing(sor: SorFile, from: number, to: number, set: DetectionSettings = DEFAULT_DETECTION): SorEvent[] {
    const g = geometry(sor);
    const lsa = new Lsa(sor.data);
    const toTime = (i: number) => Math.round((i * sor.sampleKm) / g.kmPerTime);

    if (to - from < 3 * g.deadPts) {
        return [];
    }

    const k = reflectanceOffset(sor, lsa, g);
    const tol = sameEventTol(sor);
    // Section by section search between the known events: no window overlaps an event.
    const known = sor.events.map((e) => timeToIndex(sor, e.time)).sort((a, b) => a - b);
    // The edge of an event shows up to ~2 pulses before its position (long pulses, coarse step).
    const margin = Math.max(2, Math.ceil(2.5 * g.pulsePts));
    const bounds = [from, ...known.filter((i) => i > from && i < to).flatMap((i) => [i - margin, i + g.deadPts]), to];
    const candidates: Candidate[] = [];

    for (let s = 0; s + 1 < bounds.length; s += 2) {
        // Dead zone reduced only before the end of the search zone (closure splice near the connector).
        if (bounds[s + 1]! - bounds[s]! >= 2 * g.deadPts) {
            candidates.push(...detect(sor, bounds[s]!, bounds[s + 1]!, set, lsa, s + 2 === bounds.length));
        }
    }

    return candidates
        .filter((c) => !known.some((i) => Math.abs(i - c.idx) <= tol))
        .map((c) => ({
            num: DETECTED_EVENT_BASE + c.idx, time: toTime(c.idx), km: c.idx * sor.sampleKm, slope: 0,
            loss: Math.round(c.step * 1000) / 1000,
            reflectance: c.reflective ? Math.round((reflectanceFromHeight(sor, c.height) + k) * 100) / 100 : 0,
            code: `${c.reflective ? 1 : 0}T9999LS`, marks: [0, 0, 0, 0, 0] as SorEvent['marks'], comment: EVENT_COMMENTS.detected(),
        }));
}

/**
 * Event measured at an imposed position (synchronisation of both directions of a bidirectional measurement): same
 * computation as the manual addition (magnet ± 3 pulses, LSA lines), flagged as detected. null when not measurable.
 */
export function measureAt(sor: SorFile, km: number): SorEvent | null {
    // The slope of the cut section stays the OTDR one (the fiber attenuation does not change at a splice).
    const next = [...sor.events].sort((a, b) => a.time - b.time).find((x) => x.km > km);
    const kept = next?.slope;

    try {
        const e = addEvent(sor, km, 0);

        if (next && kept !== undefined) {
            next.slope = kept;
        }

        const idx = timeToIndex(sor, e.time);
        // Lines of at least two pulses on each side, otherwise the loss is not measurable.
        const g = geometry(sor);
        const minPts = Math.max(8, Math.ceil(2 * g.pulsePts));
        const [a, , c, d] = e.marks.map((m) => timeToIndex(sor, m));

        if (idx - a! < minPts || d! - c! < minPts) {
            return twoPoint(sor, km);
        }

        return { ...e, num: DETECTED_EVENT_BASE + idx, code: '0T9999LS', comment: EVENT_COMMENTS.detectedAtOtherDirection() };
    } catch {
        if (next && kept !== undefined) {
            next.slope = kept;
        }

        return twoPoint(sor, km);
    }
}

/**
 * "2 points" measurement (OTDR method when lines cannot be fitted: event stuck to another one or to the end): mean
 * level just before the event, mean level just after the edge (1.5 pulse), corrected by the fiber attenuation between
 * both. SR-4731 code "…2P". null when the event lies in the dead zone of a neighbour.
 */
export function twoPoint(sor: SorFile, km: number): SorEvent | null {
    const g = geometry(sor);
    const idxOf = (e: SorEvent) => timeToIndex(sor, e.time);
    const i = Math.round(km / sor.sampleKm);
    const sorted = [...sor.events].sort((a, b) => a.time - b.time);
    const prev = [...sorted].reverse().find((e) => idxOf(e) < i - 1);
    const next = sorted.find((e) => idxOf(e) > i + 1);
    const P = Math.max(1, Math.round(g.pulsePts));
    const m = Math.max(3, P);
    const a0 = Math.max(prev ? idxOf(prev) + Math.ceil(1.5 * g.pulsePts) : 0, i - 1 - m);
    const a1 = i - 1;
    const b0 = i + Math.ceil(1.5 * g.pulsePts);
    const b1 = Math.min(next ? idxOf(next) - 1 : sor.data.length, b0 + m);

    if (a1 - a0 < 2 || b1 - b0 < 2) {
        return null;
    }

    const mean = (x: number, y: number) => {
        let s = 0;

        for (let k = x; k < y; k++) {
            s += sor.data[k]!;
        }

        return s / (y - x);
    };
    const slopeKm = (next?.slope || sorted.find((e) => e.slope > 0)?.slope || (sor.wavelengthNm > 1400 ? 0.19 : 0.33));
    const dx = ((b0 + b1) / 2 - (a0 + a1) / 2) * sor.sampleKm;
    const loss = Math.round((mean(b0, b1) - mean(a0, a1) - slopeKm * dx) * 1000) / 1000;
    const toTime = (k: number) => Math.round((k * sor.sampleKm) / g.kmPerTime);

    return {
        num: DETECTED_EVENT_BASE + i, time: toTime(i), km: i * sor.sampleKm, slope: 0, loss, reflectance: 0, code: '0T99992P',
        marks: [toTime(a0), toTime(i), toTime(b0), toTime(b1), 0], comment: EVENT_COMMENTS.detectedTwoPoint(),
    };
}

/**
 * Finished trace: missing LSA markers (end of previous section, start, end of dead zone, start of next section),
 * missing slopes (LSA line, section ≥ 100 m), slope of added events = slope of the cut section.
 */
export function finishTrace(sor: SorFile): void {
    const g = geometry(sor);
    const lsa = new Lsa(sor.data);
    const toTime = (i: number) => Math.round((i * sor.sampleKm) / g.kmPerTime);
    const idxOf = (e: SorEvent) => timeToIndex(sor, e.time);
    const W = Math.max(24, Math.ceil(8 * g.pulsePts));
    const events = [...sor.events].sort((a, b) => a.time - b.time);

    for (const e of events) {
        if (e.otdrSlope === undefined) {
            e.otdrSlope = isDetected(e) ? 0 : e.slope;
        }
    }

    const noise = traceNoise(sor, lsa, g, events);
    const pending: SorEvent[] = [];
    const good: { slope: number; w: number }[] = [];
    // Regression shared by all the sections of the trace (one step per event, a single slope): slope of the fiber
    // measured over its whole useful length, even when each section is short.
    let pSxx = 0;
    let pSxy = 0;

    for (let j = 0; j < events.length; j++) {
        const e = events[j]!;
        const i = idxOf(e);
        const prev = events[j - 1];
        const next = events[j + 1];
        const a = prev ? Math.min(i, idxOf(prev) + g.deadPts) : Math.max(0, i - W);
        const d = next ? idxOf(next) : Math.min(sor.data.length, i + g.deadPts + W);

        if (zeroMarks(e)) {
            e.marks = [toTime(a), e.time, toTime(Math.min(d, i + g.deadPts)), toTime(d), e.reflectance ? e.time : 0];
        }

        if (!prev) {
            continue;
        }

        // Slope of the section before the event: markers moved to the most reliable line.
        const b0 = isEndEvent(e) ? endEdge(sor, i, lsa, g) : i;
        const fit = bestSlope(sor, lsa, g, idxOf(prev), b0, noise);
        const otdr = e.otdrSlope ?? 0;
        const reliable = fit !== null && fit.se <= SLOPE_MAX_SE;

        if (!isEndEvent(prev)) {
            const wa = idxOf(prev) + Math.ceil(2 * g.pulsePts);
            const wb = b0 - Math.ceil(0.5 * g.pulsePts);
            const lin = wb - wa >= 8 ? lsa.fit(wa, wb) : null;
            const m = lin && lin.sigma <= Math.max(2.5 * noise, noise + 0.01) ? lsa.moments(wa, wb) : null;

            if (m) {
                pSxx += m.sxx;
                pSxy += m.sxy;
            }
        }

        const lengthKm = (b0 - idxOf(prev)) * sor.sampleKm;

        if (otdr !== 0 && !isDetected(prev) && (reliable ? Math.abs(otdr - fit!.slope) <= Math.max(3 * fit!.se, 0.03) : lengthKm >= 0.1)) {
            // OTDR slope confirmed by the trace, or section of at least 100 m that the trace does not contradict: kept.
            e.slope = otdr;
            e.slopeSource = 'otdr';

            if (reliable) {
                good.push({ slope: otdr, w: 1 / Math.max(1e-4, fit!.se) ** 2 });
            }

            continue;
        }

        if (reliable) {
            e.slope = Math.round(fit!.slope * 1000) / 1000;
            e.slopeSource = 'lsa';
            good.push({ slope: fit!.slope, w: 1 / Math.max(1e-4, fit!.se) ** 2 });
        } else {
            pending.push(e);
        }
    }

    // Section too short or too disturbed for a reliable line: slope of the fiber (weighted mean of the reliable sections
    // of the same trace); without any reliable section, the slope is completed at the set level.
    const pooledKm = pSxx > 0 ? pSxy / pSxx / sor.sampleKm : null;
    const pooledSe = pSxx > 0 ? (2 * noise) / Math.sqrt(pSxx * sor.sampleKm * sor.sampleKm) : Infinity;
    const wsum = good.reduce((s, x) => s + x.w, 0);
    const ref = pooledKm !== null && pooledSe <= POOLED_MAX_SE ? pooledKm : wsum > 0 ? good.reduce((s, x) => s + x.slope * x.w, 0) / wsum : null;

    for (const e of pending) {
        if (ref !== null) {
            e.slope = Math.round(ref * 1000) / 1000;
            e.slopeSource = 'fiber';
        } else {
            e.slope = 0;
            e.slopeSource = undefined;
        }
    }

    sor.events = events;
}

/** Maximum standard error of a measured slope (dB/km): beyond it, the section is too short or too noisy. */
const SLOPE_MAX_SE = 0.03;
/** Slope of the fiber (shared regression): maximum accepted standard error. */
const POOLED_MAX_SE = 0.02;

/** Trace noise (standard deviation of the residuals of a line over 40 points), median over the fiber. */
function traceNoise(sor: SorFile, lsa: Lsa, g: TraceGeometry, events: SorEvent[]): number {
    const idx = events.map((e) => timeToIndex(sor, e.time));
    const lo = Math.min(...idx);
    const hi = Math.max(...idx);
    const out: number[] = [];
    const w = 40;

    for (let i = lo + g.deadPts; i + w < hi; i += w) {
        const f = lsa.fit(i, i + w);

        if (f) {
            out.push(f.sigma);
        }
    }

    out.sort((a, b) => a - b);

    return out.length ? out[Math.floor(out.length / 2)]! : 0.01;
}

/**
 * Slope of a section between two events (indices a0 < b0), like the operator moving the markers: start after a dead
 * zone of 3, 2 or 1.5 pulses, end 1, ½ or 0 pulse before the next event; among the placements whose line fits the
 * trace (residuals ≈ noise), the one with the most reliable slope (minimal standard error, correlated OTDR noise
 * counted twice).
 */
export function bestSlope(sor: SorFile, lsa: Lsa, g: TraceGeometry, a0: number, b0: number, noise: number): { slope: number; se: number; n: number } | null {
    const P = g.pulsePts;
    let best: { slope: number; se: number; n: number } | null = null;

    for (const dzA of [3 * P, 2 * P, 1.5 * P]) {
        for (const mB of [P, 0.5 * P, 0]) {
            const a = Math.round(a0 + dzA);
            const b = Math.round(b0 - mB);

            if (b - a < 12) {
                continue;
            }

            const f = lsa.fit(a, b);

            if (!f) {
                continue;
            }

            // Line fitting the trace: residuals at the noise level (slow OTDR ripples tolerated).
            if (f.sigma > Math.max(2.5 * noise, noise + 0.01)) {
                continue;
            }

            const n = f.n;
            const sxx = ((n * (n * n - 1)) / 12) * sor.sampleKm * sor.sampleKm;
            const se = (2 * f.sigma) / Math.sqrt(sxx);

            if (!best || se < best.se) {
                best = { slope: f.slope / sor.sampleKm, se, n };
            }
        }
    }

    return best;
}

/**
 * Reflectance of a connector the OTDR did not measure: height of the peak above the backscatter line (calibrated on
 * the OTDR reflectances of the file). Without a visible peak, the value is the measurement limit (peak of 3 noise
 * standard deviations): the real reflectance is lower or equal.
 */
export function measureReflectance(sor: SorFile, e: SorEvent): number | null {
    const g = geometry(sor);
    const lsa = new Lsa(sor.data);
    const i = timeToIndex(sor, e.time);
    const W = Math.max(24, Math.ceil(8 * g.pulsePts));
    const ref = lsa.fit(i - W - 2, i - 2);

    if (!ref) {
        return null;
    }

    let p = i;

    for (let j = Math.max(0, i - Math.ceil(g.pulsePts)); j < Math.min(sor.data.length, i + Math.ceil(2 * g.pulsePts) + 2); j++) {
        if (sor.data[j]! < sor.data[p]!) {
            p = j;
        }
    }

    const h = Math.max(ref.at(p) - sor.data[p]!, 3 * Math.max(ref.sigma, 0.002));

    return Math.round((reflectanceFromHeight(sor, h) + reflectanceOffset(sor, lsa, g)) * 100) / 100;
}

/** Inserts events in the trace and finishes it. */
export function insertEvents(sor: SorFile, list: SorEvent[]): void {
    if (list.length) {
        sor.events = [...sor.events, ...list];
    }

    finishTrace(sor);
}

/**
 * Complete analysis of a trace measured in a single direction (in place): events missed by the OTDR between the first
 * event (launch spool connector) and the fiber end, markers and slopes. Returns the number of added events.
 */
export function completeTrace(sor: SorFile, set: DetectionSettings = DEFAULT_DETECTION): number {
    const g = geometry(sor);
    const lsa = new Lsa(sor.data);
    const events = [...sor.events].sort((a, b) => a.time - b.time);
    const endEv = events.find(isEndEvent);
    const end = endEv ? timeToIndex(sor, endEv.time) : endIndex(sor, lsa, g, set);
    const first = events.find((e) => !isEndEvent(e));
    const found = first ? findMissing(sor, timeToIndex(sor, first.time) + g.deadPts, endEdge(sor, end, lsa, g) - 2, set) : [];
    insertEvents(sor, found);

    return found.length;
}
