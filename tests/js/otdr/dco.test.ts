// DCO workbook: cell by cell comparison with the DCO delivered by the GDCO macro (operator reference dossier) and with
// the golden DCO of the previous tool.
import { readFileSync } from 'node:fs';
import ExcelJS from 'exceljs';
import { describe, expect, it } from 'vitest';
import { analyze, buildDco, dcoFileName, evaluateGdco, parseRopCsv, type DcoInfo } from '../../../resources/js/otdr';
import { DCO } from '../../../resources/js/otdr/export/format';
import { EXAMPLES_DIR, fileIn, hasReference, loadSorFolder, OTDR_ONLY, REFERENCE, SETS } from './support';

type Num = number | null;

const asNum = (v: ExcelJS.CellValue): Num => {
    if (typeof v === 'number') {
        return v;
    }

    if (typeof v === 'string' && /^-?\d+([.,]\d+)?$/.test(v.trim())) {
        return Number(v.replace(',', '.'));
    }

    if (v && typeof v === 'object' && 'result' in v && typeof v.result === 'number') {
        return v.result;
    }

    return null;
};
const text = (v: ExcelJS.CellValue) => (v === null || v === undefined ? '' : String(v));

interface SheetCmp { compared: number; bad: number; missingRows: number; examples: string[] }

/** Compares the data rows (key = columns keyCols) of two sheets. */
function compareSheet(ours: ExcelJS.Worksheet, ref: ExcelJS.Worksheet, firstRow: number, keyCols: number[], tol: number, skipCols: number[] = []): SheetCmp {
    const index = new Map<string, ExcelJS.Row>();
    ours.eachRow((row, n) => {
        if (n >= firstRow) {
            index.set(keyCols.map((c) => text(row.getCell(c).value)).join('|'), row);
        }
    });
    const out: SheetCmp = { compared: 0, bad: 0, missingRows: 0, examples: [] };
    ref.eachRow((row, n) => {
        if (n < firstRow) {
            return;
        }

        const key = keyCols.map((c) => text(row.getCell(c).value)).join('|');

        if (!key.replace(/\|/g, '')) {
            return;
        }

        const mine = index.get(key);

        if (!mine) {
            out.missingRows++;

            if (out.examples.length < 6) {
                out.examples.push(`missing row ${key}`);
            }

            return;
        }

        row.eachCell((cell, col) => {
            if (keyCols.includes(col) || skipCols.includes(col)) {
                return;
            }

            const a = asNum(mine.getCell(col).value);
            const b = asNum(cell.value);

            // ✔ / ✖ tick
            if (col === 1) {
                out.compared++;

                if (text(mine.getCell(1).value) !== text(cell.value)) {
                    out.bad++;

                    if (out.examples.length < 6) {
                        out.examples.push(`${ref.name} ${key} tick ${text(mine.getCell(1).value)} vs ${text(cell.value)}`);
                    }
                }

                return;
            }

            if (b === null) {
                return;
            }

            out.compared++;

            if (a === null || Math.abs(a - b) > tol) {
                out.bad++;

                if (out.examples.length < 6) {
                    out.examples.push(`${ref.name} ${key} col ${col}: ${a} vs ${b}`);
                }
            }
        });
    });

    return out;
}

async function roundTrip(wb: ExcelJS.Workbook) {
    const back = new ExcelJS.Workbook();
    await back.xlsx.load(await wb.xlsx.writeBuffer());

    return back;
}

async function loadRef(path: string) {
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.readFile(path);

    return wb;
}

const baseInfo = (over: Partial<DcoInfo>): DcoInfo => ({
    zone: 'ZONE TEST', g2r: '9900000001', pm: 'SRO-BPI-1234567', stit: 'STIT', technician: 'TECH',
    generatedAt: new Date(2026, 9, 18, 14, 30), ...over,
});

/** Summary indicators: label → value. */
function summaryIndicators(wb: ExcelJS.Workbook) {
    const m = new Map<string, ExcelJS.CellValue>();
    wb.getWorksheet(DCO.sheets.summary)!.eachRow((row) => {
        const cells = row.values as ExcelJS.CellValue[];
        const li = cells.findIndex((v) => typeof v === 'string' && v.trim().endsWith(':'));
        const label = cells[li];
        const value = cells.slice(li + 1).find((v) => v !== null && v !== undefined && v !== label);

        if (typeof label === 'string') {
            m.set(label.replace(/\s+/g, ' ').trim(), value ?? null);
        }
    });

    return m;
}

