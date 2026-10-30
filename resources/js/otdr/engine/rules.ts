// Acceptance control — operator measurement rules "Mesures de réflectométrie BLOM" v24.30 and GDCO macro 1.0.34179.18.
// When the rules and the macro differ, the macro prevails (it produces the DCO); gaps are noted below.

import { message, type Message } from './messages';
import { round3 } from './trace';
import type { Dossier, FiberResult, Lambda } from './types';

/** 0 green / 1 orange / 2 red (macro codes). */
export type Light = 0 | 1 | 2;

export interface Thresholds {
    connector: number;
    splice: number;
    slope: Record<Lambda, number>;
    /** Maximum connector reflectance (dB). */
    reflectance: number;
    /** 1550 − 1310 gap beyond which an event is a constraint (dB). */
    constraint: number;
}

export const GDCO_THRESHOLDS: Record<'transport' | 'distribution', Thresholds> = {
    // Rules §2.1 (mean of both directions). Constraint: the rules v24.30 say 0.1 dB, the macro applies 0.2 dB.
    transport: { connector: 0.6, splice: 0.15, slope: { 1310: 0.38, 1550: 0.25 }, reflectance: -55, constraint: 0.2 },
    // Rules §2.2 (single direction). Slopes only tick the slope sheet of the macro (not blocking).
    distribution: { connector: 0.7, splice: 0.3, slope: { 1310: 0.38, 1550: 0.25 }, reflectance: -55, constraint: 0.2 },
};

/** The macro checks the slope of the sections of at least 200 m only (shorter ones are not significant). */
const SLOPE_MIN_LENGTH_M = 200;

/** Failed splice repair report (distribution): neutralises a splice 0.30 < v ≤ 0.70 dB. */
export interface FailedRepair {
    cableId: string;
    fiber: number;
    event: number;
    lambda: Lambda;
    /** "Last repair value": must equal the report value to the thousandth. */
    lastValue: number;
    /** Initial measured value, for the repairs sheet of the DCO. */
    startValue?: number;
    /** Repair date. */
    date?: Date;
}

export type AnomalyType =
    | 'connector' | 'splice' | 'connector_reflectance' | 'splice_reflectance' | 'constraint' | 'slope' | 'length' | 'total_loss' | 'orl'
    | 'connector_mean' | 'splice_mean' | 'slope_mean';

export interface Anomaly {
    type: AnomalyType;
    cableId: string;
    fiber: number;
    lambda: Lambda | null;
    /** Concerned event or section (1…n). */
    index: number;
    value: number;
    threshold: number;
    /** false = information (outside the GDCO light criteria). */
    blocking: boolean;
    neutralized?: boolean;
}

export interface Criterion {
    label: Message;
    light: Light;
    detail: Message;
}

export interface Summary {
    light: Light;
    /** DCO suffix: OK, ~OK or KO. */
    status: 'OK' | '~OK' | 'KO';
    criteria: Criterion[];
    anomalies: Anomaly[];
    /** Indicators in display order; each key is also the translation key of its label. */
    indicators: Record<string, number | string>;
}

const pct = (n: number, d: number) => (d === 0 ? 0 : Math.round((n / d) * 10000) / 100);

/** Splice light: reproduces the macro, including the exact 2 % bound classified green. */
function splicesLight(percentOver: number): Light {
    const ok = 100 - percentOver;

    if (ok > 98 && ok < 100) {
        return 1;
    }

    if (ok < 98) {
        return 2;
    }

    return 0;
}

function constraints(fibers: FiberResult[], t: Thresholds): Anomaly[] {
    const out: Anomaly[] = [];
    const at = new Map<string, FiberResult>();

    for (const f of fibers) {
        at.set(`${f.cableId}|${f.fiber}|${f.lambda}`, f);
    }

    for (const f of fibers) {
        if (f.lambda !== 1310) {
            continue;
        }

        const g = at.get(`${f.cableId}|${f.fiber}|1550`);

        if (!g) {
            continue;
        }

        f.events.forEach((e, i) => {
            const e2 = g.events[i];

            if (!e2) {
                return;
            }

            const diff = round3(e2.value - e.value);

            if (diff > t.constraint) {
                out.push({ type: 'constraint', cableId: f.cableId, fiber: f.fiber, lambda: null, index: e.index, value: diff, threshold: t.constraint, blocking: true });
            }
        });
    }

    return out;
}

export interface DistributionInputs {
    /** Declared NRO-PM length (m), taken from the transport DCO of the same PM. */
    nroPmLengthM?: number;
    /** Maximum NRO-PM attenuation at 1310 nm (dB), taken from the transport DCO. */
    nroPmAttenuation1310?: number;
    failedRepairs?: FailedRepair[];
}

export function evaluateGdco(d: Dossier, inputs: DistributionInputs = {}): Summary {
    return d.kind === 'transport' ? evaluateTransport(d) : evaluateDistribution(d, inputs);
}

