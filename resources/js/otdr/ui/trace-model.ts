// Geometry of the trace viewer: decimated curve, visible window (zoom, pan, focus), axis ticks and the LSA markers of
// the selected event. Pure functions: the drawing itself is in trace-view.ts.
import { Lsa } from '../engine/analysis';
import { isEndEvent, type SorEvent, type SorFile } from '../engine/sor';
import type { DossierKind, EventKind } from '../engine/types';
import type { TraceEventRow } from '../workflow';

const C_KM_PER_S = 299792.458;

/** Plot margins (axis labels on the left, distances at the bottom). */
export const PLOT_PADDING = { left: 56, right: 8, top: 10, bottom: 24 } as const;

/** Visible distance range, km. */
export type PlotWindow = [number, number];

/** The plot is drawn at scale 1 at the width of its box (texts stay readable on a phone). */
export const plotWidth = (boxWidth: number): number => Math.max(280, Math.round(boxWidth));

export const plotHeight = (width: number): number => Math.round(Math.min(340, Math.max(220, width * 0.5)));

/** Min/max columns over [i0, i1[: the curve stays readable even with 30 000 points. */
export function decimate(data: Float32Array, i0: number, i1: number, columns: number): [number, number, number][] {
    const out: [number, number, number][] = [];
    const n = i1 - i0;
    const step = Math.max(1, n / columns);

    for (let c = 0; c * step < n; c++) {
        const a = i0 + Math.floor(c * step);
        const b = Math.min(i1, i0 + Math.floor((c + 1) * step));
        let lo = Infinity;
        let hi = -Infinity;

        for (let i = a; i < Math.max(b, a + 1); i++) {
            const v = data[i]!;

            if (v < lo) {
                lo = v;
            }

            if (v > hi) {
                hi = v;
            }
        }

        out.push([a, lo, hi]);
    }

    return out;
}

export const traceLengthKm = (sor: SorFile): number => sor.data.length * sor.sampleKm;

const lastEvent = (sor: SorFile): SorEvent | undefined => sor.events.find(isEndEvent) ?? sor.events[sor.events.length - 1];

/** Whole useful trace: up to the last event + 8 % + 50 m. */
export function defaultWindow(sor: SorFile): PlotWindow {
    const full = traceLengthKm(sor);

    return [0, Math.min(full, (lastEvent(sor)?.km ?? full) * 1.08 + 0.05)];
}

/** ±150 m around a position. */
export function focusWindow(sor: SorFile, km: number): PlotWindow {
    return [Math.max(0, km - 0.15), Math.min(traceLengthKm(sor), km + 0.15)];
}

export function zoomWindow(sor: SorFile, [x0, x1]: PlotWindow, factor: number): PlotWindow {
    const centre = (x0 + x1) / 2;
    const half = Math.max(0.01, ((x1 - x0) / 2) * factor);

    return [Math.max(0, centre - half), Math.min(traceLengthKm(sor), centre + half)];
}

export function panWindow(sor: SorFile, [x0, x1]: PlotWindow, factor: number): PlotWindow {
    const width = x1 - x0;
    const start = Math.min(Math.max(0, x0 + width * factor), Math.max(0, traceLengthKm(sor) - width));

    return [start, start + width];
}

export interface PlotGeometry {
    /** SVG path of the curve. */
    path: string;
    /** Level range shown (dB, increasing = attenuation). */
    lo: number;
    hi: number;
    x: (km: number) => number;
    y: (db: number) => number;
}

export function plotGeometry(sor: SorFile, [x0, x1]: PlotWindow, width: number, height: number): PlotGeometry {
    const i0 = Math.max(0, Math.floor(x0 / sor.sampleKm));
    const i1 = Math.min(sor.data.length, Math.ceil(x1 / sor.sampleKm) + 1);
    const columns = decimate(sor.data, i0, i1, Math.min(1200, width));
    let lo = Infinity;
    let hi = -Infinity;

    for (const [, a, b] of columns) {
        lo = Math.min(lo, a);
        hi = Math.max(hi, b);
    }

    // The noise after the fiber end would crush the scale: the range stops 3 dB above the highest useful level.
    const last = lastEvent(sor);
    const endIndex = last ? Math.round(last.km / sor.sampleKm) : i1;
    let usefulHi = -Infinity;

    for (const [i, , b] of columns) {
        if (i <= endIndex && b > usefulHi) {
            usefulHi = b;
        }
    }

    if (Number.isFinite(usefulHi)) {
        hi = Math.min(hi, usefulHi + 3);
    }

    if (hi - lo < 0.5) {
        hi += 0.25;
        lo -= 0.25;
    }

    const x = (km: number) => PLOT_PADDING.left + ((km - x0) / (x1 - x0)) * (width - PLOT_PADDING.left - PLOT_PADDING.right);
    const y = (db: number) => PLOT_PADDING.top + ((db - lo) / (hi - lo)) * (height - PLOT_PADDING.top - PLOT_PADDING.bottom);
    const path = columns.map(([i, a, b], k) => {
        const px = x(i * sor.sampleKm).toFixed(1);

        return `${k ? 'L' : 'M'}${px},${y(Math.min(a, hi)).toFixed(1)}L${px},${y(Math.min(b, hi)).toFixed(1)}`;
    }).join('');

    return { path, lo, hi, x, y };
}

