// "Measurement dossier" workbook in the FiberCable macro format (VIAVI): Title, Contents, General information,
// Measurement information, Lengths, Connectors & splices, Reflectances, Slopes, [Link budgets], [ORL], Out of limits.
// Layout taken from the dossier produced by FiberCable 2 v8.68. Generated and identified with the configured product.

import type { Borders, Workbook, Worksheet } from 'exceljs';
import type { Profile } from '../engine/profile';
import type { Anomaly, AnomalyType, Summary } from '../engine/rules';
import type { Dossier, FiberResult, FileMeta, Lambda } from '../engine/types';
import type { ExcelJS } from './excel';
import { REPORT } from './format';

export interface CableInfo {
    name: string;
    origin: string;
    extremity: string;
    connection?: string;
    projectManager?: string;
    cableManufacturer?: string;
    cableType?: string;
    fiberManufacturer?: string;
    fiberType?: string;
}

export interface ReportOptions {
    /** Link budget sheet per fiber. */
    totalLoss: boolean;
    /** ORL sheet (unidirectional measurements). */
    orl: boolean;
    /** "1550 − 1310 Difference" columns (dual wavelength comparison). */
    comparison: boolean;
    /** Out of limits sheet. */
    outOfLimits: boolean;
}

export const DEFAULT_REPORT_OPTIONS: ReportOptions = { totalLoss: true, orl: true, comparison: true, outOfLimits: true };

const GREY = 'FFC0C0C0';
const thin = { style: 'thin' as const };
const BOX: Partial<Borders> = { top: thin, left: thin, bottom: thin, right: thin };
const r3 = (v: number) => Number(v.toFixed(3));
const cmp = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);
const LAMBDAS: Lambda[] = [1310, 1550];
const safe = (v: string) => v.replace(/[\\/:*?"<>|]/g, '_');

interface Ctx {
    d: Dossier;
    s: Summary;
    p: Profile;
    info: CableInfo;
    opt: ReportOptions;
    bidirectional: boolean;
    multiCable: boolean;
    /** Fibers (cable + number) in order, with their results per wavelength. */
    fibers: { cableId: string; fiber: number; at: Partial<Record<Lambda, FiberResult>> }[];
    nEvents: number;
    nSections: number;
    eventLabels: string[];
    refSections: number[];
    meta: Map<string, FileMeta>;
    anomalies: Set<string>;
    generatedAt: Date;
}

function head(ws: Worksheet, row: number, col: number, value: string | number, merge?: [number, number], wrap = false) {
    if (merge && (merge[0] || merge[1])) {
        ws.mergeCells(row, col, row + merge[0], col + merge[1]);
    }

    const c = ws.getCell(row, col);
    c.value = value;
    c.font = { bold: true, size: 10 };
    c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: GREY } };
    c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: wrap };
    c.border = BOX;
}

function num(ws: Worksheet, row: number, col: number, v: number | null | undefined, fmt = '0.000', red = false) {
    const c = ws.getCell(row, col);

    if (v !== null && v !== undefined && Number.isFinite(v)) {
        c.value = r3(v);
    }

    c.numFmt = fmt;
    c.border = BOX;
    c.alignment = { horizontal: 'center' };

    if (red) {
        c.font = { bold: true, color: { argb: 'FFFF0000' } };
    }
}

function text(ws: Worksheet, addr: string, v: string | number, opts: { bold?: boolean; size?: number; align?: 'left' | 'right' | 'center' } = {}) {
    const c = ws.getCell(addr);
    c.value = v;
    c.font = { bold: opts.bold, size: opts.size ?? 10 };

    if (opts.align) {
        c.alignment = { horizontal: opts.align };
    }
}