function dataSheets(ours: ExcelJS.Workbook, ref: ExcelJS.Workbook, distribution: boolean, tolerances: { att: number; slope: number }, slopeTicks = true) {
    const S = DCO.sheets;

    return {
        // Distribution column 7 "total loss": estimated (indicator), compared apart.
        att: compareSheet(ours.getWorksheet(S.attenuations)!, ref.getWorksheet(S.attenuations)!, 5, [2, 3, 4], tolerances.att, distribution ? [7] : []),
        // Transport column 5 "mean": the macro copies the E→O slope of the last section by mistake; here the real mean.
        slopes: compareSheet(ours.getWorksheet(S.slopes)!, ref.getWorksheet(S.slopes)!, 5, [2, 3, 4], tolerances.slope, [...(distribution ? [] : [5]), ...(slopeTicks ? [] : [1])]),
        refl: compareSheet(ours.getWorksheet(S.reflectances)!, ref.getWorksheet(S.reflectances)!, 3, [2, 3, 4], 0.0101),
        lengths: compareSheet(ours.getWorksheet(S.lengths)!, ref.getWorksheet(S.lengths)!, 3, [2, 3, 4], 0.021),
        positions: compareSheet(ours.getWorksheet(S.positions)!, ref.getWorksheet(S.positions)!, 2, distribution ? [2, 3, 5] : [2, 3, 4], 0.021),
        constraints: compareSheet(ours.getWorksheet(S.constraints)!, ref.getWorksheet(S.constraints)!, 3, [2, 3], 0.0021),
        // OTDR settings of each .sor (numeric cells; spool flags included)
        configuration: compareSheet(ours.getWorksheet(S.configuration)!, ref.getWorksheet(S.configuration)!, 4, [1], 0.0001),
    };
}

/** Labels of the DCO indicators (identity rows excluded). */
const indicatorLabels = new Set(Object.values(DCO.indicators).map((l) => (typeof l === 'function' ? l() : l).replace(/\s+/g, ' ').trim()));

/** Indicator of the reference summary (operator inputs of the macro: NRO-PM length, Base ROP counts…). */
const declared = (wb: ExcelJS.Workbook, label: string): number => asNum(summaryIndicators(wb).get(label.replace(/\s+/g, ' ').trim()) ?? null)!;

describe.skipIf(!hasReference)('DCO workbook against the GDCO macro DCO', () => {
    for (const type of ['FTTH', 'FTTE'] as const) {
        it(`transport ${type}: sheets and values of the reference DCO`, async () => {
            const d = analyze(loadSorFolder(REFERENCE![type === 'FTTH' ? 'ftth' : 'ftte']), undefined, [], OTDR_ONLY);
            const s = evaluateGdco(d);
            const ref = await loadRef(fileIn(`${REFERENCE!.delivered}/TRANSPORT`, new RegExp(`^${type}_.*\\.xlsx$`)));
            const info = baseInfo({ transportType: type, totalFibersNroPm: declared(ref, DCO.indicators.totalFibersNroPm) });
            const ours = await roundTrip(buildDco(ExcelJS, d, s, info));
            expect(ours.worksheets.map((w) => w.name)).toEqual(ref.worksheets.map((w) => w.name));
            const sheets = Object.values(dataSheets(ours, ref, false, { att: 0.0031, slope: 0.0011 }));

            for (const x of sheets) {
                expect(x.missingRows).toBe(0);

                // a sheet left empty by the macro run (its transport attenuation sheet can be) has nothing to compare
                if (x.compared) {
                    expect(x.bad / x.compared).toBeLessThan(0.01);
                }
            }

            expect(sheets.filter((x) => x.compared).length).toBeGreaterThanOrEqual(5);
            expect(s.status).toBe('OK');
            expect(dcoFileName(d, s, info)).toBe(`${type}_ZONE TEST_SRO-BPI-1234567_Transport_20261018_1430_OK.xlsx`);
        });
    }

    it('distribution: sheets, values and indicators of the reference DCO', async () => {
        const ref = await loadRef(fileIn(`${REFERENCE!.delivered}/DISTRIBUTION`, /_Distribution_.*\.xlsx$/));
        const nroPmLengthM = declared(ref, DCO.indicators.declaredNroPmLength);
        const nroPmAttenuation1310 = declared(ref, DCO.indicators.declaredNroPmAttenuation);
        const d = analyze(loadSorFolder(REFERENCE!.distribution), undefined, [], OTDR_ONLY);
        const s = evaluateGdco(d, { nroPmLengthM });
        const rop = parseRopCsv(readFileSync(fileIn(REFERENCE!.distribution, /\.csv$/), 'utf8'));
        expect(rop).toMatchObject({ pbo: declared(ref, DCO.indicators.pboInReference), fibers: declared(ref, DCO.indicators.fibersInReference) });
        const info = baseInfo({ rop, nroPmLengthM, nroPmAttenuation1310 });
        const ours = await roundTrip(buildDco(ExcelJS, d, s, info));
        expect(ours.worksheets.map((w) => w.name)).toEqual(ref.worksheets.map((w) => w.name));
        expect(s.status).toBe('OK');

        // FiberCable re-analyses some traces (slopes of the short sections mostly): ≤ 2.5 % gaps.
        for (const x of Object.values(dataSheets(ours, ref, true, { att: 0.0031, slope: 0.0011 }))) {
            expect(x.missingRows).toBe(0);
            expect(x.bad / x.compared).toBeLessThan(0.025);
        }

        // no failed repair report: notice of the macro
        expect(ours.getWorksheet(DCO.sheets.repairs)!.getCell('D6').value).toBe(ref.getWorksheet(DCO.sheets.repairs)!.getCell('D6').value);

        const a = summaryIndicators(ours);
        const b = summaryIndicators(ref);
        const checked: string[] = [];

        for (const [label, v] of b) {
            if (!indicatorLabels.has(label) || !a.has(label) || asNum(v) === null) {
                continue;
            }

            checked.push(label);
            expect(asNum(a.get(label)!), label).toBeCloseTo(asNum(v)!, 1);
        }

        expect(checked.length).toBeGreaterThanOrEqual(14);
    });
});

