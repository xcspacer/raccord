// Measurement dossier (FiberCable macro format) compared to the dossiers produced by FiberCable 2 for looped transports.
import { existsSync, readdirSync } from 'node:fs';
import ExcelJS from 'exceljs';
import { describe, expect, it } from 'vitest';
import {
    analyze, buildMeasurementReport, configureProduct, DEFAULT_REPORT_OPTIONS, evaluateStandard, GDCO_ACQUISITION, measurementReportFileName, PRESETS,
} from '../../../resources/js/otdr';
import { REPORT } from '../../../resources/js/otdr/export/format';
import { hasReference, loadSorFolder, REFERENCE, SETS } from './support';

const val = (v: ExcelJS.CellValue): number | null => {
    if (typeof v === 'number') {
        return v;
    }

    if (v && typeof v === 'object' && 'result' in v && typeof v.result === 'number') {
        return v.result;
    }

    return null;
};

function compare(ours: ExcelJS.Worksheet, ref: ExcelJS.Worksheet, rows: [number, number], cols: [number, number], tolOf: number | ((r: number, c: number) => number), skip: (r: number, c: number) => boolean = () => false) {
    let n = 0;
    const bad: string[] = [];

    for (let r = rows[0]; r <= rows[1]; r++) {
        for (let c = cols[0]; c <= cols[1]; c++) {
            if (skip(r, c)) {
                continue;
            }

            const b = val(ref.getCell(r, c).value);
            const a = val(ours.getCell(r, c).value);

            if (b === null && a === null) {
                continue;
            }

            n++;
            const tol = typeof tolOf === 'number' ? tolOf : tolOf(r, c);

            if (a === null || b === null || Math.abs(a - b) > tol) {
                bad.push(`${ref.name} ${ref.getCell(r, c).address}: ${a} vs ${b}`);
            }
        }
    }

    return { n, bad };
}

/** FiberCable measurement dossier (.xlsx) next to a reference set, or ''. */
const referenceReport = (dir: string) => {
    const name = existsSync(dir) ? readdirSync(dir).find((n) => n.endsWith('.xlsx')) : undefined;

    return name ? `${dir}/${name}` : '';
};

/**
 * Reference sets with a FiberCable dossier: name, .sor folder, dossier folder, tolerance on the mean total loss
 * (columns AO:AP of the splices sheet; loop without loop-back spool: FiberCable does not average both directions).
 */
const reportSets = (REFERENCE && hasReference
    ? [
        ['looped transport', `${REFERENCE.transportLoop}/SOR`, REFERENCE.transportLoop, 0.0031],
        ['looped transport without loop-back spool (FTTE)', REFERENCE.ftte, REFERENCE.ftte, 0.015],
    ] as const
    : []).filter(([, , dir]) => referenceReport(dir));

describe.skipIf(!reportSets.length)('measurement dossier against FiberCable', () => {
    for (const [name, sorDir, dir, meanTotalTol] of reportSets) {
        it(`${name}: same sheets and values as the FiberCable dossier`, async () => {
            // OTDR settings deduced from the files (one loop-back spool or a direct joint)
            const d = analyze(loadSorFolder(sorDir));
            expect(d.acquisition).toMatchObject({ startSpools: GDCO_ACQUISITION.transport.startSpools, endSpools: GDCO_ACQUISITION.transport.endSpools, loop: true });
            const p = PRESETS.find((x) => x.id === 'fibercable')!;
            const s = evaluateStandard(d, p);
            const info = { name: d.fibers[0]!.cableId, origin: d.origin, extremity: d.termination };
            const wb = buildMeasurementReport(ExcelJS, d, s, p, info, { ...DEFAULT_REPORT_OPTIONS, totalLoss: false, orl: false });
            const ours = new ExcelJS.Workbook();
            await ours.xlsx.load(await wb.xlsx.writeBuffer());
            const ref = new ExcelJS.Workbook();
            await ref.xlsx.readFile(referenceReport(dir));
            expect(ours.worksheets.map((w) => w.name)).toEqual(ref.worksheets.map((w) => w.name));
            const S = REPORT.sheets;

            const res = {
                // E connector "Long. Cum." (merged AD10:AJ10): FiberCable writes 0, the real cumulated length is written here.
                // Rows 10-11 = lengths (2 cm tolerance), then losses (±0.003 dB, x.xxx5 means and total loss).
                splices: compare(ours.getWorksheet(S.splices)!, ref.getWorksheet(S.splices)!, [10, 26], [2, 42], (r, c) => (r <= 11 ? 0.02 : c >= 41 ? meanTotalTol : 0.0031), (r, c) => r === 10 && c >= 30 && c <= 36),
                slopes: compare(ours.getWorksheet(S.slopes)!, ref.getWorksheet(S.slopes)!, [10, 24], [2, 27], (r) => (r === 10 ? 0.02 : 0.0011)),
                // Connectors without OTDR reflectance: FiberCable leaves them empty, the complete analysis measures the peak (or the limit).
                reflectances: compare(ours.getWorksheet(S.reflectances)!, ref.getWorksheet(S.reflectances)!, [12, 23], [2, 9], 0.0101, (r, c) => val(ref.getWorksheet(S.reflectances)!.getCell(r, c).value) === null),
                lengths: compare(ours.getWorksheet(S.lengths)!, ref.getWorksheet(S.lengths)!, [11, 14], [2, 4], 0.02),
            };

            for (const r of Object.values(res)) {
                expect(r.n).toBeGreaterThanOrEqual(12);
                expect(r.bad).toEqual([]);
            }

            const outOfLimits = ours.getWorksheet(S.outOfLimits)!;
            // one "no value" row per table (merged cells: count the rows)
            let empty = 0;
            outOfLimits.eachRow((row) => {
                if (row.getCell(2).value === REPORT.outOfLimits.empty) {
                    empty++;
                }
            });
            expect(empty).toBe(5);
        });
    }
});

