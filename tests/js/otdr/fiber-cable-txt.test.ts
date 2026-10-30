// .txt export: same structure as the real FiberCable export, cell by cell, on the same .sor files; golden file of the
// previous tool; header written from the applied settings.
import { existsSync, readdirSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    analyze, buildFiberCableTxt, configureProduct, DEFAULT_ACQUISITION, encodeUtf16le, parseSorName, type Dossier, type FiberResult,
} from '../../../resources/js/otdr';
import { TXT } from '../../../resources/js/otdr/export/format';
import { EXAMPLES_DIR, fileIn, hasReference, loadSorFolder, OTDR_ONLY, readUtf16, REFERENCE, SETS } from './support';

const txtIn = (dir: string) => `${dir}/${readdirSync(dir).find((n) => n.endsWith('.txt'))}`;
const num = (s: string) => (s.trim() === '' ? NaN : Number(s.replace(',', '.')));

/** Section of each line (last title met). */
function sections(lines: string[]): string[] {
    let cur = 'header';
    const S = TXT.sections;
    const titles = [S.attenuations, S.slopes, S.reflectances, S.lengths, S.constraints, S.information, 'PMD'];

    return lines.map((l) => {
        const first = l.split('\t')[0]!;

        if (titles.some((t) => first.startsWith(t))) {
            cur = first;
        }

        return cur;
    });
}

// Tolerances per section: known FiberCable gaps (trace re-analysis, internal precision, estimated total loss).
const TOL: Record<string, number> = {
    [TXT.sections.attenuations]: 0.0031, [TXT.sections.slopes]: 0.0011, [TXT.sections.reflectances]: 0.0101, [TXT.sections.lengths]: 0.021, [TXT.sections.constraints]: 0.0021,
};
// Measurement information: dates (7-8, "(UTC+0)" suffix depending on the FiberCable version), calibration date (23-24)
// and nominal resolution (41-42): missing from the standard .sor blocks or display options.
const INFO_SKIP = new Set([7, 8, 23, 24, 41, 42]);

function diff(ours: string, theirs: string) {
    const A = ours.split('\r\n');
    const B = theirs.split('\r\n');
    const secs = sections(B);
    const out = { lines: [A.length, B.length], structure: [] as string[], values: new Map<string, number>(), cells: 0, examples: [] as string[] };

    for (let i = 0; i < Math.max(A.length, B.length); i++) {
        // line 2: software identification (no CRC); lines 5-38: configuration (empty labels, thresholds), checked apart
        if (i === 1 || (i >= 4 && i <= 37)) {
            continue;
        }

        const a = (A[i] ?? '').split('\t');
        const b = (B[i] ?? '').split('\t');

        if (a.length !== b.length) {
            out.structure.push(`line ${i + 1}: ${a.length} vs ${b.length} cells`);
            continue;
        }

        const sec = secs[i]!;

        for (let j = 0; j < b.length; j++) {
            out.cells++;

            if (a[j] === b[j]) {
                continue;
            }

            if (sec === TXT.sections.information && INFO_SKIP.has(j)) {
                continue;
            }

            const x = num(a[j]!);
            const y = num(b[j]!);
            const tol = TOL[sec] ?? 0;
            // alarm flags: strict equality
            const isMirror = /^[01]$/.test(a[j]!) || /^[01]$/.test(b[j]!);

            if (!Number.isNaN(x) && !Number.isNaN(y) && Math.abs(x - y) <= tol && !isMirror) {
                continue;
            }

            const key = `${sec} col ${j}`;
            out.values.set(key, (out.values.get(key) ?? 0) + 1);

            if (out.examples.length < 12) {
                out.examples.push(`l${i + 1} c${j} [${sec}] ours=${JSON.stringify(a[j])} ref=${JSON.stringify(b[j])}`);
            }
        }
    }

    return out;
}

const badRatio = (r: ReturnType<typeof diff>) => [...r.values.values()].reduce((a, b) => a + b, 0) / r.cells;

describe.skipIf(!hasReference)('FiberCable .txt export against the original exports', () => {
    const sets = REFERENCE
        ? ([
            ['looped transport', `${REFERENCE.transportLoop}/SOR`, txtIn(REFERENCE.transportLoop), 0],
            ['FTTH', REFERENCE.ftth, txtIn(REFERENCE.ftth), 0],
            // FTTE (loop without loop-back spool): the FiberCable "mean total loss" is not the mean of both directions
            // (≤ 0.02 dB on 16 cells).
            ['FTTE', REFERENCE.ftte, txtIn(REFERENCE.ftte), 0.005],
            // Distribution: traces re-analysed by FiberCable + estimated total loss (indicator).
            ['distribution', REFERENCE.distribution, txtIn(REFERENCE.distribution), 0.002],
        ] as const)
        : [];

    for (const [name, dir, txt, maxBad] of sets) {
        it(name, () => {
            const ours = buildFiberCableTxt(analyze(loadSorFolder(dir), undefined, [], OTDR_ONLY));
            const theirs = readUtf16(txt);
            const r = diff(ours, theirs);
            expect(r.structure).toEqual([]);
            expect(r.lines[0]).toBe(r.lines[1]);
            expect(badRatio(r)).toBeLessThanOrEqual(maxBad);
            // header written from the settings deduced from the files: identical, except the software line
            expect(ours.split('\r\n').slice(2, 38)).toEqual(theirs.split('\r\n').slice(2, 38));
        });
    }
});

