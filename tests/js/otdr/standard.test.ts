// Standard treatment (FiberCable): OTDR settings (spools, loop), alarm thresholds, total loss.
import { describe, expect, it } from 'vitest';
import {
    analyze, cloneProfile, DEFAULT_ACQUISITION, evaluateStandard, GDCO_ACQUISITION, PRESETS, type AcquisitionConfig, type Dossier, type FiberResult, type Lambda,
} from '../../../resources/js/otdr';
import { loadSorFolder, SETS } from './support';

const loop: AcquisitionConfig = GDCO_ACQUISITION.transport;
const classic: AcquisitionConfig = GDCO_ACQUISITION.distribution;
/** Spools: count of the OTDR events only (an event detected inside a spool changes the count). */
const classicOtdr: AcquisitionConfig = { ...classic, completeAnalysis: false };
const fc = PRESETS.find((p) => p.id === 'fibercable')!;

function fiber(cableId: string, n: number, lambda: Lambda, values: number[]): FiberResult {
    return {
        cableId, fiber: n, lambda,
        events: values.map((v, i) => ({ index: i + 1, num: i + 1, kind: i === 0 ? 'connector' : 'splice', positionM: i * 100, traceM: i * 100, oe: v, eo: null, value: v, reflOe: null, reflEo: null })),
        sections: [], totalLossOe: null, totalLossEo: null, totalLoss: 0, totalLossEstimated: false, lengthM: 500, files: [], date: new Date(0), warnings: [], edited: false,
    };
}

describe('standard control (synthetic)', () => {
    it('cable means are computed per cable (multi-cable dossier)', () => {
        const p = cloneProfile(fc);
        p.thresholds[1310].fiberSplice = 0.1;
        p.thresholds[1310].splice = 1;
        // cable A: splice mean 0.12 (out of limits); cable B: 0.02 (compliant). Pooled together: 0.07 (compliant).
        const d: Dossier = {
            kind: 'distribution', mode: 'unidirectional', origin: '', termination: '', files: [], warnings: [], acquisition: DEFAULT_ACQUISITION,
            fibers: [fiber('CABLE-A', 1, 1310, [0.3, 0.12]), fiber('CABLE-B', 1, 1310, [0.3, 0.02])],
        };
        const cableMeans = evaluateStandard(d, p).anomalies.filter((a) => a.type === 'splice_mean' && a.fiber === 0);
        expect(cableMeans).toEqual([expect.objectContaining({ cableId: 'CABLE-A', value: 0.12, lambda: 1310 })]);
    });

    it('criteria and indicators are translatable messages', () => {
        const p = cloneProfile(fc);
        const d: Dossier = {
            kind: 'distribution', mode: 'unidirectional', origin: '', termination: '', files: [], warnings: [], acquisition: DEFAULT_ACQUISITION,
            fibers: [fiber('CABLE-A', 1, 1310, [0.3, 0.05])],
        };
        const s = evaluateStandard(d, p);
        expect(s.criteria[0]!.label).toEqual({ key: 'criterion_standard_connectors', params: { threshold: 0.6 } });
        expect(s.criteria[2]!.label).toEqual({ key: 'criterion_standard_slopes_by_wavelength', params: { threshold_1310: 0.38, threshold_1550: 0.25 } });
        expect(s.criteria[5]!.detail).toEqual({ key: 'criterion_not_checked', params: {} });
        expect(s.criteria[7]!.label).toEqual({ key: 'criterion_standard_constraints', params: { threshold: 0.5 } });
        expect(Object.keys(s.indicators)).toEqual([
            'indicator_profile', 'indicator_fibers_measured', 'indicator_out_of_limits_values', 'indicator_max_length_1310',
            'indicator_mean_total_loss_1310', 'indicator_max_total_loss_1310', 'indicator_mean_total_loss_1550', 'indicator_max_total_loss_1550',
        ]);
    });
});