/** Shared page head: cable / origin / extremity reminder on the right and sheet title (row 7). */
function pageHead(ws: Worksheet, x: Ctx, title: string, lastCol: number) {
    const L = ws.getColumn(lastCol).letter;
    text(ws, `${L}2`, REPORT.productName(), { align: 'right', size: 8 });
    text(ws, `${L}3`, x.info.name, { align: 'right' });
    text(ws, `${L}4`, x.info.origin, { align: 'right' });
    text(ws, `${L}5`, x.info.extremity, { align: 'right' });
    ws.mergeCells(7, 2, 7, Math.max(2, lastCol - 1));
    text(ws, 'B7', title, { bold: true, size: 14, align: 'center' });
    ws.getRow(7).height = 20;
}

function fiberLabel(x: Ctx, f: { cableId: string; fiber: number }): string | number {
    return x.multiCable ? REPORT.tables.fiberOfCable(f.cableId, f.fiber) : f.fiber;
}

const anomalyKey = (cable: string, fiber: number, lambda: number | null, index: number, type: AnomalyType) => `${cable}|${fiber}|${lambda}|${index}|${type}`;

export function buildMeasurementReport(excel: ExcelJS, d: Dossier, s: Summary, p: Profile, info: CableInfo, opt: ReportOptions = DEFAULT_REPORT_OPTIONS, generatedAt = new Date()): Workbook {
    const byFiber = new Map<string, Ctx['fibers'][number]>();

    for (const f of [...d.fibers].sort((a, b) => cmp(a.cableId, b.cableId) || a.fiber - b.fiber)) {
        const k = `${f.cableId}|${f.fiber}`;
        const e = byFiber.get(k) ?? { cableId: f.cableId, fiber: f.fiber, at: {} };
        e.at[f.lambda] = f;
        byFiber.set(k, e);
    }

    const fibers = [...byFiber.values()];
    const nEvents = Math.max(1, ...d.fibers.map((f) => f.events.length));
    const nSections = Math.max(1, ...d.fibers.map((f) => f.sections.length));
    // Event labels: connectors at both ends, numbered splices
    const eventLabels: string[] = [];
    let spliceNo = 0;

    for (let i = 0; i < nEvents; i++) {
        const kinds = d.fibers.map((f) => f.events[i]?.kind).filter(Boolean);
        const allConnectors = kinds.length > 0 && kinds.every((k) => k === 'connector');

        if (allConnectors && i === 0) {
            eventLabels.push(REPORT.tables.connectorO);
        } else if (allConnectors) {
            eventLabels.push(REPORT.tables.connectorE);
        } else {
            eventLabels.push(REPORT.tables.splice(++spliceNo));
        }
    }

    // Reference lengths: fiber with the lowest number, mean of the wavelengths (like FiberCable)
    const ref = fibers[0];
    const refSections = Array.from({ length: nSections }, (_, j) => {
        const v = LAMBDAS.map((l) => ref?.at[l]?.sections[j]?.lengthM).filter((x): x is number => x !== undefined);

        return v.length ? v.reduce((a, b) => a + b, 0) / v.length : 0;
    });
    const anomalies = new Set(s.anomalies.map((a) => anomalyKey(a.cableId, a.fiber, a.lambda, a.index, a.type)));
    const x: Ctx = {
        d, s, p, info, opt, bidirectional: d.kind === 'transport', multiCable: new Set(fibers.map((f) => f.cableId)).size > 1,
        fibers, nEvents, nSections, eventLabels, refSections, meta: new Map(d.files.map((m) => [m.name, m])), anomalies, generatedAt,
    };
    const S = REPORT.sheets;
    const T = REPORT.contents;
    const wb = new excel.Workbook();
    wb.creator = REPORT.creator();
    wb.created = generatedAt;
    const sheets: [string, string][] = [];
    titleSheet(wb.addWorksheet(S.title), x);
    const toc = wb.addWorksheet(S.contents);
    generalSheet(wb.addWorksheet(S.general), x);
    sheets.push([S.general, T.general]);
    measurementSheet(wb.addWorksheet(S.measurement), x);
    sheets.push([S.measurement, T.measurement]);
    lengthsSheet(wb.addWorksheet(S.lengths), x);
    sheets.push([S.lengths, T.lengths]);
    splicesSheet(wb.addWorksheet(S.splices), x);
    sheets.push([S.splices, T.splices]);
    reflectancesSheet(wb.addWorksheet(S.reflectances), x);
    sheets.push([S.reflectances, T.reflectances]);
    slopesSheet(wb.addWorksheet(S.slopes), x);
    sheets.push([S.slopes, T.slopes]);

    if (opt.totalLoss) {
        totalLossSheet(wb.addWorksheet(S.totalLoss), x);
        sheets.push([S.totalLoss, T.totalLoss]);
    }

    if (opt.orl && !x.bidirectional) {
        orlSheet(wb.addWorksheet(S.orl), x);
        sheets.push([S.orl, T.orl]);
    }

    if (opt.outOfLimits) {
        outOfLimitsSheet(wb.addWorksheet(S.outOfLimits), x);
        sheets.push([S.outOfLimits, T.outOfLimits]);
    }

    contentsSheet(toc, x, sheets);

    return wb;
}