describe.skipIf(!SETS.transportLoop || !SETS.distribution)('measurement dossier', () => {
    it('looped transport: no out of limits value, five empty tables, product identity', async () => {
        configureProduct({ name: 'Acme Recette OTDR', version: '3.0.0' });
        const d = analyze(loadSorFolder(SETS.transportLoop), undefined, [], GDCO_ACQUISITION.transport);
        const p = PRESETS.find((x) => x.id === 'fibercable')!;
        const s = evaluateStandard(d, p);
        const wb = buildMeasurementReport(ExcelJS, d, s, p, { name: 'CABLE', origin: 'O', extremity: 'E' }, { ...DEFAULT_REPORT_OPTIONS, totalLoss: false, orl: false });
        expect(wb.worksheets.map((w) => w.name)).toEqual(['Titre', 'Table Matières', 'Informations Générales', 'Informations Mesure', 'Longueurs', 'Epissures', 'Réflectances', 'Pentes', 'Hors Normes']);
        expect(wb.creator).toBe('Acme Recette OTDR v3.0.0');
        expect(wb.getWorksheet('Titre')!.getCell('C18').value).toBe('Dossier généré par Acme Recette OTDR v3.0.0 à partir des fichiers .sor d\'origine.');
        expect(wb.getWorksheet('Informations Générales')!.getCell('E31').value).toBe('Bidirectionnelle par bouclage');
        let empty = 0;
        wb.getWorksheet('Hors Normes')!.eachRow((row) => {
            if (row.getCell(2).value === 'Pas de valeurs') {
                empty++;
            }
        });
        expect(empty).toBe(5);
        expect(measurementReportFileName({ name: 'A/B', origin: '', extremity: '' }, s)).toBe('A_B OK.xlsx');
        configureProduct({ name: 'Recette OTDR', version: '1.0.0' });
    });

    it('unidirectional multi-cable: ORL and link budget sheets, cable on each row', async () => {
        const d = analyze(loadSorFolder(SETS.distribution).slice(0, 60), undefined, [], GDCO_ACQUISITION.distribution);
        const p = PRESETS.find((x) => x.id === 'xpfibre-distribution')!;
        const s = evaluateStandard(d, p);
        const wb = buildMeasurementReport(ExcelJS, d, s, p, { name: 'distribution', origin: d.origin, extremity: 'PBO' });
        const back = new ExcelJS.Workbook();
        await back.xlsx.load(await wb.xlsx.writeBuffer());
        expect(back.worksheets.map((w) => w.name)).toContain('ORL');
        expect(back.worksheets.map((w) => w.name)).toContain('Bilans');
        expect(String(back.getWorksheet('Epissures')!.getCell(14, 1).value)).toMatch(/^SRO-BPI-\d+_.* · \d+$/);
        expect(val(back.getWorksheet('ORL')!.getCell(10, 2).value)).toBeGreaterThan(30);
    });

    it('out of limits means: table of the fiber and cable means', async () => {
        const d = analyze(loadSorFolder(SETS.transportLoop), undefined, [], GDCO_ACQUISITION.transport);
        const p = structuredClone(PRESETS.find((x) => x.id === 'fibercable')!);
        p.thresholds[1310].fiberSplice = 0.06;
        const s = evaluateStandard(d, p);
        const wb = buildMeasurementReport(ExcelJS, d, s, p, { name: 'CABLE', origin: 'O', extremity: 'E' });
        const texts: string[] = [];
        wb.getWorksheet('Hors Normes')!.eachRow((row) => row.eachCell((c) => {
            if (typeof c.value === 'string') {
                texts.push(c.value);
            }
        }));
        expect(texts).toContain('Tableau des moyennes des fibres et du câble');
        expect(texts).toContain('Câble');
    });
});
