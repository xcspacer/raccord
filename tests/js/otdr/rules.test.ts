// GDCO acceptance rules (measurement rules v24.30 + GDCO macro 1.0.34179.18).
import { describe, expect, it } from 'vitest';
import { analyze, DEFAULT_ACQUISITION, evaluateGdco, pboOf, type Dossier, type FiberResult, type Lambda } from '../../../resources/js/otdr';
import { loadSorFolder, REFERENCE, SETS } from './support';

/** Synthetic distribution fiber: connector + splices (O→E values). */
function distFiber(fiber: number, lambda: Lambda, values: number[], opts: { refl?: (number | null)[]; cable?: string; lengthM?: number } = {}): FiberResult {
    return {
        cableId: opts.cable ?? 'SRO-BPI-1_PBO001_B1-T1-MA', fiber, lambda,
        events: values.map((v, i) => ({
            index: i + 1, num: i + 1, kind: i === 0 ? 'connector' : 'splice', positionM: i * 100, traceM: 500 + i * 100,
            oe: v, eo: null, value: v, reflOe: opts.refl?.[i] ?? null, reflEo: null,
        })),
        sections: [], totalLossOe: null, totalLossEo: null, totalLoss: 0, totalLossEstimated: false,
        lengthM: opts.lengthM ?? 500, files: [], date: new Date(0), warnings: [], edited: false,
    };
}

function transportFiber(fiber: number, lambda: Lambda, values: number[], lengthM = 1000): FiberResult {
    const f = distFiber(fiber, lambda, values, { cable: '9900000001_9900001_B1-T4-AU', lengthM });
    f.events.forEach((e, i) => {
        e.kind = i === 0 || i === values.length - 1 ? 'connector' : 'splice';
        e.eo = e.oe;
    });

    return f;
}

const dossier = (kind: 'transport' | 'distribution', fibers: FiberResult[]): Dossier => ({
    kind, mode: kind === 'transport' ? 'loop' : 'unidirectional', origin: '', termination: '', fibers, files: [], warnings: [], acquisition: DEFAULT_ACQUISITION,
});

/** n compliant fibers at both wavelengths, plus the given fibers. */
function distWith(n: number, extra: FiberResult[]): Dossier {
    const fibers: FiberResult[] = [];

    for (let i = 1; i <= n; i++) {
        for (const l of [1310, 1550] as const) {
            fibers.push(distFiber(i, l, [0.4, 0.05]));
        }
    }

    return dossier('distribution', [...fibers, ...extra]);
}

