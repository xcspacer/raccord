// Text export in the FiberCable 2 format ("Version tableau dossier" 18): same structure, same table positions,
// UTF-16LE + BOM, tabs, decimal comma. The file identifies the configured product (line 2) and does not carry the
// FiberCable integrity fingerprint (CRC): it does not pretend to be a FiberCable export.
// The "mirror" blocks carry the FiberCable alarm flags: 1 when |value| exceeds the header threshold.
// Known gaps: empty OTDR calibration date (missing from the standard .sor blocks), resolution = real sampling step.

import type { AcquisitionConfig, Profile, ThresholdKey } from '../engine/profile';
import type { Dossier, EventResult, FiberResult, FileMeta, Lambda } from '../engine/types';
import { TXT } from './format';

/**
 * FiberCable settings of the reference deliverables validated by the operator (rules v24.30): header thresholds and
 * alarm flags, as written in the delivered FiberCable exports. The GDCO light applies the macro rules (engine/rules).
 */
export const REFERENCE_TXT_THRESHOLDS: Record<Dossier['kind'], {
    connector: number;
    splice: number;
    /** Slope thresholds per wavelength (null: not checked). */
    slope: Record<Lambda, number> | null;
    reflectance: number;
    constraint: number;
    /** 1625 nm column of the FiberCable profile (header only, never measured). */
    nm1625: { connector: number; splice: number; slope: number; fiberConnector: number; fiberSplice: number; fiberSlope: number; reflectance: number } | null;
}> = {
    transport: {
        connector: 0.6, splice: 0.15, slope: { 1310: 0.38, 1550: 0.25 }, reflectance: -55, constraint: 0.5,
        nm1625: { connector: 1, splice: 0.4, slope: 0.3, fiberConnector: 1, fiberSplice: 0.2, fiberSlope: 0.28, reflectance: -40 },
    },
    distribution: { connector: 0.7, splice: 0.3, slope: null, reflectance: -55, constraint: 0.2, nm1625: null },
};

/** Reference settings of the operator expressed as a profile (same thresholds for the events and the fiber means). */
export function referenceProfile(kind: Dossier['kind']): Profile {
    const c = REFERENCE_TXT_THRESHOLDS[kind];
    const th = (lambda: Lambda) => {
        const slope = c.slope?.[lambda] ?? 0.38;

        return { connector: c.connector, splice: c.splice, slope, reflectance: c.reflectance, fiberConnector: c.connector, fiberSplice: c.splice, fiberSlope: slope, totalLossMin: 0, totalLossMax: 9999, orl: 0 };
    };

    return {
        id: `reference-${kind}`, name: TXT.referenceProfileName, constraint: c.constraint, negativeAlarm: true, ignoreMissingSlopes: true,
        active: { connector: true, splice: true, slope: c.slope !== null, reflectance: true, fiberConnector: true, fiberSplice: true, fiberSlope: c.slope !== null, totalLossMin: false, totalLossMax: false, orl: false },
        thresholds: { 1310: th(1310), 1550: th(1550) },
    };
}

type Cell = string;

function fx(v: number | null | undefined, d: number): Cell {
    if (v === null || v === undefined || !Number.isFinite(v)) {
        return '';
    }

    const r = Number(v.toFixed(d));

    return (r === 0 ? 0 : r).toFixed(d).replace('.', ',');
}

const f3 = (v: number | null | undefined) => fx(v, 3);
const f2 = (v: number | null | undefined) => fx(v, 2);

const mean = (xs: number[]): number | null => (xs.length ? xs.reduce((a, b) => a + b, 0) / xs.length : null);
/** Mean of .sor values to the thousandth, rounded half up on whole thousandths. */
const meanMilli = (xs: number[]): number | null =>
    xs.length ? Math.round(xs.reduce((a, b) => a + Math.round(b * 1000), 0) / xs.length) / 1000 : null;

const pad2 = (n: number) => String(n).padStart(2, '0');

function fcDate(d: Date): string {
    return `${pad2(d.getUTCDate())}/${pad2(d.getUTCMonth() + 1)}/${d.getUTCFullYear()} ${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())}:${pad2(d.getUTCSeconds())}${TXT.information.utc}`;
}