describe.skipIf(!SETS.transportLoop || !SETS.distribution)('standard treatment', () => {
    const transport = loadSorFolder(SETS.transportLoop);
    const dist = loadSorFolder(SETS.distribution);

    it('looped transport, FiberCable profile: no out of limits value (like the Out of limits sheet of the FiberCable dossier)', () => {
        const d = analyze(transport, undefined, [], loop);
        expect(d.mode).toBe('loop');
        expect(evaluateStandard(d, fc).anomalies).toEqual([]);
    });

    it('automatic setting = operator setting (same results)', () => {
        const auto = analyze(transport);
        const manual = analyze(transport, undefined, [], loop);
        expect(auto.fibers.map((f) => f.totalLoss)).toEqual(manual.fibers.map((f) => f.totalLoss));
        expect(auto.acquisition).toMatchObject({ startSpools: 1, endSpools: 1, loop: true, loopSpools: 1 });
    });

    it('lowered splice threshold: out of limits; negative value in alarm only when the option is checked', () => {
        const p = cloneProfile(fc);
        p.thresholds[1310].splice = 0.1;
        const spl = evaluateStandard(analyze(transport, undefined, [], loop), p).anomalies.filter((a) => a.type === 'splice');
        expect(spl.length).toBeGreaterThan(0);
        expect(spl.every((a) => a.lambda === 1310 && a.value > 0.1)).toBe(true);
        // distribution: apparent gains (−0.46 dB) beyond 0.30 dB
        const pd = cloneProfile(PRESETS.find((x) => x.id === 'xpfibre-distribution')!);
        const d = analyze(dist, undefined, [], classic);
        const neg = (q: typeof pd) => evaluateStandard(d, q).anomalies.filter((a) => a.type === 'splice' && a.value < 0).length;
        expect(neg(pd)).toBe(0);
        pd.negativeAlarm = true;
        expect(neg(pd)).toBeGreaterThan(0);
    });

    it('inactive line: not checked', () => {
        const p = cloneProfile(fc);
        p.thresholds[1310].splice = 0.05;
        p.active.splice = false;
        expect(evaluateStandard(analyze(transport, undefined, [], loop), p).anomalies.filter((a) => a.type === 'splice')).toEqual([]);
    });

    it('fiber and cable means', () => {
        const p = cloneProfile(fc);
        // mean of the splices of fiber 1 at 1310 nm: 0.067
        p.thresholds[1310].fiberSplice = 0.06;
        const an = evaluateStandard(analyze(transport, undefined, [], loop), p).anomalies.filter((a) => a.type === 'splice_mean');
        expect(an.some((a) => a.fiber === 1 && a.value === 0.067)).toBe(true);
        // cable mean
        expect(an.some((a) => a.fiber === 0)).toBe(true);
    });

    it('maximum link budget and constraint', () => {
        const p = cloneProfile(fc);
        p.thresholds[1310].totalLossMax = 2.0;
        p.active.totalLossMax = true;
        p.constraint = 0.05;
        const s = evaluateStandard(analyze(transport, undefined, [], loop), p);
        expect(s.anomalies.filter((a) => a.type === 'total_loss').map((a) => a.fiber).sort((a, b) => a - b)).toEqual([2, 4, 6]);
        expect(s.anomalies.some((a) => a.type === 'constraint')).toBe(true);
    });

    it('total loss: output connector excluded = total loss − loss of the E connector', () => {
        const inc = analyze(transport, undefined, [], loop).fibers.find((f) => f.fiber === 1 && f.lambda === 1310)!;
        const exc = analyze(transport, undefined, [], { ...loop, totalLossOutput: false }).fibers.find((f) => f.fiber === 1 && f.lambda === 1310)!;
        const last = inc.events[inc.events.length - 1]!;
        expect(exc.totalLossOe!).toBeCloseTo(inc.totalLossOe! - last.oe!, 6);
        expect(exc.totalLossEo!).toBeCloseTo(inc.totalLossEo! - last.eo!, 6);
    });

    it('spools: 2 launch spools remove the first joint; receive spool = last event is a connector', () => {
        const pick = (cfg: AcquisitionConfig, ref?: { cableId: string; fiber: number; lambda: number }) =>
            analyze(dist, undefined, [], cfg).fibers.find((f) => (ref ? f.cableId === ref.cableId && f.fiber === ref.fiber && f.lambda === ref.lambda : f.events.length >= 3))!;
        const one = pick(classicOtdr);
        const two = pick({ ...classicOtdr, startSpools: 2 }, one);
        expect(two.events.map((e) => e.num)).toEqual(one.events.slice(1).map((e) => e.num));
        const end = pick({ ...classicOtdr, endSpools: 1 }, one);
        expect(end.events[end.events.length - 1]!.kind).toBe('connector');
        // the section towards the fiber end belongs to the spool
        expect(end.sections).toHaveLength(one.sections.length - 1);
        const none = pick({ ...classicOtdr, startSpools: 0 }, one);
        expect(none.events[0]!.kind).toBe('splice');
    });

    it('loop from the extremity (O←E): the directions of both fibers are swapped', () => {
        const oe = analyze(transport, undefined, [], loop).fibers.find((f) => f.fiber === 1 && f.lambda === 1310)!;
        const eo = analyze(transport, undefined, [], { ...loop, loopDirection: 'EO' }).fibers.find((f) => f.fiber === 1 && f.lambda === 1310)!;
        // from E, file 001 gives fiber 1 measured E→O: its first event is on the E side
        expect(eo.events.map((e) => e.eo)).toEqual([...oe.events.map((e) => e.oe)].reverse());
    });

    it('loop without loop-back spool: incompatible number of events reported', () => {
        const d = analyze(transport, undefined, [], { ...loop, loopSpools: 0 });
        expect(d.fibers.some((f) => f.warnings.some((w) => w.key === 'loop_event_count_incompatible'))).toBe(true);
    });

    it('numbering n, n, n+2…: file n holds the pair n / n+1', () => {
        const d = analyze(transport.filter((f) => /-00[13]_/.test(f.name)), undefined, [], { ...loop, sequence: 'n,n' });
        expect([...new Set(d.fibers.map((f) => f.fiber))].sort((a, b) => a - b)).toEqual([1, 3]);
        expect(d.warnings.some((w) => w.key === 'loop_only_eo' && w.params.fiber === 2)).toBe(true);
    });
});