describe('distribution', () => {
    it('compliant dossier → OK', () => {
        expect(evaluateGdco(distWith(10, [])).status).toBe('OK');
    });

    it('splice > 0.30 dB: orange when 98–100 % compliant, red below', () => {
        // 100 fibers × 2 λ = 200 compliant splices + 1 out of threshold → 99.5 % compliant
        const s1 = evaluateGdco(distWith(100, [distFiber(101, 1310, [0.4, 0.35])]));
        expect(s1.status).toBe('~OK');
        // 10 × 2 = 20 compliant + 1 → 95 % compliant
        const s2 = evaluateGdco(distWith(10, [distFiber(11, 1310, [0.4, 0.35])]));
        expect(s2.status).toBe('KO');
    });

    it('exact 2 % bound out of threshold classified green, like the macro', () => {
        // 49 × 2 = 98 compliant + 1 fiber with 2 splices out of threshold → 2 / 100 = 2 %
        const s = evaluateGdco(distWith(49, [distFiber(50, 1310, [0.4, 0.35, 0.36])]));
        expect(s.criteria[0]!.light).toBe(0);
        expect(s.criteria[0]!.detail).toEqual({ key: 'criterion_detail_unjustified_splices', params: { count: 2, total: 100, percent: 2 } });
    });

    it('failed repair report: neutralises 0.30 < v ≤ 0.70 when the value matches to the thousandth', () => {
        const bad = distFiber(11, 1310, [0.4, 0.35]);
        const pv = { cableId: bad.cableId, fiber: 11, event: 2, lambda: 1310 as const, lastValue: 0.35 };
        expect(evaluateGdco(distWith(10, [bad]), { failedRepairs: [pv] }).status).toBe('OK');
        expect(evaluateGdco(distWith(10, [bad]), { failedRepairs: [{ ...pv, lastValue: 0.351 }] }).status).toBe('KO');
        const veryBad = distFiber(11, 1310, [0.4, 0.75]);
        const s = evaluateGdco(distWith(10, [veryBad]), { failedRepairs: [{ ...pv, lastValue: 0.75 }] });
        expect(s.status).toBe('KO');
        expect(s.indicators.indicator_failed_repair_splices_over_07).toBe(1);
    });

    it('connector > 0.7 dB, connector reflectance > −55 dB, splice reflectance → red', () => {
        expect(evaluateGdco(distWith(10, [distFiber(11, 1310, [0.71, 0.05])])).status).toBe('KO');
        expect(evaluateGdco(distWith(10, [distFiber(11, 1310, [0.4, 0.05], { refl: [-54.9, null] })])).status).toBe('KO');
        expect(evaluateGdco(distWith(10, [distFiber(11, 1310, [0.4, 0.05], { refl: [-60, -48] })])).status).toBe('KO');
        expect(evaluateGdco(distWith(10, [distFiber(11, 1310, [0.4, 0.05], { refl: [-55, null] })])).status).toBe('OK');
    });

    it('constraint: 1550 − 1310 > 0.2 dB on a same event → red', () => {
        const d = dossier('distribution', [distFiber(1, 1310, [0.4, 0.05]), distFiber(1, 1550, [0.4, 0.26])]);
        const s = evaluateGdco(d);
        expect(s.status).toBe('KO');
        expect(s.anomalies.find((a) => a.type === 'constraint')).toMatchObject({ fiber: 1, index: 2, value: 0.21 });
        const ok = dossier('distribution', [distFiber(1, 1310, [0.4, 0.05]), distFiber(1, 1550, [0.4, 0.25])]);
        expect(evaluateGdco(ok).status).toBe('OK');
    });

    it('NRO-PM-PBO length: orange > 23 km, red > 25 km', () => {
        const d = dossier('distribution', [distFiber(1, 1310, [0.4, 0.05], { lengthM: 2000 })]);
        expect(evaluateGdco(d, { nroPmLengthM: 20000 }).status).toBe('OK');
        expect(evaluateGdco(d, { nroPmLengthM: 22000 }).status).toBe('~OK');
        expect(evaluateGdco(d, { nroPmLengthM: 23500 }).status).toBe('KO');
        expect(evaluateGdco(d).criteria[5]!.detail.key).toBe('criterion_detail_nro_pm_length_missing');
        expect(evaluateGdco(d, { nroPmLengthM: 22000 }).criteria[5]!.detail).toEqual({ key: 'criterion_detail_total_length', params: { length: 24000 } });
    });

    it('PBO name without the module', () => {
        expect(pboOf('SRO-BPI-1234567_PBO012_B2-T2-MB')).toBe('PBO012');
        expect(pboOf('SRO-BPI-1234567_BE-ID-9900000001G6-02_B2-T6-MA')).toBe('BE-ID-9900000001G6-02');
    });
});