const cmp = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);
const rowOrder = (a: FiberResult, b: FiberResult) => a.lambda - b.lambda || cmp(a.cableId, b.cableId) || a.fiber - b.fiber;

function repeat<T>(n: number, f: (i: number) => T[]): T[] {
    const out: T[] = [];

    for (let i = 0; i < n; i++) {
        out.push(...f(i));
    }

    return out;
}

interface Layout {
    kind: Dossier['kind'];
    p: Profile;
    bidirectional: boolean;
    nEvents: number;
    nSections: number;
    rows: FiberResult[];
    /** Fibers (cable + number) for the constraint evaluation. */
    fibers: { cableId: string; fiber: number; r1310?: FiberResult; r1550?: FiberResult }[];
    meta: Map<string, FileMeta>;
    spools: Cell[];
}

function layout(d: Dossier, p: Profile): Layout {
    const rows = [...d.fibers].sort(rowOrder);
    const byFiber = new Map<string, Layout['fibers'][number]>();

    for (const r of rows) {
        const k = `${r.cableId}|${r.fiber}`;
        const e = byFiber.get(k) ?? { cableId: r.cableId, fiber: r.fiber };

        if (r.lambda === 1310) {
            e.r1310 = r;
        } else {
            e.r1550 = r;
        }

        byFiber.set(k, e);
    }

    return {
        kind: d.kind,
        p,
        bidirectional: d.kind === 'transport',
        nEvents: Math.max(1, ...rows.map((r) => r.events.length)),
        nSections: Math.max(1, ...rows.map((r) => r.sections.length)),
        rows,
        fibers: [...byFiber.values()].sort((a, b) => cmp(a.cableId, b.cableId) || a.fiber - b.fiber),
        meta: new Map(d.files.map((m) => [m.name, m])),
        spools: spoolFlags(d.acquisition, d.kind === 'transport').map(String),
    };
}

// ------------------------------------------------------------------ header

/**
 * "Nombre de bobines amorce" row: launch spools, loop-back spools, receive spools, (empty), measurement type (2 =
 * loop, 0 = classic). Written from the applied acquisition settings (the reference deliverables give "1 1 1 _ 2" for a
 * looped transport and "1 0 0 _ 0" for a classic distribution).
 */
function spoolsRow(d: Dossier): Cell[] {
    const a = d.acquisition;

    return [TXT.header.spools, String(a.startSpools), String(a.loop ? a.loopSpools : 0), String(a.endSpools), '', a.loop ? '2' : '0'];
}

/**
 * Spool flags of each measurement (FiberCable "Amorces de début", "Amorce", "Amorce de fin", also copied by the GDCO
 * macro into "BOUCLAGE"): −1 = present, 0 = absent. "Amorce" is the loop-back spool of a loop (0 for a direct joint).
 */
export function spoolFlags(a: AcquisitionConfig, bidirectional: boolean): [-1 | 0, -1 | 0, -1 | 0] {
    const flag = (present: boolean) => (present ? -1 : 0);

    return [flag(a.startSpools >= 1), flag(a.loop ? a.loopSpools >= 1 : bidirectional), flag(bidirectional && a.endSpools >= 1)];
}