function evaluateTransport(d: Dossier): Summary {
    const t = GDCO_THRESHOLDS.transport;
    const an: Anomaly[] = [];
    let splices = 0;
    let splicesOver = 0;
    let connectors = 0;

    for (const f of d.fibers) {
        for (const e of f.events) {
            const base = { cableId: f.cableId, fiber: f.fiber, lambda: f.lambda, index: e.index };

            if (e.kind === 'connector') {
                connectors++;

                if (e.value > t.connector) {
                    an.push({ ...base, type: 'connector', value: e.value, threshold: t.connector, blocking: true });
                }

                for (const r of [e.reflOe, e.reflEo]) {
                    if (r !== null && r > t.reflectance) {
                        an.push({ ...base, type: 'connector_reflectance', value: r, threshold: t.reflectance, blocking: true });
                    }
                }
            } else {
                if (e.value !== 0) {
                    splices++;
                }

                if (e.value > t.splice) {
                    splicesOver++;
                    an.push({ ...base, type: 'splice', value: e.value, threshold: t.splice, blocking: true });
                }

                for (const r of [e.reflOe, e.reflEo]) {
                    if (r !== null) {
                        an.push({ ...base, type: 'splice_reflectance', value: r, threshold: 0, blocking: true });
                    }
                }
            }
        }

        an.push(...slopes(f, t));
    }

    an.push(...constraints(d.fibers, t));
    const lengths = d.fibers.filter((f) => f.lambda === 1310).map((f) => f.lengthM);
    const maxL = lengths.length ? Math.max(...lengths) : 0;
    const minL = lengths.length ? Math.min(...lengths) : 0;
    const spread = maxL - minL;
    const lengthLight: Light = spread > 50 && spread < 100 ? 1 : spread > 100 ? 2 : 0;
    const pctOver = pct(splicesOver, splices);
    const count = (ty: AnomalyType) => an.filter((a) => a.type === ty).length;
    const criteria: Criterion[] = [
        { label: message('criterion_gdco_transport_splices'), light: splicesLight(pctOver), detail: message('criterion_detail_splices_over', { count: splicesOver, total: splices, percent: pctOver }) },
        { label: message('criterion_gdco_transport_connectors'), light: count('connector') ? 2 : 0, detail: message('criterion_detail_over_of', { count: count('connector'), total: connectors }) },
        { label: message('criterion_gdco_connector_reflectance'), light: count('connector_reflectance') ? 2 : 0, detail: message('criterion_detail_over', { count: count('connector_reflectance') }) },
        { label: message('criterion_gdco_splice_reflectance'), light: count('splice_reflectance') ? 2 : 0, detail: message('criterion_detail_reflectances', { count: count('splice_reflectance') }) },
        { label: message('criterion_gdco_constraints'), light: count('constraint') ? 2 : 0, detail: message('criterion_detail_constraints', { count: count('constraint') }) },
        { label: message('criterion_gdco_length_spread'), light: lengthLight, detail: message('criterion_detail_length_spread', { spread: Math.round(spread * 10) / 10 }) },
    ];

    return finish(criteria, an, {
        indicator_fibers_measured: new Set(d.fibers.map((f) => f.fiber)).size,
        indicator_max_length_nro_pm_1310: Math.round(maxL * 100) / 100,
        indicator_min_length_nro_pm_1310: Math.round(minL * 100) / 100,
        indicator_max_attenuation_nro_pm_1310: maxOf(d.fibers.filter((f) => f.lambda === 1310).map((f) => f.totalLoss)),
        indicator_splices_over_015_percent: pctOver,
    });
}

