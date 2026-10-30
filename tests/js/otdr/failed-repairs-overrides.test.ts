// Failed repair report (operator template) and manual event corrections.
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import ExcelJS from 'exceljs';
import { describe, expect, it } from 'vitest';
import {
    addFailedRepair, analyze, buildFailedRepairsWorkbook, canAddFailedRepair, evaluateGdco, failedRepairsFromRows, failedRepairStatus, mergeFailedRepairs,
    OtdrError, readFailedRepairsFile, upsertOverride, type Anomaly, type Dossier, type FailedRepair,
} from '../../../resources/js/otdr';
import { FAILED_REPAIRS } from '../../../resources/js/otdr/export/format';
import { byNameHash, loadSorFolder, REFERENCE, SETS } from './support';

/** Failed-repair report delivered to the operator (OTDR_REFERENCE_DIR/failed-repairs), or null. */
function operatorReport(): string | null {
    const dir = REFERENCE?.failedRepairs;
    const name = dir && existsSync(dir) ? readdirSync(dir).find((n) => /\.xlsx$/i.test(n)) : undefined;

    return name ? `${dir}/${name}` : null;
}

describe('failed repair report', () => {
    it('reads the rows of the rules example ("1300" = 1310 nm, decimal comma)', () => {
        const { failedRepairs, errors } = failedRepairsFromRows([
            FAILED_REPAIRS.headers,
            ['SRO-BPI-1234567', 'PM022', 'PBO006', 'SRO-BPI-1234567_PBO006_B2-T5-MC', '1', '4', '0,342', '0,342', '10/01/2024', '1300'],
            ['SRO-BPI-1234567', 'PM022', 'PBO047', 'SRO-BPI-1234567_PBO047_B2-T4-MA', 3, 2, 0.406, 0.38, '10/01/2024', 1550],
            ['', '', '', 'SRO-BPI-1234567_PBO105_B2-T3-MC', 'x', '4', '', '', '', '1625'],
        ]);
        expect(failedRepairs).toEqual([
            { cableId: 'SRO-BPI-1234567_PBO006_B2-T5-MC', fiber: 1, event: 4, lambda: 1310, lastValue: 0.342, startValue: 0.342, date: new Date(Date.UTC(2024, 0, 10)) },
            { cableId: 'SRO-BPI-1234567_PBO047_B2-T4-MA', fiber: 3, event: 2, lambda: 1550, lastValue: 0.38, startValue: 0.406, date: new Date(Date.UTC(2024, 0, 10)) },
        ]);
        expect(errors).toEqual([{ key: 'failed_repair_line_invalid', params: { line: 4, fields: 'N° fibre, valeur dernière reprise, λ' } }]);
    });

    it('round trip: generated report → read back identically', async () => {
        const rows = [{ cableId: 'SRO-BPI-1_PBO001_B1-T1-MA', fiber: 11, event: 2, lambda: 1310 as const, lastValue: 0.35, startValue: 0.41, date: new Date(Date.UTC(2026, 9, 18)) }];
        const buf = await buildFailedRepairsWorkbook(ExcelJS, rows, 'SRO-BPI-1').xlsx.writeBuffer();
        const back = await readFailedRepairsFile(ExcelJS, 'PVECHECS_SRO-BPI-1.xlsx', buf as ArrayBuffer);
        expect(back).toEqual({ failedRepairs: rows, errors: [] });
    });

    it.skipIf(!operatorReport())('empty template of the operator (header of a delivered report): no row, no error', async () => {
        const report = new ExcelJS.Workbook();
        await report.xlsx.readFile(operatorReport()!);
        const wb = new ExcelJS.Workbook();
        wb.addWorksheet(report.worksheets[0]!.name).addRow((report.worksheets[0]!.getRow(1).values as ExcelJS.CellValue[]).slice(1));
        const r = await readFailedRepairsFile(ExcelJS, 'template.xlsx', (await wb.xlsx.writeBuffer()) as ArrayBuffer);
        expect(r).toEqual({ failedRepairs: [], errors: [] });
    });

    it.skipIf(!operatorReport())('report delivered to the operator: every row read; start values = values of the .sor measured before the repairs', async () => {
        const data = readFileSync(operatorReport()!);
        const { failedRepairs, errors } = await readFailedRepairsFile(ExcelJS, 'report.xlsx', data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength) as ArrayBuffer);
        expect(errors).toEqual([]);
        const wb = new ExcelJS.Workbook();
        await wb.xlsx.readFile(operatorReport()!);
        expect(failedRepairs).toHaveLength(wb.worksheets[0]!.actualRowCount - 1);
        // generated report: same header as the operator template
        expect(buildFailedRepairsWorkbook(ExcelJS, [], 'SRO-BPI-1').worksheets[0]!.getRow(1).values).toEqual(wb.worksheets[0]!.getRow(1).values);

        // The report proves the repairs with the .sor measured before them: their values are the start values (events
        // recorded by the OTDR, like FiberCable). Rows of a cable absent from the .sor names are not compared.
        const d = analyze(loadSorFolder(REFERENCE!.failedRepairs), undefined, [], { completeAnalysis: false });
        const measured = failedRepairs
            .map((p) => [p, d.fibers.find((f) => f.cableId === p.cableId && f.fiber === p.fiber && f.lambda === p.lambda)?.events[p.event - 1]] as const)
            .filter(([, e]) => e);
        expect(measured.length / failedRepairs.length).toBeGreaterThan(0.9);
        expect(measured.filter(([p, e]) => e!.value === p.startValue).length / measured.length).toBeGreaterThan(0.95);
    });

    it('refuses the old .xls format with a clear instruction', async () => {
        const error = await readFailedRepairsFile(ExcelJS, 'PVECHECS_SRO-BPI-1.xls', new ArrayBuffer(8)).catch((e: unknown) => e);
        expect(error).toBeInstanceOf(OtdrError);
        expect((error as OtdrError).detail.key).toBe('failed_repair_xls_unsupported');
    });

    it('screen helpers: add, merge and status of the rows', () => {
        const d = { kind: 'distribution' } as Dossier;
        const a: Anomaly = { type: 'splice', cableId: 'SRO-BPI-1_PBO001_B1-T1-MA', fiber: 2, lambda: 1310, index: 2, value: 0.35, threshold: 0.3, blocking: true };
        expect(canAddFailedRepair(a, d, [])).toBe(true);
        const list = addFailedRepair([], a, new Date(0));
        expect(list).toEqual([{ cableId: a.cableId, fiber: 2, event: 2, lambda: 1310, lastValue: 0.35, startValue: 0.35, date: new Date(0) }]);
        expect(canAddFailedRepair(a, d, list)).toBe(false);
        expect(canAddFailedRepair({ ...a, value: 0.75 }, d, [])).toBe(false);
        const merged = mergeFailedRepairs(list, [{ ...list[0]!, lastValue: 0.34 }]);
        expect(merged).toHaveLength(1);
        expect(merged[0]!.lastValue).toBe(0.34);
        const summary = (an: Anomaly) => ({ light: 0 as const, status: 'OK' as const, criteria: [], anomalies: [an], indicators: {} });
        const row: FailedRepair = list[0]!;
        expect(failedRepairStatus(row, summary({ ...a, neutralized: true, blocking: false }))).toEqual({ ok: true, message: { key: 'failed_repair_status_neutralized', params: {} } });
        expect(failedRepairStatus(row, summary({ ...a, value: 0.75 })).message).toEqual({ key: 'failed_repair_status_over_limit', params: { value: 0.75 } });
        expect(failedRepairStatus({ ...row, lastValue: 0.36 }, summary(a)).message).toEqual({ key: 'failed_repair_status_value_mismatch', params: { value: 0.35, report_value: 0.36 } });
        expect(failedRepairStatus({ ...row, fiber: 9 }, summary(a)).message.key).toBe('failed_repair_status_not_found');
    });
});