function header(d: Dossier, L: Layout): Cell[][] {
    const H = TXT.header;
    const first = L.rows[0] ? L.meta.get(L.rows[0].files[0]!) : undefined;
    const at = (lambda: number) => {
        const r = L.rows.find((x) => x.lambda === lambda);

        return r ? L.meta.get(r.files[0]!) : undefined;
    };
    const m1310 = at(1310);
    const m1550 = at(1550);
    const t1 = L.p.thresholds[1310];
    const t5 = L.p.thresholds[1550];
    const tr = d.kind === 'transport';
    const on = (k: ThresholdKey, v: number) => (L.p.active[k] && Number.isFinite(v) ? f3(v) : '9999');
    // 1625 nm column: only the reference profile carries the FiberCable values (never measured).
    const nm1625 = L.p.id === referenceProfile(d.kind).id ? REFERENCE_TXT_THRESHOLDS[d.kind].nm1625 : null;
    const c1625 = (k: 'connector' | 'splice' | 'slope' | 'fiberConnector' | 'fiberSplice' | 'fiberSlope') => (nm1625 ? f3(nm1625[k]) : '9999');
    // Cable and project labels: written by FiberCable in the transport exports, empty in the distribution exports.
    const label = (text: string): Cell[] => [tr ? text : '', ''];
    const termination = tr ? d.termination : (first?.termination ?? '');
    const included = (v: boolean) => (v ? '1' : '0');

    return [
        [H.version, H.versionNumber, H.generator(), '', '', '', '', '', '', '1,234', '', ''],
        [H.origin, d.origin],
        [H.extremity, termination],
        [H.otdr, first?.otdr ?? '', ''],
        [H.otdrSerial, first?.otdrSerial ?? '', ''],
        // cable manufacturer, cable type, fiber manufacturer/type, project manager, connection: not filled
        label(H.cableManufacturer),
        label(H.cableType),
        label(H.fiberManufacturer),
        label(H.fiberType),
        label(H.projectManager),
        label(H.connection),
        [H.module, first?.module ?? '', ''],
        [H.moduleSerial, first?.moduleSerial ?? '', ''],
        [H.pulse, String(first?.pulseNs ?? ''), '', String(m1310?.pulseNs ?? ''), String(m1550?.pulseNs ?? ''), ''],
        [H.ior, fx(first?.ior, 5), '', fx(m1310?.ior, 5), fx(m1550?.ior, 5), ''],
        [H.fibers, String(L.rows.length)],
        [H.splices, String(L.nEvents)],
        [H.sections, String(L.nSections)],
        spoolsRow(d),
        [H.constraintDetection, L.p.constraint === null ? '9999' : f3(L.p.constraint)],
        [H.lambda, H.nm1310, H.nm1550, H.nm1625],
        [H.realWavelength, '1310', '1550', ''],
        [H.backscatter, f2(m1310?.backscatterDb), f2(m1550?.backscatterDb), ''],
        [H.connectorThresholds, on('connector', t1.connector), on('connector', t5.connector), c1625('connector')],
        [H.spliceThresholds, on('splice', t1.splice), on('splice', t5.splice), c1625('splice')],
        [H.slopeThresholds, on('slope', t1.slope), on('slope', t5.slope), c1625('slope')],
        [H.meanConnectorThresholds, on('fiberConnector', t1.fiberConnector), on('fiberConnector', t5.fiberConnector), c1625('fiberConnector')],
        [H.meanSpliceThresholds, on('fiberSplice', t1.fiberSplice), on('fiberSplice', t5.fiberSplice), c1625('fiberSplice')],
        [H.meanSlopeThresholds, on('fiberSlope', t1.fiberSlope), on('fiberSlope', t5.fiberSlope), c1625('fiberSlope')],
        [H.reflectanceThresholds, L.p.active.reflectance ? f2(t1.reflectance) : '0', L.p.active.reflectance ? f2(t5.reflectance) : '0', nm1625 ? f2(nm1625.reflectance) : '0'],
        [H.meanReflectanceThresholds, '0', '0', '0'],
        [H.spoolsRemoved, H.yes, '1'],
        [H.connectorsRemoved, H.no, '0', '', '', '', '', '', '', H.connectorsIncluded, included(d.acquisition.totalLossInput), included(d.acquisition.totalLossOutput)],
        tr ? [H.direction, H.bidirectional, '0'] : [H.direction, H.unidirectional, '1'],
        [H.totalLossMax, L.p.active.totalLossMax ? f3(t1.totalLossMax) : '9999', L.p.active.totalLossMax ? f3(t5.totalLossMax) : '9999', '9999'],
        [H.totalLossMin, L.p.active.totalLossMin ? f3(t1.totalLossMin) : '0', L.p.active.totalLossMin ? f3(t5.totalLossMin) : '0', '0'],
        [H.orlMax, L.p.active.orl ? f2(t1.orl) : '0', L.p.active.orl ? f2(t5.orl) : '0', '0'],
    ];
}