/** Distance ticks (km) of the visible window. */
export function axisTicks([x0, x1]: PlotWindow, width: number): number[] {
    const span = x1 - x0;
    const maxTicks = Math.max(3, Math.floor(width / 90));
    const step = [0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10].find((s) => span / s <= maxTicks) ?? 20;
    const out: number[] = [];

    for (let v = Math.ceil(x0 / step) * step; v <= x1; v += step) {
        out.push(v);
    }

    return out;
}

/** Distance on the trace (km) of a point of the plot, or null in the level axis margin. */
export function plotKm([x0, x1]: PlotWindow, width: number, x: number): number | null {
    if (x < PLOT_PADDING.left) {
        return null;
    }

    return x0 + ((x - PLOT_PADDING.left) / (width - PLOT_PADDING.left - PLOT_PADDING.right)) * (x1 - x0);
}

export type Point = readonly [km: number, db: number];

/**
 * LSA markers of an event: end of the previous section (1), event start (2), end of the dead zone (3), start of the
 * next section (4), and both regression lines extended to the event start.
 */
export interface EventMarkers {
    positions: [number, number, number, number];
    before: readonly [Point, Point];
    after: readonly [Point, Point];
}

export function eventMarkers(sor: SorFile, event: SorEvent): EventMarkers | null {
    if (event.marks.every((m) => m === 0)) {
        return null;
    }

    const kmPerTime = (1e-10 * C_KM_PER_S) / sor.ior;
    const [a, b, c, d] = event.marks.slice(0, 4).map((m) => m * kmPerTime) as [number, number, number, number];
    const index = (km: number) => Math.round(km / sor.sampleKm);
    const lsa = new Lsa(sor.data);
    const before = lsa.fit(index(a), index(b));
    const after = lsa.fit(index(c), index(d));

    if (!before || !after) {
        return null;
    }

    const at = (fit: NonNullable<typeof before>, km: number) => fit.at(km / sor.sampleKm);

    return { positions: [a, b, c, d], before: [[a, at(before, a)], [b, at(before, b)]], after: [[b, at(after, b)], [d, at(after, d)]] };
}

/** A regression segment cut to the visible window. */
export function clipSegment([[ka, da], [kb, db]]: readonly [Point, Point], [x0, x1]: PlotWindow): [Point, Point] {
    const span = kb - ka || 1;
    const start = Math.max(x0, ka);
    const end = Math.min(x1, kb);

    return [[start, da + ((db - da) * (start - ka)) / span], [end, da + ((db - da) * (end - ka)) / span]];
}

// ------------------------------------------------------------------ events of the trace

/** Automatic kind of a row: the first event is the connector, added and detected events are splices. */
export const automaticKind = (row: TraceEventRow, index: number): EventKind => (row.added || row.detected ? 'splice' : index === 0 ? 'connector' : 'splice');

/** Kind shown for a row: forced by a correction, otherwise automatic. */
export function rowKind(row: TraceEventRow, index: number): EventKind {
    const override = row.override;

    if (override?.kind) {
        return override.kind;
    }

    return override?.action === 'connector' || override?.action === 'splice' ? override.action : automaticKind(row, index);
}

/** Colour family of an event marker. */
export type MarkerTone = 'ignored' | 'end' | 'detected' | 'added' | 'event' | 'connector' | 'splice';

export function markerTone(row: TraceEventRow, index: number, kind: DossierKind): MarkerTone {
    if (row.ignored) {
        return 'ignored';
    }

    if (row.end) {
        return 'end';
    }

    if (row.detected) {
        return 'detected';
    }

    if (kind === 'transport') {
        return row.added ? 'added' : 'event';
    }

    return rowKind(row, index) === 'connector' ? 'connector' : row.added ? 'added' : 'splice';
}