describe('corrections list', () => {
    it('one correction per file and event; removing restores the automatic value', () => {
        const list = upsertOverride([], { file: 'a.sor', event: 2, action: 'ignore' });
        const retyped = upsertOverride(list, { file: 'a.sor', event: 2, action: 'connector' });
        expect(retyped).toEqual([{ file: 'a.sor', event: 2, action: 'connector' }]);
        expect(upsertOverride(retyped, { file: 'a.sor', event: 2, action: 'connector' }, true)).toEqual([]);
    });
});

describe.skipIf(!SETS.distribution)('event corrections', () => {
    const files = loadSorFolder(SETS.distribution);
    const name = byNameHash(files, '864d41f613c1')[0]?.name ?? '';
    const fiber = (d: ReturnType<typeof analyze>) => d.fibers.find((f) => f.files[0] === name)!;

    it('ignoring a splice: event removed, sections merged', () => {
        const base = fiber(analyze(files));
        expect(base.events.map((e) => e.num)).toEqual([1, 2]);
        const edited = fiber(analyze(files, undefined, [{ file: name, event: 2, action: 'ignore' }]));
        expect(edited.events.map((e) => e.num)).toEqual([1]);
        expect(edited.sections).toHaveLength(1);
        expect(edited.sections[0]!.lengthM).toBeCloseTo(base.sections[0]!.lengthM + base.sections[1]!.lengthM, 6);
        expect(edited.edited).toBe(true);
        expect(base.edited).toBe(false);
    });

    it('the fiber end cannot be ignored', () => {
        const edited = fiber(analyze(files, undefined, [{ file: name, event: 3, action: 'ignore' }]));
        expect(edited.sections).toHaveLength(2);
    });

    it('changing the kind of an event changes the applied threshold', () => {
        // splice −0.295 dB: |v| ≤ 0.30; retyped as connector, checked against the connector threshold
        const d = analyze(files, undefined, [{ file: name, event: 2, action: 'connector' }]);
        expect(fiber(d).events[1]!.kind).toBe('connector');
        expect(evaluateGdco(d).status).toBe('OK');
    });
});