// ------------------------------------------------------------------ tables

const triple = (L: Layout, oe: number | null | undefined, eo: number | null | undefined, v: number | null | undefined, fmt: (x: number | null | undefined) => Cell): Cell[] =>
    L.bidirectional ? [fmt(oe), fmt(eo), fmt(v)] : ['', '', fmt(v)];

/** Active alarm threshold (otherwise infinite): event values, or fiber means (`fiber`). */
function thr(L: Layout, lambda: Lambda, what: 'connector' | 'splice' | 'slope', fiber = false): number {
    const k: ThresholdKey = fiber ? (what === 'connector' ? 'fiberConnector' : what === 'splice' ? 'fiberSplice' : 'fiberSlope') : what;

    return L.p.active[k] ? L.p.thresholds[lambda][k] : Infinity;
}

/** FiberCable alarm flag: "1" when |v| exceeds the threshold. */
const flag = (v: number | null | undefined, threshold: number): Cell => (v !== null && v !== undefined && Math.abs(v) > threshold ? '1' : '0');

function attenuationRow(L: Layout, r: FiberResult, mirror: boolean): Cell[] {
    const k = L.nEvents;
    const ev = (i: number): Cell[] => {
        const e = r.events[i];

        if (!e) {
            return ['', '', ''];
        }

        const th = thr(L, r.lambda, e.kind === 'connector' ? 'connector' : 'splice');

        // Bidirectional: FiberCable only raises the alarm on the mean.
        if (mirror) {
            return L.bidirectional ? ['0', '0', flag(e.value, th)] : ['', '', flag(e.value, th)];
        }

        return triple(L, e.oe, e.eo, e.value, f3);
    };
    const splices = r.events.filter((e) => e.kind === 'splice');

    if (L.bidirectional) {
        const c1 = r.events[0];
        const ck = r.loopJoint ?? r.events[r.events.length - 1];
        const conn = [c1?.oe, ck?.oe, c1?.eo, ck?.eo].map((x) => f3(x));
        // The shared loop-back joint is shown but averaged on the first fiber of the pair only.
        const own = r.loopJoint ? [c1?.oe, c1?.eo] : [c1?.oe, ck?.oe, c1?.eo, ck?.eo];
        const connVals = own.filter((x): x is number => x !== null && x !== undefined);
        const splVals = splices.flatMap((e) => [e.oe, e.eo]).filter((x): x is number => x !== null);
        const totals = mirror ? ['0', '0', '0'] : [f3(r.totalLossOe), f3(r.totalLossEo), f3(r.totalLoss)];
        const mc = meanMilli(connVals);
        const msp = meanMilli(splVals);
        const tail = mirror ? [flag(mc, thr(L, r.lambda, 'connector', true)), msp === null ? '' : flag(msp, thr(L, r.lambda, 'splice', true))] : [f3(mc), f3(msp)];

        return [r.cableId, String(r.fiber), String(r.lambda), ...repeat(k, ev), ...totals, ...conn, ...tail, ''];
    }

    const totals = mirror ? ['', '', '0'] : ['', '', f3(r.totalLoss)];
    const mc = r.events[0]?.value;
    const msp = mean(splices.map((e) => e.value));
    const tail = mirror ? [flag(mc, thr(L, r.lambda, 'connector', true)), msp === null ? '' : flag(msp, thr(L, r.lambda, 'splice', true))] : [f3(mc), f3(msp)];

    return [r.cableId, String(r.fiber), String(r.lambda), ...repeat(k, ev), ...totals, '0,000', '0,000', ...tail, ''];
}

function meanLines(L: Layout, n: number, value: (r: FiberResult, i: number) => number | null | undefined, mirror: boolean): Cell[][] {
    return [1310, 1550].map((lambda) => {
        const rows = L.rows.filter((r) => r.lambda === lambda);

        return [
            TXT.columns.mean, '', String(lambda),
            ...repeat(n, (i) => {
                const vals = rows.map((r) => value(r, i)).filter((x): x is number => x !== null && x !== undefined);

                return ['', '', vals.length ? (mirror ? '0' : f3(mean(vals))) : ''];
            }),
            '',
        ];
    });
}

