// "Out of limits" control of the standard treatment, like the FiberCable "Alarm thresholds":
// - thresholds of the mean values: value of each event (mean of both directions when bidirectional), slopes,
//   reflectances; a negative value is only in alarm when "Alarm on negative values" is checked;
// - thresholds of the fiber and cable means: mean of the connectors, splices and slopes of each fiber, then of each
//   cable;
// - other thresholds: link budget min / max, ORL; constraint detection (1550 − 1310 gap).
// Only the lines checked "active" are controlled. The result has the shape of the GDCO summary (reused by the screen).

import { message, type Message } from './messages';
import { activeThreshold, type Profile } from './profile';
import type { Anomaly, AnomalyType, Criterion, Light, Summary } from './rules';
import { round3 } from './trace';
import type { Dossier, FiberResult, FileMeta, Lambda } from './types';

const mean = (xs: number[]) => (xs.length ? xs.reduce((a, b) => a + b, 0) / xs.length : null);

type MeanKey = 'fiberConnector' | 'fiberSplice' | 'fiberSlope';

interface MeanValues {
    connectors: number[];
    splices: number[];
    slopes: number[];
}

export function evaluateStandard(d: Dossier, p: Profile): Summary {
    const bidirectional = d.kind === 'transport';
    const an: Anomaly[] = [];
    const meta = new Map<string, FileMeta>(d.files.map((m) => [m.name, m]));
    const over = (v: number, thr: number) => (p.negativeAlarm ? Math.abs(v) : v) > thr;
    let events = 0;
    let sections = 0;
    // Cable means are computed per cable (a dossier can hold several cables).
    const cables = new Map<string, Record<Lambda, MeanValues>>();
    const cableOf = (cableId: string) => {
        let c = cables.get(cableId);

        if (!c) {
            c = { 1310: { connectors: [], splices: [], slopes: [] }, 1550: { connectors: [], splices: [], slopes: [] } };
            cables.set(cableId, c);
        }

        return c;
    };

    for (const f of d.fibers) {
        const l = f.lambda;
        const cable = cableOf(f.cableId)[l];
        const base = { cableId: f.cableId, fiber: f.fiber, lambda: l };
        const thConn = activeThreshold(p, l, 'connector');
        const thSpl = activeThreshold(p, l, 'splice');
        const thRefl = activeThreshold(p, l, 'reflectance');
        const thSlope = activeThreshold(p, l, 'slope');

        for (const e of f.events) {
            events++;
            const thr = e.kind === 'connector' ? thConn : thSpl;

            if (thr !== null && over(e.value, thr)) {
                an.push({ ...base, index: e.index, type: e.kind, value: e.value, threshold: thr, blocking: true });
            }

            (e.kind === 'connector' ? cable.connectors : cable.splices).push(e.value);

            if (thRefl !== null) {
                for (const r of [e.reflOe, e.reflEo]) {
                    if (r !== null && r > thRefl) {
                        an.push({ ...base, index: e.index, type: e.kind === 'connector' ? 'connector_reflectance' : 'splice_reflectance', value: r, threshold: thRefl, blocking: true });
                    }
                }
            }
        }

        for (const s of f.sections) {
            sections++;

            if (s.slope === null) {
                continue;
            }

            cable.slopes.push(s.slope);

            if (thSlope !== null && s.slope > thSlope) {
                an.push({ ...base, index: s.index, type: 'slope', value: s.slope, threshold: thSlope, blocking: true });
            }
        }

        an.push(...fiberMeans(f, p));
        const bMin = activeThreshold(p, l, 'totalLossMin');
        const bMax = activeThreshold(p, l, 'totalLossMax');

        if (bMax !== null && f.totalLoss > bMax) {
            an.push({ ...base, index: 0, type: 'total_loss', value: f.totalLoss, threshold: bMax, blocking: true });
        }

        if (bMin !== null && f.totalLoss < bMin) {
            an.push({ ...base, index: 0, type: 'total_loss', value: f.totalLoss, threshold: bMin, blocking: true });
        }

        const orlMin = activeThreshold(p, l, 'orl');

        if (!bidirectional && orlMin !== null) {
            const orl = meta.get(f.files[0]!)?.orl ?? 0;

            if (orl > 0 && orl < orlMin) {
                an.push({ ...base, index: 0, type: 'orl', value: round3(orl), threshold: orlMin, blocking: true });
            }
        }
    }

    // Cable means (fiber 0 = the whole cable)
    for (const [cableId, values] of cables) {
        for (const l of [1310, 1550] as const) {
            const checks: [number[], MeanKey, AnomalyType][] = [
                [values[l].connectors, 'fiberConnector', 'connector_mean'], [values[l].splices, 'fiberSplice', 'splice_mean'], [values[l].slopes, 'fiberSlope', 'slope_mean'],
            ];

            for (const [vals, k, type] of checks) {
                const m = mean(vals);
                const thr = activeThreshold(p, l, k);

                if (m !== null && thr !== null && over(m, thr)) {
                    an.push({ cableId, fiber: 0, lambda: l, index: 0, type, value: round3(m), threshold: thr, blocking: true });
                }
            }
        }
    }

    if (p.constraint !== null) {
        an.push(...constraints(d.fibers, p.constraint));
    }

    const count = (ty: AnomalyType | AnomalyType[]) => an.filter((a) => (Array.isArray(ty) ? ty.includes(a.type) : a.type === ty)).length;
    const threshold = (labelKey: string, k: 'connector' | 'splice' | 'slope' | 'reflectance'): Message => {
        const a = p.thresholds[1310][k];
        const b = p.thresholds[1550][k];

        return a === b ? message(labelKey, { threshold: a }) : message(`${labelKey}_by_wavelength`, { threshold_1310: a, threshold_1550: b });
    };
    const crit = (label: Message, n: number, detail: Message, active: boolean): Criterion => ({ label, light: (active && n > 0 ? 2 : 0) as Light, detail: active ? detail : message('criterion_not_checked') });
    const A = p.active;
    const means = count(['connector_mean', 'splice_mean', 'slope_mean']);
    const reflectances = count(['connector_reflectance', 'splice_reflectance']);
    const criteria: Criterion[] = [
        crit(threshold('criterion_standard_connectors', 'connector'), count('connector'), message('criterion_detail_out_of_limits', { count: count('connector') }), A.connector),
        crit(threshold('criterion_standard_splices', 'splice'), count('splice'), message('criterion_detail_out_of_limits_events', { count: count('splice'), total: events }), A.splice),
        crit(threshold('criterion_standard_slopes', 'slope'), count('slope'), message('criterion_detail_out_of_limits_sections', { count: count('slope'), total: sections }), A.slope),
        crit(threshold('criterion_standard_reflectances', 'reflectance'), reflectances, message('criterion_detail_out_of_limits', { count: reflectances }), A.reflectance),
        crit(message('criterion_standard_means'), means, message('criterion_detail_out_of_limits_means', { count: means }), A.fiberConnector || A.fiberSplice || A.fiberSlope),
        crit(message('criterion_standard_total_loss'), count('total_loss'), message('criterion_detail_out_of_bounds', { count: count('total_loss') }), A.totalLossMin || A.totalLossMax),
        crit(message('criterion_standard_orl'), count('orl'), message('criterion_detail_below_minimum', { count: count('orl') }), A.orl && !bidirectional),
        crit(
            p.constraint === null ? message('criterion_standard_constraints_disabled') : message('criterion_standard_constraints', { threshold: p.constraint }),
            count('constraint'),
            message('criterion_detail_constraints', { count: count('constraint') }),
            p.constraint !== null,
        ),
    ];
    const light: Light = an.length ? 2 : 0;
    const totals = (l: Lambda) => d.fibers.filter((f) => f.lambda === l).map((f) => f.totalLoss);
    const lengths = d.fibers.filter((f) => f.lambda === 1310).map((f) => f.lengthM);
    const mx = (xs: number[]) => (xs.length ? Math.max(...xs) : 0);

    return {
        light, status: light ? 'KO' : 'OK', criteria, anomalies: an,
        indicators: {
            indicator_profile: p.name,
            indicator_fibers_measured: new Set(d.fibers.map((f) => `${f.cableId}|${f.fiber}`)).size,
            indicator_out_of_limits_values: an.length,
            indicator_max_length_1310: Math.round(mx(lengths) * 100) / 100,
            indicator_mean_total_loss_1310: round3(mean(totals(1310)) ?? 0),
            indicator_max_total_loss_1310: mx(totals(1310)),
            indicator_mean_total_loss_1550: round3(mean(totals(1550)) ?? 0),
            indicator_max_total_loss_1550: mx(totals(1550)),
        },
    };
}

