// Complete trace analysis: detection of the events missed by the OTDR, markers, wavelength / direction synchronisation.
import { describe, expect, it } from 'vitest';
import { analyze, DETECTED_EVENT_BASE, isDetected, type SorEvent, type SorFile } from '../../../resources/js/otdr';
import { completeTrace, DEFAULT_DETECTION, findMissing, inferEnd } from '../../../resources/js/otdr/engine/analysis';
import { byCableHash, loadSorFolder, SETS } from './support';

const C = 299792.458;

/** Synthetic trace: backscatter 0.33 dB/km, gaussian noise, steps at the given positions (km → dB). */
function synthetic(steps: [number, number][], noise = 0.008, seed = 7): SorFile {
    const ior = 1.468;
    const sampleKm = 0.000638;
    const n = Math.round(6 / sampleKm);
    let s = seed;
    const rnd = () => {
        s = (s * 16807) % 2147483647;

        return s / 2147483647;
    };
    const gauss = () => Math.sqrt(-2 * Math.log(rnd() + 1e-12)) * Math.cos(2 * Math.PI * rnd());
    const data = new Float32Array(n);

    for (let i = 0; i < n; i++) {
        const km = i * sampleKm;
        let v = 45 + 0.33 * km;

        for (const [at, db] of steps) {
            // edge over one pulse (30 ns)
            if (km >= at) {
                v += Math.min(1, (km - at) / 0.003) * db;
            }
        }

        data[i] = v + noise * gauss();
    }

    const kmPerTime = (1e-10 * C) / ior;
    const ev = (num: number, km: number, code: string, loss = 0): SorEvent => ({
        num, time: Math.round(km / kmPerTime), km, slope: 0, loss, reflectance: 0, code, marks: [0, 0, 0, 0, 0], comment: '',
    });

    return {
        name: 'synthetic.sor', blocks: [], cableId: '', fiberId: '', origin: '', termination: '', operator: '', comment: '',
        supplier: '', otdr: '', otdrSerial: '', module: '', moduleSerial: '', software: '', date: new Date(0),
        wavelengthNm: 1310, pulseNs: 30, ior, backscatterDb: -79, averagingTimeS: 0, rangeKm: 6, sampleKm,
        events: [ev(1, 0.5, '1F9999LS', 0.3), ev(2, 5.5, '1E9999LS')], orl: 0, data,
    };
}

describe('detection on a synthetic trace', () => {
    it('finds the missed splices and gains, with the right loss and position', () => {
        const sor = synthetic([[1.8, 0.12], [3.1, -0.08], [4.4, 0.06]]);
        const added = completeTrace(sor);
        expect(added).toBe(3);
        const found = sor.events.filter(isDetected);

        for (const [at, db] of [[1.8, 0.12], [3.1, -0.08], [4.4, 0.06]] as const) {
            const e = found.find((x) => Math.abs(x.km - at) < 0.005)!;
            expect(e, `event at ${at} km`).toBeDefined();
            expect(e.loss).toBeCloseTo(db, 1);
            expect(Math.abs(e.loss - db)).toBeLessThan(0.02);
            expect(e.num).toBeGreaterThanOrEqual(DETECTED_EVENT_BASE);
            expect(e.marks.slice(0, 4).every((m) => m > 0)).toBe(true);
        }
    });

    it('no false event on a fiber without defect (10 noise draws)', () => {
        for (let seed = 1; seed <= 10; seed++) {
            const sor = synthetic([], 0.01, seed * 97);
            expect(findMissing(sor, 900, 8500, DEFAULT_DETECTION), `draw ${seed}`).toEqual([]);
        }
    });

    it('below the detection threshold: not kept', () => {
        const sor = synthetic([[2.5, 0.03]]);
        expect(completeTrace(sor, { ...DEFAULT_DETECTION, splice: 0.05 })).toBe(0);
    });

    it('section slope computed when the OTDR does not give it', () => {
        const sor = synthetic([]);
        completeTrace(sor);
        expect(sor.events.find((e) => e.num === 2)!.slope).toBeCloseTo(0.33, 2);
    });
});

describe('fiber end requalification', () => {
    it('last event followed by a normal trace: no invented end', () => {
        const sor = synthetic([]);
        sor.events = sor.events.filter((e) => e.num === 1);
        expect(inferEnd(sor)).toBe(false);
    });
});

describe('slopes: adjusted markers, short section', () => {
    it('60 m section between two splices: slope of the fiber (shared regression), not an outlier', () => {
        const sor = synthetic([[2.0, 0.1], [2.06, 0.1]], 0.012, 11);
        completeTrace(sor);
        const ev = [...sor.events].sort((a, b) => a.km - b.km);
        const short = ev.find((e) => Math.abs(e.km - 2.06) < 0.005)!;
        expect(short.slopeSource).toBe('fiber');
        expect(short.slope).toBeGreaterThan(0.3);
        expect(short.slope).toBeLessThan(0.36);
    });
});