function slopeRow(L: Layout, r: FiberResult, mirror: boolean): Cell[] {
    const sec = (j: number): Cell[] => {
        const s = r.sections[j];

        if (!s || (s.slope === null && !L.bidirectional)) {
            return ['', '', ''];
        }

        if (mirror) {
            return L.bidirectional ? ['0', '0', flag(s.slope, thr(L, r.lambda, 'slope'))] : ['', '', flag(s.slope, thr(L, r.lambda, 'slope'))];
        }

        return triple(L, s.slopeOe, s.slopeEo, s.slope, f3);
    };
    const avg = mean(r.sections.map((s) => s.slope).filter((x): x is number => x !== null));

    return [r.cableId, String(r.fiber), String(r.lambda), ...repeat(L.nSections, sec), avg === null ? '' : mirror ? flag(avg, thr(L, r.lambda, 'slope', true)) : f3(avg), ''];
}

function reflectanceRow(L: Layout, r: FiberResult, mirror: boolean): Cell[] {
    const ev = (i: number): Cell[] => {
        const e = r.events[i];
        const cell = (v: number | null | undefined) => (v === null || v === undefined ? '' : mirror ? (L.p.active.reflectance && v > L.p.thresholds[r.lambda].reflectance ? '1' : '0') : f2(v));

        return [cell(e?.reflOe), L.bidirectional ? cell(e?.reflEo) : ''];
    };

    return [r.cableId, String(r.fiber), String(r.lambda), ...repeat(L.nEvents, ev), ''];
}

function lengthRow(L: Layout, r: FiberResult, mirror: boolean): Cell[] {
    const sec = (j: number): Cell[] => {
        const s = r.sections[j];

        if (!s) {
            return ['', '', ''];
        }

        if (mirror) {
            return L.bidirectional ? ['0', '0', '0'] : ['', '', '0'];
        }

        return triple(L, s.lengthOe, s.lengthEo, s.lengthM, f3);
    };

    return [r.cableId, String(r.fiber), String(r.lambda), ...repeat(L.nSections, sec), ''];
}

function constraintRow(L: Layout, f: Layout['fibers'][number], mirror: boolean): Cell[] {
    const ev = (i: number): Cell[] => {
        const a: EventResult | undefined = f.r1310?.events[i];
        const b: EventResult | undefined = f.r1550?.events[i];

        if (!a && !b) {
            return ['', '', ''];
        }

        if (mirror) {
            return [a ? '0' : '', b ? '0' : '', a && b ? (L.p.constraint !== null && b.value - a.value > L.p.constraint ? '1' : '0') : ''];
        }

        return [f3(a?.value), f3(b?.value), a && b ? f3(Number((b.value - a.value).toFixed(3))) : ''];
    };

    return [f.cableId, String(f.fiber), '', ...repeat(L.nEvents, ev), ''];
}

