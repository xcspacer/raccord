// Parity with FiberCable 2 on real dossiers (looped transport, with or without loop-back spool, and PM-PBO
// distribution), against the original exports. The settings are deduced from the files, like an operator would set them.
import { readdirSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { analyze, type FiberResult } from '../../../resources/js/otdr';
import { type FcExport, hasReference, loadSorFolder, OTDR_ONLY, readFcExport, REFERENCE, rowKey } from './support';

interface Stats { n: number; bad: number; max: number; examples: string[] }

const stats = (): Stats => ({ n: 0, bad: 0, max: 0, examples: [] });

function cmp(s: Stats, got: number | null, exp: number | null | undefined, tol: number, label: string) {
    if ((got === null || got === 0) && (exp === null || exp === undefined)) {
        return;
    }

    if (got === null || exp === null || exp === undefined) {
        s.n++;
        s.bad++;

        if (s.examples.length < 5) {
            s.examples.push(`${label}: ${got} vs ${exp}`);
        }

        return;
    }

    s.n++;
    const d = Math.abs(got - exp);
    s.max = Math.max(s.max, d);

    if (d > tol) {
        s.bad++;

        if (s.examples.length < 5) {
            s.examples.push(`${label}: ${got} vs ${exp}`);
        }
    }
}

function transportParity(fibers: FiberResult[], fc: FcExport) {
    const r = { atten: stats(), avg: stats(), total: stats(), slope: stats(), length: stats(), refl: stats() };
    // Total loss columns after the events of the longest fiber (direct loop: the second fiber of a pair has one less).
    const nEvents = Math.max(...fibers.map((f) => f.events.length));

    for (const f of fibers) {
        const k = rowKey(f.cableId, f.fiber, f.lambda);
        const a = fc.atten.get(k);

        // even fiber: FiberCable stores it on the row of the odd fiber
        if (!a) {
            continue;
        }

        f.events.forEach((e, i) => {
            const lb = `${k} ev${i + 1}`;
            cmp(r.atten, e.oe, a[3 * i], 0.0005, `${lb} OE`);
            cmp(r.atten, e.eo, a[3 * i + 1], 0.0005, `${lb} EO`);
            // ±0.001 on the x.xxx5 means (see mean3)
            cmp(r.avg, e.value, a[3 * i + 2], 0.0010001, `${lb} mean`);
            const rf = fc.refl.get(k)!;
            cmp(r.refl, e.reflOe, rf[2 * i], 0.0100001, `${lb} refl OE`);
            cmp(r.refl, e.reflEo, rf[2 * i + 1], 0.0100001, `${lb} refl EO`);
        });
        cmp(r.total, f.totalLossOe, a[3 * nEvents], 0.003, `${k} total OE`);
        cmp(r.total, f.totalLossEo, a[3 * nEvents + 1], 0.003, `${k} total EO`);
        const sl = fc.slope.get(k)!;
        const ln = fc.length.get(k)!;
        f.sections.forEach((s, j) => {
            cmp(r.slope, s.slopeOe, sl[3 * j], 0.0005, `${k} slope${j + 1} OE`);
            cmp(r.slope, s.slopeEo, sl[3 * j + 1], 0.0005, `${k} slope${j + 1} EO`);
            cmp(r.length, s.lengthOe, ln[3 * j], 0.02, `${k} length${j + 1} OE`);
            cmp(r.length, s.lengthEo, ln[3 * j + 1], 0.02, `${k} length${j + 1} EO`);
        });
    }

    return r;
}

const txtIn = (dir: string) => `${dir}/${readdirSync(dir).find((n) => n.endsWith('.txt'))}`;

describe.skipIf(!hasReference)('FiberCable parity — looped transport', () => {
    const sets = REFERENCE
        ? ([['looped transport', `${REFERENCE.transportLoop}/SOR`, txtIn(REFERENCE.transportLoop)], ['FTTH', REFERENCE.ftth, txtIn(REFERENCE.ftth)], ['FTTE', REFERENCE.ftte, txtIn(REFERENCE.ftte)]] as const)
        : [];

    for (const [name, dir, txt] of sets) {
        it(name, () => {
            const d = analyze(loadSorFolder(dir), undefined, [], OTDR_ONLY);
            expect(d.kind).toBe('transport');
            expect(d.mode).toBe('loop');
            const r = transportParity(d.fibers, readFcExport(txt));
            expect(r.atten.n).toBeGreaterThanOrEqual(96);

            for (const s of Object.values(r)) {
                expect(s.examples).toEqual([]);
            }
        });
    }
});

describe.skipIf(!hasReference)('FiberCable parity — PM-PBO distribution', () => {
    it('complete PM (every trace of the FiberCable export)', () => {
        const d = analyze(loadSorFolder(REFERENCE!.distribution), undefined, [], OTDR_ONLY);
        expect(d.kind).toBe('distribution');
        const fc = readFcExport(txtIn(REFERENCE!.distribution));
        const r = { atten: stats(), total: stats(), slope: stats(), length: stats(), refl: stats() };
        // Total loss: mean column after the events of the longest fiber.
        const total = 3 * Math.max(...d.fibers.map((f) => f.events.length)) + 2;

        for (const f of d.fibers) {
            const k = rowKey(f.cableId, f.fiber, f.lambda);
            const a = fc.atten.get(k)!;
            expect(a, k).toBeDefined();
            f.events.forEach((e, i) => {
                cmp(r.atten, e.value, a[3 * i + 2], 0.0005, `${k} ev${i + 1}`);
                cmp(r.refl, e.reflOe, fc.refl.get(k)![2 * i], 0.0100001, `${k} refl${i + 1}`);
            });
            cmp(r.total, f.totalLoss, a[total], 0.0025, `${k} total`);
            f.sections.forEach((s, j) => {
                cmp(r.slope, s.slope, fc.slope.get(k)![3 * j + 2], 0.0005, `${k} slope${j + 1}`);
                cmp(r.length, s.lengthM, fc.length.get(k)![3 * j + 2], 0.02, `${k} length${j + 1}`);
            });
        }

        expect(d.fibers.length).toBe(fc.atten.size);

        // FiberCable re-analyses some traces on loading (up to 6 % of the traces of a PM, with values neither in the .sor
        // event table nor recomputable from its markers): ≥ 96 % identical, losses within about 0.01 dB.
        for (const s of [r.atten, r.refl, r.length, r.slope]) {
            expect(s.bad / s.n).toBeLessThan(0.04);
        }

        expect(r.atten.max).toBeLessThan(0.015);

        // Distribution total loss: estimated indicator (FiberCable estimates again the slopes of the short sections).
        expect(r.total.bad / r.total.n).toBeLessThan(0.25);
    });
});