// Real SmartOTDR 25.6.6 measurements (no markers, …_NNNN_λ_OE naming).
describe.skipIf(!SETS.smartOtdr)('SmartOTDR 25.6.6 set (no markers)', () => {
    const all = loadSorFolder(SETS.smartOtdr);
    const fiberOf = (files: typeof all, fiber: number) => files.filter((f) => new RegExp(`_${String(fiber).padStart(4, '0')}_`).test(f.name));

    it('the "fiber start" event at 0 m is not the O connector', () => {
        for (const on of [false, true]) {
            const d = analyze(fiberOf(byCableHash(all, '82d233e6065b'), 19), undefined, [], { completeAnalysis: on });
            const f = d.fibers.find((x) => x.lambda === 1310)!;
            expect(f.events[0]!.kind).toBe('connector');
            expect(f.events[0]!.traceM).toBeCloseTo(2052.5, 0);
            expect(f.events[0]!.value).toBe(0.398);
        }
    });

    it('an event found at one wavelength is measured at the other: same events at 1310 and 1550 nm', () => {
        // Whole cable: the splice at 2510 m recorded by the OTDR on fibers 11 and 12 is measured on fiber 10.
        const d = analyze(byCableHash(all, '63376d94f5d0'));
        const [a, b] = [1310, 1550].map((l) => d.fibers.find((f) => f.fiber === 10 && f.lambda === l)!);
        expect(a!.events.length).toBe(2);
        expect(a!.events.length).toBe(b!.events.length);
        a!.events.forEach((e, i) => expect(Math.abs(e.positionM - b!.events[i]!.positionM)).toBeLessThan(10));
        expect(Object.values(d.traces!).some((t) => t.events.some(isDetected))).toBe(true);
    });

    it('a detected event ignored by the operator is not detected again', () => {
        const files = byCableHash(all, '63376d94f5d0');
        const name = files.find((f) => /_0010_1310/.test(f.name))!.name;
        const fib = (x: ReturnType<typeof analyze>) => x.fibers.find((f) => f.files[0] === name)!;
        const d = analyze(files);
        const det = d.traces![name]!.events.find(isDetected)!;
        const e = analyze(files, undefined, [{ file: name, event: det.num, action: 'ignore' }]);
        expect(e.traces![name]!.events.some(isDetected)).toBe(false);
        expect(e.traces![name]!.ignored.map((x) => x.num)).toContain(det.num);
        expect(fib(e).events.length).toBe(fib(d).events.length - 1);
    });

    it('fiber end not marked by the OTDR: the last peak followed by noise becomes the end', () => {
        const [f] = fiberOf(byCableHash(all, '028c07c9c949'), 14).filter((x) => /_1550_/.test(x.name));
        const d = analyze([f!]);
        expect(d.fibers[0]!.warnings.map((w) => w.key)).not.toContain('fiber_end_not_detected');
        expect(Math.max(...d.fibers[0]!.events.map((e) => e.traceM))).toBeLessThan(2500);
    });

    it('all the fibers: no loss detected outside the fiber (noise after the end)', () => {
        const d = analyze(all);

        for (const t of Object.values(d.traces!)) {
            for (const e of t.events.filter(isDetected)) {
                expect(Math.abs(e.loss), `${e.km}`).toBeLessThan(1);
            }
        }
    });
});

// Real SmartOTDR + MTS 4000 measurements (588 traces, steps 0.64 and 1.28 m, short sections).
describe.skipIf(!SETS.mixedOtdr)('SmartOTDR + MTS 4000 set: 100 % treatment', () => {
    const files = loadSorFolder(SETS.mixedOtdr);

    it('manual "loop" setting on distribution measurements: ignored, classic measurement', () => {
        const d = analyze(files.slice(0, 40), undefined, [], { auto: false, loop: true, startSpools: 1, endSpools: 1 });
        expect(d.mode).toBe('unidirectional');
        expect(d.warnings.map((w) => w.key)).toContain('loop_setting_ignored');
    });

    it('each connector has its reflectance, each section its slope', () => {
        const d = analyze(files);

        for (const f of d.fibers) {
            for (const e of f.events) {
                if (e.kind === 'connector') {
                    expect(e.reflOe, `${f.cableId} ${f.fiber} ${f.lambda}`).not.toBeNull();
                }
            }

            for (const s of f.sections) {
                expect(s.slope, `${f.cableId} ${f.fiber} ${f.lambda} section ${s.index}`).not.toBeNull();
            }
        }
    });

    it('fibers of a same cable: same events (aligned columns in the dossier)', () => {
        const d = analyze(files);
        const byCable = new Map<string, number[]>();

        for (const f of d.fibers) {
            byCable.set(`${f.cableId}|${f.lambda}`, [...(byCable.get(`${f.cableId}|${f.lambda}`) ?? []), f.events.length]);
        }

        const aligned = [...byCable.values()].filter((v) => new Set(v).size === 1).length;
        expect(aligned / byCable.size).toBeGreaterThan(0.9);
    });
});