/** Means of a fiber (connectors, splices, slopes) compared to the "fibers and cable" thresholds. */
function fiberMeans(f: FiberResult, p: Profile): Anomaly[] {
    const out: Anomaly[] = [];
    const over = (v: number, thr: number) => (p.negativeAlarm ? Math.abs(v) : v) > thr;
    const checks: [number[], MeanKey, AnomalyType][] = [
        [f.events.filter((e) => e.kind === 'connector').map((e) => e.value), 'fiberConnector', 'connector_mean'],
        [f.events.filter((e) => e.kind === 'splice').map((e) => e.value), 'fiberSplice', 'splice_mean'],
        [f.sections.map((s) => s.slope).filter((v): v is number => v !== null), 'fiberSlope', 'slope_mean'],
    ];

    for (const [vals, k, type] of checks) {
        const m = mean(vals);
        const thr = activeThreshold(p, f.lambda, k);

        if (m !== null && thr !== null && over(m, thr)) {
            out.push({ cableId: f.cableId, fiber: f.fiber, lambda: f.lambda, index: 0, type, value: round3(m), threshold: thr, blocking: true });
        }
    }

    return out;
}

function constraints(fibers: FiberResult[], threshold: number): Anomaly[] {
    const out: Anomaly[] = [];
    const at = new Map(fibers.map((f) => [`${f.cableId}|${f.fiber}|${f.lambda}`, f]));

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

            if (diff > threshold) {
                out.push({ type: 'constraint', cableId: f.cableId, fiber: f.fiber, lambda: null, index: e.index, value: diff, threshold, blocking: true });
            }
        });
    }

    return out;
}