function informationRow(L: Layout, r: FiberResult): Cell[] {
    const c: Cell[] = new Array<Cell>(60).fill('');
    c[0] = r.cableId;
    c[1] = String(r.fiber);
    c[2] = String(r.lambda);
    c[17] = r.cableId;
    c[18] = r.cableId;
    c[19] = String(r.fiber);
    c[20] = String(r.fiber);
    // Loop: FiberCable stores the pair of files on the odd fiber only.
    const loopPartnerRow = L.bidirectional && r.fiber % 2 === 0 && L.rows.some((x) => x.cableId === r.cableId && x.lambda === r.lambda && x.fiber === r.fiber - 1);

    if (loopPartnerRow) {
        return c;
    }

    r.files.slice(0, L.bidirectional ? 2 : 1).forEach((name, s) => {
        const m = L.meta.get(name);

        if (!m) {
            return;
        }

        c[3 + s] = m.name;
        c[7 + s] = fcDate(m.date);
        c[11 + s] = m.comment;
        // ORL of a looped trace is not meaningful: empty, like FiberCable.
        c[15 + s] = m.orl && !L.bidirectional ? f2(m.orl) : '';
        c[25 + s] = m.fiberId;
        c[27 + s] = m.otdr;
        c[29 + s] = m.otdrSerial;
        c[31 + s] = m.module;
        c[33 + s] = m.moduleSerial;
        c[35 + s] = String(m.pulseNs);
        c[37 + s] = String(m.averagingTimeS);
        c[39 + s] = f3(m.rangeKm);
        c[41 + s] = f2(m.sampleM);
        c[43 + s] = fx(m.ior, 5);
        c[45 + s] = m.operator;
        c[47 + s] = m.origin;
        c[49 + s] = m.termination;
        c[51 + s] = L.spools[0]!;
        c[53 + s] = L.spools[1]!;
        c[55 + s] = L.spools[2]!;
        c[57 + s] = '0';
    });

    if (!L.bidirectional) {
        c[26] = c[25]!;
    }

    return c;
}

function informationHeader(): Cell[] {
    const c: Cell[] = new Array<Cell>(60).fill('');

    for (const [i, v] of TXT.information.headers) {
        c[i] = v;
    }

    return c;
}

function informationSubHeader(bidirectional: boolean): Cell[] {
    const c: Cell[] = new Array<Cell>(60).fill('');

    for (const i of [3, 7, 11, 23, 27, 29, 31, 33, 35, 37, 39, 41, 43, 45, 47, 49, 51, 53, 55, 57]) {
        c[i] = TXT.information.oe;
        c[i + 1] = TXT.information.eo;
    }

    c[15] = TXT.information.orlOe;
    c[16] = bidirectional ? TXT.information.orlEo : TXT.information.orlOe;

    return c;
}

// ------------------------------------------------------------------ assembly