describe.skipIf(!EXAMPLES_DIR || !SETS.distribution)('DCO workbook against the golden DCO of the previous tool', () => {
    it('distribution: same sheets, data and indicators', async () => {
        const d = analyze(loadSorFolder(SETS.distribution), undefined, [], OTDR_ONLY);
        const s = evaluateGdco(d, { nroPmLengthM: 335 });
        const golden = await loadRef(fileIn(EXAMPLES_DIR!, /^[^_].*_Distribution_.*\.xlsx$/));
        // Inputs of the golden DCO: Base ROP reference and declared NRO-PM values.
        const ours = await roundTrip(buildDco(ExcelJS, d, s, baseInfo({ nroPmLengthM: 335, nroPmAttenuation1310: 2.4, rop: { pbo: 84, fibers: 717 } })));
        expect(ours.worksheets.map((w) => w.name)).toEqual(golden.worksheets.map((w) => w.name));

        // The previous tool did not tick the distribution slopes (the macro does: |slope| > threshold on sections ≥ 200 m).
        for (const [name, x] of Object.entries(dataSheets(ours, golden, true, { att: 0, slope: 0 }, false))) {
            expect(x.missingRows, name).toBe(0);
            expect(x.examples, name).toEqual([]);
        }

        const a = summaryIndicators(ours);
        const b = summaryIndicators(golden);
        let checked = 0;

        for (const [label, v] of b) {
            if (!indicatorLabels.has(label) || !a.has(label) || asNum(v) === null) {
                continue;
            }

            checked++;
            expect(asNum(a.get(label)!), label).toBeCloseTo(asNum(v)!, 6);
        }

        expect(checked).toBeGreaterThanOrEqual(10);
        // criteria and traffic light of the summary
        const cellsOf = (wb: ExcelJS.Workbook) => [6, 9, 10, 11, 12, 13, 14].map((r) => text(wb.getWorksheet(DCO.sheets.summary)!.getCell(r, 9).value));
        expect(cellsOf(ours)).toEqual(cellsOf(golden));
    });
});

describe('DCO workbook', () => {
    it('file name: KO only on a red light, FTTH/FTTE prefix in transport', async () => {
        const d = analyze([]);
        const info = baseInfo({});
        expect(dcoFileName({ ...d, kind: 'distribution' }, { light: 1, status: '~OK', criteria: [], anomalies: [], indicators: {} }, info)).toBe('ZONE TEST_SRO-BPI-1234567_Distribution_20261018_1430_OK.xlsx');
        expect(dcoFileName({ ...d, kind: 'transport' }, { light: 2, status: 'KO', criteria: [], anomalies: [], indicators: {} }, { ...info, transportType: 'FTTE' })).toBe('FTTE_ZONE TEST_SRO-BPI-1234567_Transport_20261018_1430_KO.xlsx');
    });
});