export function measurementReportFileName(info: CableInfo, s: Summary): string {
    return REPORT.fileName(safe(info.name || REPORT.defaultName), s.status === 'OK');
}

// ------------------------------------------------------------------ information pages

function titleSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.title;
    [11.4, 5, 20.7, 11.1, 14, 21.6, 5.4].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    ws.mergeCells('B4:G4');
    text(ws, 'B4', L.heading, { bold: true, size: 20, align: 'center' });
    ws.getRow(4).height = 26;
    text(ws, 'D6', L.cableName, { align: 'right' });
    text(ws, 'E6', x.info.name);
    text(ws, 'C12', L.projectManager);
    text(ws, 'E12', x.info.projectManager ?? '');
    text(ws, 'C13', L.connection);
    text(ws, 'E13', x.info.connection ?? '');
    text(ws, 'C14', L.date);
    const c = ws.getCell('F14');
    c.value = x.generatedAt;
    c.numFmt = 'dd/mm/yyyy';
    text(ws, 'C15', L.signature);
    text(ws, 'C18', L.generatedBy(), { size: 8 });
}

function contentsSheet(ws: Worksheet, x: Ctx, sheets: [string, string][]) {
    [18.7, 35.3, 11.6, 6.4, 18.7].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    pageHead(ws, x, REPORT.contents.title, 5);
    sheets.forEach(([sheet, label], i) => {
        const c = ws.getCell(10 + i, 2);
        c.value = { text: label, hyperlink: `#'${sheet}'!A1` };
        c.font = { color: { argb: 'FF0563C1' }, underline: true };
    });
}

function generalSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.general;
    [10.7, 2.7, 25.7, 20.6, 22, 2.7].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    pageHead(ws, x, REPORT.contents.general, 7);
    text(ws, 'B9', L.link, { bold: true, size: 12 });
    const rows: [string, string | number][] = [[L.cableName, x.info.name], [L.origin, x.info.origin], [L.extremity, x.info.extremity]];
    rows.forEach(([k, v], i) => {
        text(ws, `C${11 + i}`, k);
        text(ws, `E${11 + i}`, v, { align: 'right' });
    });
    text(ws, 'B16', L.cable, { bold: true, size: 12 });
    const fiberNums = x.fibers.map((f) => f.fiber);
    const cable: [string, string | number][] = [
        [L.connection, x.info.connection ?? ''], [L.cableManufacturer, x.info.cableManufacturer ?? ''], [L.cableType, x.info.cableType ?? ''],
        [L.fiberManufacturer, x.info.fiberManufacturer ?? ''], [L.fiberType, x.info.fiberType ?? ''], ['', ''],
        [L.fibers, x.fibers.length], [L.firstFiber, Math.min(...fiberNums)], [L.lastFiber, Math.max(...fiberNums)],
        [L.sections, x.nSections],
    ];
    cable.forEach(([k, v], i) => {
        if (k) {
            text(ws, `C${19 + i}`, k);
            text(ws, `E${19 + i}`, v, { align: 'right' });
        }
    });
    text(ws, 'C31', L.measureType);
    text(ws, 'E31', L.modes[x.d.mode], { align: 'right' });
}

function measurementSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.measurement;
    [10.7, 2.1, 3.7, 26.3, 10.1, 10, 10, 10, 12, 12, 2.1].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    pageHead(ws, x, REPORT.contents.measurement, 12);
    const oe = x.d.files[0];
    const eoName = x.bidirectional ? x.d.fibers.find((f) => f.files[1])?.files[1] : undefined;
    const eo = eoName ? x.meta.get(eoName) : oe;
    const at = (l: Lambda) => x.d.files.find((f) => Math.abs(f.lambda - l) < 15);
    text(ws, 'B11', L.otdr, { bold: true });
    ws.mergeCells('G12:H12');
    ws.mergeCells('I12:J12');
    text(ws, 'G12', L.origin, { align: 'center' });
    text(ws, 'I12', x.bidirectional ? L.extremity : '', { align: 'center' });
    const otdr: [string, keyof FileMeta][] = [[L.otdrType, 'otdr'], [L.otdrSerial, 'otdrSerial'], [L.moduleType, 'module'], [L.moduleSerial, 'moduleSerial']];
    otdr.forEach(([k, f], i) => {
        const r = 13 + i;
        text(ws, `C${r}`, k);
        ws.mergeCells(`G${r}:H${r}`);
        ws.mergeCells(`I${r}:J${r}`);
        text(ws, `G${r}`, String(oe?.[f] ?? ''), { align: 'center' });

        if (x.bidirectional) {
            text(ws, `I${r}`, String(eo?.[f] ?? ''), { align: 'center' });
        }
    });
    text(ws, 'C18', L.wavelengths);
    text(ws, 'I18', 1310, { align: 'right' });
    text(ws, 'J18', 1550, { align: 'right' });
    text(ws, 'C19', L.ior);
    num(ws, 19, 9, at(1310)?.ior, '0.0000');
    num(ws, 19, 10, at(1550)?.ior, '0.0000');
    text(ws, 'C20', L.pulse);
    num(ws, 20, 9, at(1310)?.pulseNs, '0');
    num(ws, 20, 10, at(1550)?.pulseNs, '0');
    text(ws, 'C21', L.thresholds(x.p.name));
    const t = x.p.thresholds;
    const A = x.p.active;
    const thresholds: [string, (l: Lambda) => number | null][] = [
        [L.splice, (l) => (A.splice ? t[l].splice : null)], [L.meanSplices, (l) => (A.fiberSplice ? t[l].fiberSplice : null)],
        [L.connector, (l) => (A.connector ? t[l].connector : null)], [L.meanConnectors, (l) => (A.fiberConnector ? t[l].fiberConnector : null)],
        [L.slope, (l) => (A.slope ? t[l].slope : null)], [L.meanSlopes, (l) => (A.fiberSlope ? t[l].fiberSlope : null)],
        [L.reflectance, (l) => (A.reflectance ? t[l].reflectance : null)], [L.totalLossMin, (l) => (A.totalLossMin ? t[l].totalLossMin : null)],
        [L.totalLossMax, (l) => (A.totalLossMax ? t[l].totalLossMax : null)], [L.orl, (l) => (A.orl ? t[l].orl : null)],
    ];
    thresholds.forEach(([k, f], i) => {
        const r = 22 + i;
        text(ws, `D${r}`, k);

        for (const [j, l] of [[9, 1310], [10, 1550]] as const) {
            const v = f(l);

            if (v === null) {
                text(ws, `${ws.getColumn(j).letter}${r}`, L.inactive, { align: 'right' });
            } else {
                num(ws, r, j, v, '0.00');
            }
        }
    });
    text(ws, 'D32', L.constraint);

    if (x.p.constraint === null) {
        text(ws, 'F32', L.inactive);
    } else {
        num(ws, 32, 6, x.p.constraint, '0.00');
    }

    text(ws, 'C34', L.backscatter);
    num(ws, 34, 9, at(1310)?.backscatterDb, '0.00');
    num(ws, 34, 10, at(1550)?.backscatterDb, '0.00');
    text(ws, 'C36', L.software, { bold: true });
    text(ws, 'D37', REPORT.productName());
    text(ws, 'G37', L.version());
}