describe('transport', () => {
    it('splice mean > 0.15 dB and connector > 0.6 dB', () => {
        const ok = [1, 2, 3, 4].flatMap((n) => [transportFiber(n, 1310, [0.3, 0.05, 0.1, 0.3]), transportFiber(n, 1550, [0.3, 0.05, 0.1, 0.3])]);
        expect(evaluateGdco(dossier('transport', ok)).status).toBe('OK');
        const splice = [...ok, transportFiber(5, 1310, [0.3, 0.16, 0.1, 0.3])];
        // 1 / 18 > 2 %
        expect(evaluateGdco(dossier('transport', splice)).status).toBe('KO');
        const conn = [...ok, transportFiber(5, 1310, [0.61, 0.05, 0.1, 0.3])];
        expect(evaluateGdco(dossier('transport', conn)).status).toBe('KO');
    });

    it('length gap between fibers: orange > 50 m, red > 100 m', () => {
        const f = (n: number, l: number) => transportFiber(n, 1310, [0.3, 0.05, 0.3], l);
        expect(evaluateGdco(dossier('transport', [f(1, 1000), f(2, 1040)])).status).toBe('OK');
        expect(evaluateGdco(dossier('transport', [f(1, 1000), f(2, 1060)])).status).toBe('~OK');
        expect(evaluateGdco(dossier('transport', [f(1, 1000), f(2, 1101)])).status).toBe('KO');
    });

    it('out of threshold slope reported without blocking the light', () => {
        const f = transportFiber(1, 1550, [0.3, 0.05, 0.3]);
        f.sections = [{ index: 1, lengthOe: 500, lengthEo: 500, lengthM: 500, slopeOe: 0.26, slopeEo: 0.26, slope: 0.26 }];
        const s = evaluateGdco(dossier('transport', [f]));
        expect(s.status).toBe('OK');
        expect(s.anomalies).toEqual([expect.objectContaining({ type: 'slope', blocking: false })]);
    });

    it('slopes like the macro: |slope| over the threshold, sections of at least 200 m only, transport and distribution', () => {
        const section = (index: number, slope: number, lengthM: number) => ({ index, lengthOe: lengthM, lengthEo: null, lengthM, slopeOe: slope, slopeEo: null, slope });
        const t = transportFiber(1, 1550, [0.3, 0.05, 0.3]);
        t.sections = [section(1, -0.26, 500), section(2, 0.4, 199.9)];
        const dist = distFiber(1, 1310, [0.4, 0.05]);
        dist.sections = [section(1, 0.39, 200)];

        for (const [kind, f, index] of [['transport', t, 1], ['distribution', dist, 1]] as const) {
            const s = evaluateGdco(dossier(kind, [f]));
            expect(s.status).toBe('OK');
            expect(s.anomalies.filter((a) => a.type === 'slope').map((a) => [a.index, a.blocking])).toEqual([[index, false]]);
        }
    });

    it('criteria are translatable messages', () => {
        const s = evaluateGdco(dossier('transport', [transportFiber(1, 1310, [0.3, 0.05, 0.3])]));
        expect(s.criteria.map((c) => c.label.key)).toEqual([
            'criterion_gdco_transport_splices', 'criterion_gdco_transport_connectors', 'criterion_gdco_connector_reflectance',
            'criterion_gdco_splice_reflectance', 'criterion_gdco_constraints', 'criterion_gdco_length_spread',
        ]);
        expect(s.criteria[1]!.detail).toEqual({ key: 'criterion_detail_over_of', params: { count: 0, total: 2 } });
    });
});

describe.skipIf(!SETS.distribution || !SETS.transportLoop)('real dossiers delivered OK by the GDCO macro', () => {
    it('looped transport → OK', () => {
        expect(evaluateGdco(analyze(loadSorFolder(SETS.transportLoop))).status).toBe('OK');
    });

    it.skipIf(!REFERENCE)('transport FTTH and FTTE of the reference PM → OK', () => {
        expect(evaluateGdco(analyze(loadSorFolder(REFERENCE!.ftth))).status).toBe('OK');
        expect(evaluateGdco(analyze(loadSorFolder(REFERENCE!.ftte))).status).toBe('OK');
    });

    it('distribution → OK, DCO indicators', () => {
        const s = evaluateGdco(analyze(loadSorFolder(SETS.distribution)), { nroPmLengthM: 335 });
        expect(s.status).toBe('OK');
        // Values of the 1_SYNTHESE sheet of the delivered DCO
        expect(s.indicators.indicator_pbo_covered).toBe(80);
        expect(s.indicators.indicator_rop_measured).toBe(466);
        expect(s.indicators.indicator_max_length_pm_pbo_1310).toBeCloseTo(1924.46, 1);
    });
});