/** @param profile thresholds written in the header and used for the flags (default: operator reference deliverable). */
export function buildFiberCableTxt(d: Dossier, profile: Profile = referenceProfile(d.kind)): string {
    const T = TXT.columns;
    const S = TXT.sections;
    const L = layout(d, profile);
    const k = L.nEvents;
    const ns = L.nSections;
    const lines: Cell[][] = [];
    const push = (...ls: Cell[][]) => lines.push(...ls);
    const blank = (): Cell[] => [''];
    const starts: Record<string, number> = {};
    // +1: line 1 (table index) is added at the end
    const mark = (name: string) => {
        starts[name] = lines.length + 1 + 1;
    };

    push(...header(d, L));

    for (const l of TXT.middle) {
        push(l.split('\t'));
    }

    // Attenuations
    const evHead = repeat(k, () => [T.oe, T.eo, T.mean]);
    const evNums = repeat(k, (i) => [String(i + 1), String(i + 1), String(i + 1)]);
    push([S.attenuations]);

    if (L.bidirectional) {
        push([T.cableId, T.fiber, T.lambda, ...evHead, T.oe, T.eo, T.mean, T.oe, T.eo, T.oe, T.eo, T.meanConnectors, T.meanSplices, '']);
        push(['', '', '', ...evNums, T.totalLoss, T.totalLoss, T.totalLoss, T.connectors, T.connectors, T.connectors, T.connectors, '', '', '']);
    } else {
        push([T.cableId, T.fiber, T.lambda, ...evHead, T.oe, T.eo, T.mean, T.oe, T.eo, T.meanConnectors, T.meanSplices, '']);
        push(['', '', '', ...evNums, T.totalLoss, T.totalLoss, T.totalLoss, T.connectors, T.connectors, '', '', '']);
    }

    const evValue = (r: FiberResult, i: number) => r.events[i]?.value;
    mark('atten');

    for (const r of L.rows) {
        push(attenuationRow(L, r, false));
    }

    push(...meanLines(L, k, evValue, false), blank(), blank());
    mark('attenM');

    for (const r of L.rows) {
        push(attenuationRow(L, r, true));
    }

    push(...meanLines(L, k, evValue, true), blank(), blank());

    // Slopes
    const secHead = repeat(ns, () => [T.oe, T.eo, T.mean]);
    const secNums = repeat(ns, (i) => [String(i + 1), String(i + 1), String(i + 1)]);
    const slopeValue = (r: FiberResult, j: number) => r.sections[j]?.slope;
    push([S.slopes], [T.cableId, T.fiber, T.lambda, ...secHead, T.meanSlopes, ''], ['', '', '', ...secNums, '', '']);
    mark('slope');

    for (const r of L.rows) {
        push(slopeRow(L, r, false));
    }

    push(...meanLines(L, ns, slopeValue, false), blank(), blank());
    mark('slopeM');

    for (const r of L.rows) {
        push(slopeRow(L, r, true));
    }

    push(...meanLines(L, ns, slopeValue, true), blank(), blank());

    // Reflectances
    push([S.reflectances],
        [T.cableId, T.fiber, T.lambda, ...repeat(k, () => [T.oe, T.eo]), ''],
        ['', '', '', ...repeat(k, (i) => [String(i + 1), String(i + 1)]), '']);
    mark('refl');

    for (const r of L.rows) {
        push(reflectanceRow(L, r, false));
    }

    push(blank(), blank());
    mark('reflM');

    for (const r of L.rows) {
        push(reflectanceRow(L, r, true));
    }

    push(blank(), blank());

    // Lengths
    push([S.lengths], [T.cableId, T.fiber, T.lambda, ...secHead, ''], ['', '', '', ...secNums, '']);
    mark('length');

    for (const r of L.rows) {
        push(lengthRow(L, r, false));
    }

    push(blank(), blank());
    mark('lengthM');

    for (const r of L.rows) {
        push(lengthRow(L, r, true));
    }

    push(blank(), blank());

    // Constraints
    push([S.constraints],
        [T.cableId, T.fiber, '', ...repeat(k, () => [T.nm1310, T.nm1550, T.difference]), ''],
        ['', '', '', ...evNums, '']);
    mark('constr');

    for (const f of L.fibers) {
        push(constraintRow(L, f, false));
    }

    push(blank(), blank());
    mark('constrM');

    for (const f of L.fibers) {
        push(constraintRow(L, f, true));
    }

    push(blank(), blank());

    // Measurement information
    push([S.information], informationHeader(), informationSubHeader(L.bidirectional));
    mark('info');

    for (const r of L.rows) {
        push(informationRow(L, r));
    }

    const tailStart = lines.length + 2;

    for (const l of TXT.tail) {
        push(l.split('\t'));
    }

    push(['']);

    const pos = (name: string) => starts[name]!;
    const idx: (number | string)[] = [
        pos('atten'), 4, 3, pos('slope'), 4, 3, pos('refl'), 4, 2, pos('length'), 4, 3,
        tailStart + 5, 3, 4, tailStart + 10, 28, 2, tailStart + 15, 3, 2, tailStart + 20, 6, 0,
        pos('info'), 4, 4, tailStart + 25, 11, 5,
        pos('attenM'), 4, 3, pos('slopeM'), 4, 3, pos('reflM'), 4, 2, pos('lengthM'), 4, 3, pos('constr'), 4, 3, pos('constrM'), 4, 3,
    ];
    const first = [TXT.tableIndex, ...idx.map(String)];

    return [first, ...lines].map((c) => c.join('\t')).join('\r\n');
}

/** File encoding: UTF-16LE with BOM, like FiberCable. */
export function encodeUtf16le(text: string): Uint8Array {
    const out = new Uint8Array(2 + text.length * 2);
    out[0] = 0xff;
    out[1] = 0xfe;

    for (let i = 0; i < text.length; i++) {
        const c = text.charCodeAt(i);
        out[2 + 2 * i] = c & 0xff;
        out[3 + 2 * i] = c >> 8;
    }

    return out;
}

/** File name of the operator deliverable (rules §8.1 step 5). */
export function txtFileName(d: Dossier, pm: string, transportType: 'FTTH' | 'FTTE' = 'FTTH'): string {
    return TXT.fileName(pm, d.kind, transportType);
}