// ------------------------------------------------------------------ measurement tables

function lengthsSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.lengths;
    [7.7, 13.7, 16, 16, 47.1].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    pageHead(ws, x, REPORT.contents.lengths, 6);
    head(ws, 9, 2, L.section, [1, 0], true);
    head(ws, 9, 3, L.unit, [1, 0], true);
    head(ws, 9, 4, L.cumulated, [1, 0], true);
    head(ws, 9, 5, L.comment, [1, 0]);
    let cum = 0;
    x.refSections.forEach((l, j) => {
        cum += l;
        const r = 11 + j;
        num(ws, r, 2, j + 1, '0');
        num(ws, r, 3, l, '0.00');
        num(ws, r, 4, cum, '0.00');
        num(ws, r, 5, null);
    });
}

/** Fiber column of a table row (fiber, with the cable when several). */
function fiberCol(ws: Worksheet, row: number, f: Ctx['fibers'][number], x: Ctx) {
    const c = ws.getCell(row, 1);
    c.value = fiberLabel(x, f);
    c.border = BOX;
}

function splicesSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.tables;
    const per = x.bidirectional ? (x.opt.comparison ? 7 : 6) : (x.opt.comparison ? 3 : 2);
    const n = x.nEvents;
    const endCol = 1 + n * per + 6;
    ws.getColumn(1).width = x.multiCable ? 44 : 14.6;
    pageHead(ws, x, REPORT.contents.splices, Math.min(endCol, 16));
    head(ws, 9, 1, L.event);
    head(ws, 10, 1, L.cumulatedLength);
    head(ws, 11, 1, L.length);
    head(ws, 12, 1, L.fiber, [1, 0]);
    let cum = 0;

    for (let i = 0; i < n; i++) {
        const c0 = 2 + i * per;
        head(ws, 9, c0, x.eventLabels[i]!, [0, per - 1]);
        const sec = i === 0 ? 0 : x.refSections[i - 1] ?? 0;
        cum += sec;
        ws.mergeCells(10, c0, 10, c0 + per - 1);
        num(ws, 10, c0, cum, '0.00');
        ws.mergeCells(11, c0, 11, c0 + per - 1);
        num(ws, 11, c0, sec, '0.00');
        const sub = x.bidirectional ? [L.oe, L.eo, L.mean] : [L.oe];
        LAMBDAS.forEach((l, k) => {
            const c = c0 + k * sub.length;
            head(ws, 12, c, L.nm(l), sub.length > 1 ? [0, sub.length - 1] : undefined);
            sub.forEach((t, j) => head(ws, 13, c + j, t));
        });

        if (x.opt.comparison) {
            head(ws, 12, c0 + per - 1, L.difference, [1, 0], true);
        }
    }

    const sumCol = 2 + n * per;
    ([[L.mean, 0], [L.maximum, 2], [L.totalLoss, 4]] as const).forEach(([label, off]) => {
        head(ws, 9, sumCol + off, label, [2, 1], true);
        LAMBDAS.forEach((l, k) => head(ws, 12, sumCol + off + k, L.nm(l), [1, 0]));
    });
    x.fibers.forEach((f, r0) => {
        const r = 14 + r0;
        fiberCol(ws, r, f, x);

        for (let i = 0; i < n; i++) {
            const c0 = 2 + i * per;
            LAMBDAS.forEach((l, k) => {
                const fr = f.at[l];
                const e = fr?.events[i];
                const bad = !!e && x.anomalies.has(anomalyKey(f.cableId, f.fiber, l, e.index, e.kind));

                if (x.bidirectional) {
                    num(ws, r, c0 + k * 3, e?.oe);
                    num(ws, r, c0 + k * 3 + 1, e?.eo);
                    num(ws, r, c0 + k * 3 + 2, e?.value, '0.000', bad);
                } else {
                    num(ws, r, c0 + k, e?.value, '0.000', bad);
                }
            });

            if (x.opt.comparison) {
                const a = f.at[1310]?.events[i];
                const b = f.at[1550]?.events[i];
                num(ws, r, c0 + per - 1, a && b ? b.value - a.value : null, '0.000', !!a && x.anomalies.has(anomalyKey(f.cableId, f.fiber, null, a.index, 'constraint')));
            }
        }

        LAMBDAS.forEach((l, k) => {
            const fr = f.at[l];
            const spl = fr ? fr.events.filter((e) => e.kind === 'splice').map((e) => e.value) : [];
            num(ws, r, sumCol + k, spl.length ? spl.reduce((a, b) => a + b, 0) / spl.length : null);
            num(ws, r, sumCol + 2 + k, spl.length ? Math.max(...spl) : null);
            num(ws, r, sumCol + 4 + k, fr?.totalLoss, '0.000', !!fr && x.anomalies.has(anomalyKey(f.cableId, f.fiber, l, 0, 'total_loss')));
        });
    });
    // Row of the means per value column
    const last = 14 + x.fibers.length;
    head(ws, last, 1, L.mean);

    for (let i = 0; i < n; i++) {
        LAMBDAS.forEach((l, k) => {
            const vals = x.fibers.map((f) => f.at[l]?.events[i]?.value).filter((v): v is number => v !== undefined);
            const col = 2 + i * per + (x.bidirectional ? k * 3 + 2 : k);
            num(ws, last, col, vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : null);
        });
    }

    for (let c = 2; c <= endCol; c++) {
        if (!ws.getColumn(c).width) {
            ws.getColumn(c).width = 9.7;
        }
    }

    ws.views = [{ state: 'frozen', xSplit: 1, ySplit: 13 }];
}

function reflectancesSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.tables;
    const cols = x.eventLabels.map((l, i) => ({ l, i })).filter(({ i }) => x.d.fibers.some((f) => (f.events[i]?.reflOe !== null && f.events[i]?.reflOe !== undefined) || f.events[i]?.reflEo));
    const per = x.bidirectional ? 4 : 2;
    ws.getColumn(1).width = x.multiCable ? 44 : 12.3;
    pageHead(ws, x, REPORT.contents.reflectances, Math.max(6, 2 + cols.length * per + 1));
    head(ws, 9, 1, L.event);
    head(ws, 10, 1, L.fiber, [1, 0], true);
    cols.forEach(({ l }, j) => {
        const c0 = 2 + j * per;
        head(ws, 9, c0, l, [0, per - 1]);
        LAMBDAS.forEach((lambda, k) => {
            const c = c0 + k * (per / 2);
            head(ws, 10, c, L.nm(lambda), per > 2 ? [0, 1] : undefined);

            if (x.bidirectional) {
                head(ws, 11, c, L.oe);
                head(ws, 11, c + 1, L.eo);
            } else {
                head(ws, 11, c, L.oe);
            }
        });
    });
    const commentCol = 2 + cols.length * per;
    head(ws, 10, commentCol, L.comment, [1, 0]);
    ws.getColumn(commentCol).width = 30;
    x.fibers.forEach((f, r0) => {
        const r = 12 + r0;
        fiberCol(ws, r, f, x);
        cols.forEach(({ i }, j) => {
            const c0 = 2 + j * per;
            LAMBDAS.forEach((l, k) => {
                const e = f.at[l]?.events[i];
                const badOf = (v: number | null | undefined) => v !== null && v !== undefined && v > x.p.thresholds[l].reflectance;
                const c = c0 + k * (per / 2);
                num(ws, r, c, e?.reflOe, '0.00', badOf(e?.reflOe));

                if (x.bidirectional) {
                    num(ws, r, c + 1, e?.reflEo, '0.00', badOf(e?.reflEo));
                }
            });
        });
        num(ws, r, commentCol, null);
    });
}

function slopesSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.tables;
    const per = x.bidirectional ? 6 : 2;
    const ns = x.nSections;
    ws.getColumn(1).width = x.multiCable ? 44 : 12.3;
    pageHead(ws, x, REPORT.contents.slopes, Math.min(2 + ns * per + 2, 16));
    head(ws, 9, 1, L.event);
    head(ws, 10, 1, L.length);
    head(ws, 11, 1, L.fiber, [x.bidirectional ? 1 : 0, 0], true);

    for (let j = 0; j < ns; j++) {
        const c0 = 2 + j * per;
        head(ws, 9, c0, L.slope(j + 1), [0, per - 1]);
        ws.mergeCells(10, c0, 10, c0 + per - 1);
        num(ws, 10, c0, x.refSections[j], '0.00');
        LAMBDAS.forEach((l, k) => {
            const c = c0 + k * (per / 2);
            head(ws, 11, c, L.nm(l), per > 2 ? [0, 2] : undefined);

            if (x.bidirectional) {
                [L.oe, L.eo, L.mean].forEach((t, m) => head(ws, 12, c + m, t));
            }
        });
    }

    const mc = 2 + ns * per;
    head(ws, 9, mc, L.meanSlopes, [x.bidirectional ? 2 : 1, 1], true);
    LAMBDAS.forEach((l, k) => head(ws, x.bidirectional ? 12 : 11, mc + k, L.nm(l)));
    const first = x.bidirectional ? 13 : 12;
    x.fibers.forEach((f, r0) => {
        const r = first + r0;
        fiberCol(ws, r, f, x);
        LAMBDAS.forEach((l, k) => {
            const fr = f.at[l];

            for (let j = 0; j < ns; j++) {
                const s = fr?.sections[j];
                const c = 2 + j * per + k * (per / 2);
                const bad = !!s && x.anomalies.has(anomalyKey(f.cableId, f.fiber, l, s.index, 'slope'));

                if (x.bidirectional) {
                    num(ws, r, c, s?.slopeOe);
                    num(ws, r, c + 1, s?.slopeEo);
                    num(ws, r, c + 2, s?.slope, '0.000', bad);
                } else {
                    num(ws, r, c, s?.slope, '0.000', bad);
                }
            }

            const sl = fr ? fr.sections.map((s) => s.slope).filter((v): v is number => v !== null) : [];
            num(ws, r, mc + k, sl.length ? sl.reduce((a, b) => a + b, 0) / sl.length : null);
        });
    });
}

function totalLossSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.tables;
    ws.getColumn(1).width = x.multiCable ? 44 : 14;
    pageHead(ws, x, L.totalLossTitle, 9);
    head(ws, 9, 1, L.fiber, [1, 0], true);
    const sub = x.bidirectional ? [L.oeArrow, L.eoArrow, L.mean] : [L.oeArrow];
    LAMBDAS.forEach((l, k) => {
        const c = 2 + k * sub.length;
        head(ws, 9, c, L.nm(l), sub.length > 1 ? [0, sub.length - 1] : undefined);
        sub.forEach((t, j) => head(ws, 10, c + j, t));
    });
    x.fibers.forEach((f, r0) => {
        const r = 11 + r0;
        fiberCol(ws, r, f, x);
        LAMBDAS.forEach((l, k) => {
            const fr = f.at[l];
            const c = 2 + k * sub.length;
            const bad = !!fr && x.anomalies.has(anomalyKey(f.cableId, f.fiber, l, 0, 'total_loss'));

            if (x.bidirectional) {
                num(ws, r, c, fr?.totalLossOe);
                num(ws, r, c + 1, fr?.totalLossEo);
                num(ws, r, c + 2, fr?.totalLoss, '0.000', bad);
            } else {
                num(ws, r, c, fr?.totalLoss, '0.000', bad);
            }
        });
    });
}

function orlSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.tables;
    ws.getColumn(1).width = x.multiCable ? 44 : 14;
    pageHead(ws, x, L.orlTitle, 5);
    head(ws, 9, 1, L.fiber);
    LAMBDAS.forEach((l, k) => head(ws, 9, 2 + k, L.nm(l)));
    x.fibers.forEach((f, r0) => {
        const r = 10 + r0;
        fiberCol(ws, r, f, x);
        LAMBDAS.forEach((l, k) => {
            const fr = f.at[l];
            const m = fr ? x.meta.get(fr.files[0]!) : undefined;
            num(ws, r, 2 + k, m?.orl || null, '0.00', !!fr && x.anomalies.has(anomalyKey(f.cableId, f.fiber, l, 0, 'orl')));
        });
    });
}