describe.skipIf(!EXAMPLES_DIR || !SETS.distribution)('FiberCable .txt export against the golden file of the previous tool', () => {
    it('distribution', () => {
        const ours = buildFiberCableTxt(analyze(loadSorFolder(SETS.distribution), undefined, [], OTDR_ONLY));
        const golden = readUtf16(fileIn(EXAMPLES_DIR!, /_Distribution\.txt$/));
        const r = diff(ours, golden);
        expect(r.structure).toEqual([]);
        expect(r.lines[0]).toBe(r.lines[1]);
        expect(r.examples).toEqual([]);
        // header (lines 5-38) identical as well
        expect(ours.split('\r\n').slice(4, 38)).toEqual(golden.split('\r\n').slice(4, 38));
    });
});

/** Golden transport file of the previous tool, when it was generated from the reference FTTH set (same cable). */
function transportGolden(): string | null {
    if (!EXAMPLES_DIR || !REFERENCE || !existsSync(REFERENCE.ftth)) {
        return null;
    }

    const name = readdirSync(EXAMPLES_DIR).find((n) => /_Transport FTTH\.txt$/.test(n));
    const cable = parseSorName(loadSorFolder(REFERENCE.ftth)[0]?.name ?? '')?.cableId;
    const text = name ? readUtf16(`${EXAMPLES_DIR}/${name}`) : '';

    return cable && text.includes(`\r\n${cable}\t`) ? text : null;
}

describe.skipIf(!transportGolden())('FiberCable .txt export against the transport golden file of the previous tool', () => {
    it('transport FTTH', () => {
        const ours = buildFiberCableTxt(analyze(loadSorFolder(REFERENCE!.ftth), undefined, [], OTDR_ONLY));
        const golden = transportGolden()!;
        const r = diff(ours, golden);
        expect(r.structure).toEqual([]);
        expect(r.examples).toEqual([]);
        expect(ours.split('\r\n').slice(4, 38)).toEqual(golden.split('\r\n').slice(4, 38));
    });
});

describe('FiberCable .txt export', () => {
    const fiber = (n: number, lambda: 1310 | 1550): FiberResult => ({
        cableId: 'CABLE-1', fiber: n, lambda,
        events: [
            { index: 1, num: 1, kind: 'connector', positionM: 0, traceM: 100, oe: 0.3, eo: 0.31, value: 0.305, reflOe: -60, reflEo: -61 },
            { index: 2, num: 2, kind: 'connector', positionM: 900, traceM: 1000, oe: 0.2, eo: 0.22, value: 0.21, reflOe: -58, reflEo: null },
        ],
        sections: [{ index: 1, lengthOe: 900, lengthEo: 901, lengthM: 900.5, slopeOe: 0.33, slopeEo: 0.34, slope: 0.335 }],
        totalLossOe: 0.8, totalLossEo: 0.81, totalLoss: 0.805, totalLossEstimated: false, lengthM: 900.5, files: [], date: new Date(0), warnings: [], edited: false,
    });
    const dossier = (acquisition: Dossier['acquisition'], kind: Dossier['kind'] = 'transport'): Dossier => ({
        kind, mode: acquisition.loop ? 'loop' : kind === 'transport' ? 'bidirectional' : 'unidirectional', origin: 'O', termination: 'E',
        fibers: [fiber(1, 1310), fiber(1, 1550)], files: [], warnings: [], acquisition,
    });
    const headerLine = (text: string, label: string) => text.split('\r\n').find((l) => l.startsWith(label))!.split('\t');

    it('line 2 identifies the configured product, without FiberCable fingerprint; UTF-16LE with BOM', () => {
        configureProduct({ name: 'Acme Recette OTDR', version: '2.1.0' });
        const ours = buildFiberCableTxt(dossier({ ...DEFAULT_ACQUISITION, loop: true, endSpools: 1 }));
        const l2 = ours.split('\r\n')[1]!.split('\t');
        expect(l2[2]).toBe('Acme Recette OTDR (v 2.1.0) - format FiberCable 2 Suite');
        expect(l2[10]).toBe('');
        const bytes = encodeUtf16le(ours);
        expect([bytes[0], bytes[1]]).toEqual([0xff, 0xfe]);
        expect(new TextDecoder('utf-16le').decode(bytes.subarray(2))).toBe(ours);
        configureProduct({ name: 'Recette OTDR', version: '1.0.0' });
    });

    it('spools and connectors of the header follow the applied settings', () => {
        // reference settings: looped transport and classic distribution (values of the reference deliverables)
        const loop = buildFiberCableTxt(dossier({ ...DEFAULT_ACQUISITION, startSpools: 1, endSpools: 1, loop: true, loopSpools: 1 }));
        expect(headerLine(loop, TXT.header.spools)).toEqual([TXT.header.spools, '1', '1', '1', '', '2']);
        expect(headerLine(loop, TXT.header.connectorsRemoved).slice(-3)).toEqual([TXT.header.connectorsIncluded, '1', '1']);
        const dist = buildFiberCableTxt(dossier({ ...DEFAULT_ACQUISITION, startSpools: 1, endSpools: 0, loop: false }, 'distribution'));
        expect(headerLine(dist, TXT.header.spools)).toEqual([TXT.header.spools, '1', '0', '0', '', '0']);
        // other settings are no longer hidden behind fixed values
        const other = buildFiberCableTxt(dossier({ ...DEFAULT_ACQUISITION, startSpools: 2, endSpools: 1, loop: false, totalLossInput: false, totalLossOutput: true }));
        expect(headerLine(other, TXT.header.spools)).toEqual([TXT.header.spools, '2', '0', '1', '', '0']);
        expect(headerLine(other, TXT.header.connectorsRemoved).slice(-3)).toEqual([TXT.header.connectorsIncluded, '0', '1']);
    });
});