function evaluateDistribution(d: Dossier, inputs: DistributionInputs): Summary {
    const t = GDCO_THRESHOLDS.distribution;
    const an: Anomaly[] = [];
    const repairs = inputs.failedRepairs ?? [];
    let splices = 0;
    let connectors = 0;
    let connReflCount = 0;
    const over = { 0.3: 0, 0.4: 0, 0.5: 0 };
    let repairsUsed = 0;
    let repairsOver07 = 0;

    for (const f of d.fibers) {
        for (const e of f.events) {
            const base = { cableId: f.cableId, fiber: f.fiber, lambda: f.lambda, index: e.index };

            if (e.kind === 'connector') {
                connectors++;

                if (e.value > t.connector) {
                    an.push({ ...base, type: 'connector', value: e.value, threshold: t.connector, blocking: true });
                }

                if (e.reflOe !== null) {
                    connReflCount++;

                    if (e.reflOe > t.reflectance) {
                        an.push({ ...base, type: 'connector_reflectance', value: e.reflOe, threshold: t.reflectance, blocking: true });
                    }
                }

                continue;
            }

            if (e.value !== 0) {
                splices++;
            }

            const match = repairs.find((p) => p.cableId === f.cableId && p.fiber === f.fiber && p.event === e.index && p.lambda === f.lambda && round3(p.lastValue) === e.value);

            if (match) {
                repairsUsed++;

                if (e.value > 0.7) {
                    repairsOver07++;
                }
            }

            const neutralized = !!match && e.value > t.splice && e.value <= 0.7;

            if (e.value > t.splice) {
                an.push({ ...base, type: 'splice', value: e.value, threshold: t.splice, blocking: !neutralized, neutralized });
            }

            if (!neutralized) {
                if (e.value > 0.3) {
                    over[0.3]++;
                }

                if (e.value > 0.4) {
                    over[0.4]++;
                }

                if (e.value > 0.5) {
                    over[0.5]++;
                }
            }

            if (e.reflOe !== null) {
                an.push({ ...base, type: 'splice_reflectance', value: e.reflOe, threshold: 0, blocking: true });
            }
        }

        an.push(...slopes(f, t));
    }

    an.push(...constraints(d.fibers, t));
    const f1310 = d.fibers.filter((f) => f.lambda === 1310);
    const maxPmPbo = maxOf(f1310.map((f) => f.lengthM));
    const maxAtt = maxOf(f1310.map((f) => f.totalLoss));
    const nroPm = inputs.nroPmLengthM;
    const totalLen = nroPm === undefined ? null : nroPm + maxPmPbo;
    const lenLight: Light = totalLen === null ? 0 : totalLen > 23000 && totalLen < 25000 ? 1 : totalLen > 25000 ? 2 : 0;
    const p03 = pct(over[0.3], splices);
    const count = (ty: AnomalyType) => an.filter((a) => a.type === ty && a.blocking).length;
    const criteria: Criterion[] = [
        { label: message('criterion_gdco_distribution_splices'), light: splicesLight(p03), detail: message('criterion_detail_unjustified_splices', { count: over[0.3], total: splices, percent: p03 }) },
        { label: message('criterion_gdco_distribution_connectors'), light: count('connector') ? 2 : 0, detail: message('criterion_detail_over_of', { count: count('connector'), total: connectors }) },
        { label: message('criterion_gdco_connector_reflectance'), light: count('connector_reflectance') ? 2 : 0, detail: message('criterion_detail_over', { count: count('connector_reflectance') }) },
        { label: message('criterion_gdco_splice_reflectance'), light: count('splice_reflectance') ? 2 : 0, detail: message('criterion_detail_reflectances', { count: count('splice_reflectance') }) },
        { label: message('criterion_gdco_constraints'), light: count('constraint') ? 2 : 0, detail: message('criterion_detail_constraints', { count: count('constraint') }) },
        {
            label: message('criterion_gdco_total_length'),
            light: lenLight,
            detail: totalLen === null ? message('criterion_detail_nro_pm_length_missing') : message('criterion_detail_total_length', { length: Math.round(totalLen) }),
        },
    ];
    const pbos = new Set(d.fibers.map((f) => pboOf(f.cableId)));

    return finish(criteria, an, {
        indicator_pbo_covered: pbos.size,
        indicator_rop_measured: new Set(d.fibers.map((f) => `${f.cableId}|${f.fiber}`)).size,
        indicator_max_length_pm_pbo_1310: Math.round(maxPmPbo * 100) / 100,
        indicator_max_attenuation_pm_pbo_1310: maxAtt,
        indicator_unjustified_splices_over_03_percent: p03,
        indicator_unjustified_splices_over_04_percent: pct(over[0.4], splices),
        indicator_unjustified_splices_over_05_percent: pct(over[0.5], splices),
        indicator_splice_reflectance_percent: pct(count('splice_reflectance'), splices),
        indicator_connectors_over_07_percent: pct(count('connector'), connectors),
        indicator_connector_reflectance_over_55_percent: pct(count('connector_reflectance'), connReflCount || connectors),
        indicator_constraints: count('constraint'),
        indicator_failed_repair_splices: repairsUsed,
        indicator_failed_repair_splices_over_07: repairsOver07,
    });
}

/** Out of threshold slopes, reported without blocking the light: like the macro, |slope| on the sections of at least 200 m. */
function slopes(f: FiberResult, t: Thresholds): Anomaly[] {
    return f.sections
        .filter((s) => s.slope !== null && Math.abs(s.slope) > t.slope[f.lambda] && round3(s.lengthM) >= SLOPE_MIN_LENGTH_M)
        .map((s) => ({ cableId: f.cableId, fiber: f.fiber, lambda: f.lambda, index: s.index, type: 'slope' as const, value: s.slope!, threshold: t.slope[f.lambda], blocking: false }));
}

/** "SRO-BPI-1234567_PBO012_B2-T2-MB" → "PBO012" (a PBO can be served by several modules). */
export function pboOf(cableId: string): string {
    return cableId.replace(/^SRO-BPI-\d+_/i, '').replace(/_B\d+-T\d+-M[A-Z0-9]+$/i, '');
}

function maxOf(xs: number[]): number {
    return xs.length ? Math.max(...xs) : 0;
}

function finish(criteria: Criterion[], anomalies: Anomaly[], indicators: Summary['indicators']): Summary {
    const light = criteria.reduce<Light>((m, c) => (c.light > m ? c.light : m), 0);

    return { light, status: light === 0 ? 'OK' : light === 1 ? '~OK' : 'KO', criteria, anomalies, indicators };
}