function outOfLimitsSheet(ws: Worksheet, x: Ctx) {
    const L = REPORT.outOfLimits;
    [4.7, 16.9, 30, 14, 14, 13, 9.7, 9.7, 9.7, 8.7].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    pageHead(ws, x, L.title, 11);
    const an = x.s.anomalies;
    const fib = (a: Anomaly) => (x.multiCable ? REPORT.tables.fiberOfCable(a.cableId, a.fiber) : a.fiber);
    const label = (a: Anomaly) => {
        const f = x.d.fibers.find((r) => r.cableId === a.cableId && r.fiber === a.fiber);

        return f?.events[a.index - 1] ? x.eventLabels[a.index - 1] ?? L.event(a.index) : L.event(a.index);
    };
    const tables: [string, string[], Anomaly[], (a: Anomaly) => (string | number | null)[]][] = [
        [L.splicesTitle, L.splicesColumns(x.bidirectional),
            an.filter((a) => a.type === 'connector' || a.type === 'splice'), (a) => [label(a), fib(a), a.value, a.lambda, a.threshold]],
        [L.slopesTitle, L.slopesColumns(x.bidirectional),
            an.filter((a) => a.type === 'slope'), (a) => [a.index, fib(a), a.value, a.lambda, a.threshold]],
        [L.reflectancesTitle, L.reflectancesColumns,
            an.filter((a) => a.type === 'connector_reflectance' || a.type === 'splice_reflectance'), (a) => [label(a), fib(a), a.value, a.lambda, a.threshold]],
        [L.totalLossTitle, L.totalLossColumns,
            an.filter((a) => a.type === 'total_loss'), (a) => ['', fib(a), a.value, a.lambda, a.threshold]],
        [L.constraintsTitle, L.constraintsColumns,
            an.filter((a) => a.type === 'constraint'), (a) => [label(a), fib(a), a.value, null, a.threshold]],
    ];

    if (!x.bidirectional) {
        tables.push([L.orlTitle, L.orlColumns, an.filter((a) => a.type === 'orl'), (a) => ['', fib(a), a.value, a.lambda, a.threshold]]);
    }

    // Means of the fibers (fiber n) and of the cable (fiber 0): connectors, splices, slopes.
    const means = an.filter((a) => a.type === 'connector_mean' || a.type === 'splice_mean' || a.type === 'slope_mean');

    if (means.length) {
        tables.push([L.meansTitle, L.meansColumns,
            means, (a) => [L.means[a.type] ?? a.type, a.fiber === 0 ? L.cable : fib(a), a.value, a.lambda, a.threshold]]);
    }

    let r = 9;

    for (const [title, cols, rows, cells] of tables) {
        ws.mergeCells(r, 2, r, 10);
        text(ws, `B${r}`, title, { bold: true, size: 12, align: 'center' });
        r += 2;
        cols.forEach((c, i) => head(ws, r, 2 + i, c, undefined, true));
        r++;

        if (!rows.length) {
            ws.mergeCells(r, 2, r, 6);
            const c = ws.getCell(r, 2);
            c.value = L.empty;
            c.alignment = { horizontal: 'center' };
            c.border = BOX;
            r++;
        }

        for (const a of rows) {
            cells(a).forEach((v, i) => {
                const c = ws.getCell(r, 2 + i);

                if (v !== null && v !== '') {
                    c.value = typeof v === 'number' ? r3(v) : v;
                }

                c.border = BOX;
                c.alignment = { horizontal: 'center' };

                if (i === 2) {
                    c.font = { bold: true, color: { argb: 'FFFF0000' } };
                }
            });
            r++;
        }

        r += 2;
    }
}
