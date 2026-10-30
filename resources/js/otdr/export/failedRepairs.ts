// Failed splice repair report (measurement rules §9.2.2): one splice per row, columns of the operator template
// A SRO-BPI · B PM · C PBO · D cable ID · E fiber · F event number · G initial value (OE)
// H last repair value (OE) · I repair date · J λ ("1300" accepted for 1310 nm).

import type { Workbook } from 'exceljs';
import { message, OtdrError, type Message } from '../engine/messages';
import { pboOf, type FailedRepair } from '../engine/rules';
import type { Lambda } from '../engine/types';
import { type ExcelJS, loadExcelJS } from './excel';
import { FAILED_REPAIRS } from './format';

type Raw = unknown;

const norm = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

type Column = keyof typeof FAILED_REPAIRS.importHeaders;

const DEFAULT_COL: Record<Column, number> = { sro: 0, pm: 1, pbo: 2, cableId: 3, fiber: 4, event: 5, startValue: 6, lastValue: 7, date: 8, lambda: 9 };

export interface FailedRepairImport {
    failedRepairs: FailedRepair[];
    errors: Message[];
}

function cellText(v: Raw): string {
    if (v === null || v === undefined) {
        return '';
    }

    if (v instanceof Date) {
        return v.toISOString();
    }

    if (typeof v === 'object' && v && 'result' in v) {
        return cellText((v as { result: Raw }).result);
    }

    if (typeof v === 'object' && v && 'richText' in v) {
        return ((v as { richText: { text: string }[] }).richText).map((r) => r.text).join('');
    }

    return String(v).trim();
}

function num(v: Raw): number | null {
    if (typeof v === 'number') {
        return v;
    }

    const t = cellText(v).replace(',', '.');

    if (!t) {
        return null;
    }

    const n = Number(t);

    return Number.isFinite(n) ? n : null;
}

function date(v: Raw): Date | undefined {
    if (v instanceof Date) {
        return v;
    }

    const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(cellText(v));

    return m ? new Date(Date.UTC(Number(m[3]), Number(m[2]) - 1, Number(m[1]))) : undefined;
}

/** Raw rows (1st row = headers) → report rows; errors are described row by row. */
export function failedRepairsFromRows(rows: Raw[][]): FailedRepairImport {
    const header = (rows[0] ?? []).map((c) => norm(cellText(c)));
    const col = { ...DEFAULT_COL };

    for (const [k, re] of Object.entries(FAILED_REPAIRS.importHeaders) as [Column, RegExp][]) {
        const i = header.findIndex((h) => re.test(h));

        if (i >= 0) {
            col[k] = i;
        }
    }

    const failedRepairs: FailedRepair[] = [];
    const errors: Message[] = [];
    const F = FAILED_REPAIRS.fieldNames;
    rows.slice(1).forEach((r, i) => {
        const line = i + 2;

        if (r.every((c) => cellText(c) === '')) {
            return;
        }

        const cableId = cellText(r[col.cableId]);
        const fiber = num(r[col.fiber]);
        const event = num(r[col.event]);
        const lastValue = num(r[col.lastValue]);
        const l = num(r[col.lambda]);
        const lambda: Lambda | null = l === 1310 || l === 1300 ? 1310 : l === 1550 ? 1550 : null;
        const missing = [!cableId && F.cableId, fiber === null && F.fiber, event === null && F.event, lastValue === null && F.lastValue, !lambda && F.lambda].filter(Boolean);

        if (missing.length) {
            errors.push(message('failed_repair_line_invalid', { line, fields: missing.join(', ') }));

            return;
        }

        const startValue = num(r[col.startValue]);
        failedRepairs.push({ cableId, fiber: fiber!, event: event!, lambda: lambda!, lastValue: lastValue!, startValue: startValue ?? undefined, date: date(r[col.date]) });
    });

    return { failedRepairs, errors };
}

/** Reads a report file (.xlsx or .csv; an old .xls must be saved again as .xlsx). Throws an OtdrError. */
export async function readFailedRepairsFile(excel: ExcelJS, name: string, data: ArrayBuffer): Promise<FailedRepairImport> {
    if (/\.xls$/i.test(name)) {
        throw new OtdrError(message('failed_repair_xls_unsupported'));
    }

    if (/\.csv$/i.test(name)) {
        const text = new TextDecoder().decode(data);
        const sep = text.includes(';') ? ';' : ',';

        return failedRepairsFromRows(text.split(/\r?\n/).map((l) => l.split(sep)));
    }

    const wb = new excel.Workbook();
    await wb.xlsx.load(data);
    const ws = wb.worksheets[0];

    if (!ws) {
        throw new OtdrError(message('failed_repair_empty_workbook'));
    }

    const rows: Raw[][] = [];
    ws.eachRow({ includeEmpty: true }, (row) => {
        rows.push((row.values as Raw[]).slice(1));
    });

    return failedRepairsFromRows(rows);
}

/** Same as `readFailedRepairsFile`, loading ExcelJS on demand (screen import). */
export async function importFailedRepairsFile(name: string, data: ArrayBuffer): Promise<FailedRepairImport> {
    return readFailedRepairsFile(await loadExcelJS(), name, data);
}

/** Report workbook in the operator template format ("PVECHECS"). */
export function buildFailedRepairsWorkbook(excel: ExcelJS, failedRepairs: FailedRepair[], pm: string): Workbook {
    const wb = new excel.Workbook();
    const ws = wb.addWorksheet(FAILED_REPAIRS.sheet);
    const widths = [20, 13, 21, 48, 13, 19, 18, 18, 21, 12];
    const border = { top: { style: 'thin' as const }, left: { style: 'thin' as const }, bottom: { style: 'thin' as const }, right: { style: 'thin' as const } };
    FAILED_REPAIRS.headers.forEach((h, i) => {
        const c = ws.getCell(1, i + 1);
        c.value = h;
        c.font = { bold: true, size: 10 };
        c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
        c.border = border;
        ws.getColumn(i + 1).width = widths[i]!;
    });
    ws.getRow(1).height = 26;
    failedRepairs.forEach((p, n) => {
        const values = [pm, '', pboOf(p.cableId), p.cableId, p.fiber, p.event, p.startValue ?? null, p.lastValue, p.date ?? null, p.lambda];
        values.forEach((v, i) => {
            const c = ws.getCell(n + 2, i + 1);
            c.value = v as never;
            c.alignment = { horizontal: 'center' };
            c.border = border;

            if (v instanceof Date) {
                c.numFmt = 'dd/mm/yyyy';
            }
        });
    });

    return wb;
}

export const failedRepairsFileName = (pm: string) => FAILED_REPAIRS.fileName(pm);
