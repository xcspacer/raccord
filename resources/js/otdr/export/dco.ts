// Optical control dossier (DCO) workbook: same sheets, columns and indicators as the DCO of the GDCO macro
// (measurement rules v24.30 §10.4), generated directly. The document identifies itself with the configured product.

import type { Borders, Cell, Fill, Workbook, Worksheet } from 'exceljs';
import { parseSorName } from '../engine/naming';
import { GDCO_THRESHOLDS, pboOf, type Anomaly, type AnomalyType, type FailedRepair, type Summary } from '../engine/rules';
import type { Dossier, EventKind, FiberResult, FileMeta } from '../engine/types';
import type { ExcelJS } from './excel';
import { spoolFlags } from './fiberCableTxt';
import { DCO, FILE_LOCALE, GDCO_CRITERION_LABELS } from './format';

export interface DcoInfo {
    /** Zone name. */
    zone: string;
    /** G2R number of the NRO. */
    g2r: string;
    /** PM reference (SRO-BPI-…). */
    pm: string;
    stit: string;
    technician: string;
    /** Transport: fibers connectorised (FTTH) or coiled (FTTE) at the PM. */
    transportType?: 'FTTH' | 'FTTE';
    /** Transport: total number of NRO-PM fibers (FTTH + FTTE). */
    totalFibersNroPm?: number;
    /** Distribution: Base ROP reference. */
    rop?: { pbo: number; fibers: number };
    nroPmLengthM?: number;
    nroPmAttenuation1310?: number;
    failedRepairs?: FailedRepair[];
    comment?: string;
    generatedAt: Date;
}

const C = {
    head: 'FFB4C6E7', title: 'FF1F4E79', titleInk: 'FFFFFFFF', mean: 'FFDCFFFF', grey: 'FFF0F0F0', input: 'FFFFFFDD', configHead: 'FFCDF5FF',
    green: 'FF00B050', red: 'FFFF0000', orange: 'FFFFC000', blue: 'FF0000FF', violet: 'FF7030A0', black: 'FF000000',
};
const thin = { style: 'thin' as const, color: { argb: 'FF7F7F7F' } };
const BOX: Partial<Borders> = { top: thin, left: thin, bottom: thin, right: thin };
const solid = (argb: string): Fill => ({ type: 'pattern', pattern: 'solid', fgColor: { argb } });
const r3 = (v: number) => Number(v.toFixed(3));
const r2 = (v: number) => Number(v.toFixed(2));
const cmp = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);
const LIGHT_COLOR = [C.green, C.orange, C.red] as const;

function put(ws: Worksheet, addr: string, value: Cell['value'], style: { bold?: boolean; fill?: string; color?: string; size?: number; align?: 'left' | 'center'; wrap?: boolean; box?: boolean; numFmt?: string } = {}) {
    const c = ws.getCell(addr);
    c.value = value;
    c.font = { name: 'Calibri', size: style.size ?? 11, bold: style.bold ?? false, color: style.color ? { argb: style.color } : undefined };

    if (style.fill) {
        c.fill = solid(style.fill);
    }

    c.alignment = { horizontal: style.align ?? 'center', vertical: 'middle', wrapText: style.wrap ?? false };

    if (style.box !== false) {
        c.border = BOX;
    }

    if (style.numFmt) {
        c.numFmt = style.numFmt;
    }

    return c;
}

function head(ws: Worksheet, row: number, col: number, value: Cell['value'], merge?: [number, number]) {
    if (merge) {
        ws.mergeCells(row, col, row + merge[0], col + merge[1]);
    }

    const c = ws.getCell(row, col);
    c.value = value;
    c.font = { bold: true };
    c.fill = solid(C.head);
    c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
    c.border = BOX;

    return c;
}

function val(ws: Worksheet, row: number, col: number, value: number | string | null | undefined, opts: { color?: string; bold?: boolean; fill?: string } = {}) {
    const c = ws.getCell(row, col);

    if (value !== null && value !== undefined && value !== '') {
        c.value = value;
    }

    c.alignment = { horizontal: 'center' };
    c.border = BOX;

    if (opts.color || opts.bold) {
        c.font = { color: opts.color ? { argb: opts.color } : undefined, bold: opts.bold };
    }

    if (opts.fill) {
        c.fill = solid(opts.fill);
    }

    return c;
}

function tick(ws: Worksheet, row: number, ok: boolean) {
    const c = ws.getCell(row, 1);
    c.value = ok ? DCO.ok : DCO.ko;
    c.font = { bold: true, color: { argb: ok ? C.green : C.red } };
    c.alignment = { horizontal: 'center' };
    c.border = BOX;
}

const numColor = (v: number | null | undefined, outOfLimits: boolean, neutral = false) =>
    neutral ? C.violet : outOfLimits ? C.red : v !== null && v !== undefined && v < 0 ? C.blue : undefined;

interface Ctx {
    d: Dossier;
    s: Summary;
    info: DcoInfo;
    bidirectional: boolean;
    rows: FiberResult[];
    nEvents: number;
    nSections: number;
    anomalies: Map<string, Anomaly>;
    meta: Map<string, FileMeta>;
}

/** Row tick (macro): ✖ as soon as |value| exceeds the threshold, apparent gains included; failed repair: neutralised. */
function outOfRange(x: Ctx, kind: EventKind, v: number, a: Anomaly | undefined): boolean {
    if (a?.neutralized) {
        return false;
    }

    const t = GDCO_THRESHOLDS[x.d.kind];

    return Math.abs(v) > (kind === 'connector' ? t.connector : t.splice);
}

const anomalyKey = (cable: string, fiber: number, lambda: number | null, index: number, type: AnomalyType) => `${cable}|${fiber}|${lambda}|${index}|${type}`;

export function dcoFileName(d: Dossier, s: Summary, info: DcoInfo): string {
    const t = info.generatedAt;
    const p = (n: number) => String(n).padStart(2, '0');
    const stamp = `${t.getFullYear()}${p(t.getMonth() + 1)}${p(t.getDate())}_${p(t.getHours())}${p(t.getMinutes())}`;
    // Rules §11: green and orange can be submitted ("OK").
    const status = s.light === 2 ? 'KO' : 'OK';
    const base = DCO.fileName(info.zone, info.pm, d.kind, stamp, status);

    return d.kind === 'transport' ? `${info.transportType ?? 'FTTH'}_${base}` : base;
}

export function buildDco(excel: ExcelJS, d: Dossier, s: Summary, info: DcoInfo): Workbook {
    const anomalies = new Map<string, Anomaly>();

    for (const a of s.anomalies) {
        anomalies.set(anomalyKey(a.cableId, a.fiber, a.lambda, a.index, a.type), a);
    }

    const rows = [...d.fibers].sort((a, b) => cmp(a.cableId, b.cableId) || a.fiber - b.fiber || a.lambda - b.lambda);
    const ctx: Ctx = {
        d, s, info, bidirectional: d.kind === 'transport', rows, anomalies,
        nEvents: Math.max(1, ...rows.map((r) => r.events.length)),
        nSections: Math.max(1, ...rows.map((r) => r.sections.length)),
        meta: new Map(d.files.map((m) => [m.name, m])),
    };
    const wb = new excel.Workbook();
    wb.creator = DCO.creator();
    wb.created = info.generatedAt;
    wb.title = DCO.title(d.kind, info.pm);
    summarySheet(wb.addWorksheet(DCO.sheets.summary), ctx);
    thresholdsSheet(wb.addWorksheet(DCO.sheets.thresholds), ctx);
    attenuationsSheet(wb.addWorksheet(DCO.sheets.attenuations), ctx);
    slopesSheet(wb.addWorksheet(DCO.sheets.slopes), ctx);
    reflectancesSheet(wb.addWorksheet(DCO.sheets.reflectances), ctx);
    lengthsSheet(wb.addWorksheet(DCO.sheets.lengths), ctx);
    positionsSheet(wb.addWorksheet(DCO.sheets.positions), ctx);
    constraintsSheet(wb.addWorksheet(DCO.sheets.constraints), ctx);
    configurationSheet(wb.addWorksheet(DCO.sheets.configuration), ctx);

    if (d.kind === 'distribution') {
        repairsSheet(wb.addWorksheet(DCO.sheets.repairs), ctx);
    }

    return wb;
}

// ------------------------------------------------------------------ 1_SYNTHESE

function summarySheet(ws: Worksheet, x: Ctx) {
    const { d, s, info } = x;
    const tr = d.kind === 'transport';
    const L = DCO.summary;
    ws.columns = Array.from({ length: 16 }, (_, i) => ({ width: i === 0 ? 4 : 11 }));
    ws.mergeCells('B1:K2');
    put(ws, 'B1', tr ? L.titleTransport : L.titleDistribution, { bold: true, size: 24, fill: C.title, color: C.titleInk });
    ws.getRow(1).height = 30;
    ws.getRow(2).height = 30;
    ws.mergeCells('B3:K3');
    put(ws, 'B3', L.generatedBy(), { size: 10, color: 'FF595959', box: false });
    ws.mergeCells('B4:K4');
    put(ws, 'B4', tr ? L.subtitleTransport : L.subtitleDistribution, { bold: true, fill: C.title, color: C.titleInk });

    const field = (row: number, label: string, value: string | number) => {
        ws.mergeCells(row, 2, row, 4);
        put(ws, `B${row}`, label, { bold: true });
        ws.mergeCells(row, 5, row, 7);
        put(ws, `E${row}`, value, { fill: C.input });
    };
    field(6, L.zone, info.zone);
    field(7, L.g2r, /^\d+$/.test(info.g2r) ? Number(info.g2r) : info.g2r);
    field(8, L.pm, info.pm);

    if (tr) {
        field(9, L.fiberType, info.transportType ?? 'FTTH');
    }

    field(10, L.stit, info.stit);
    field(11, tr ? L.technicianTransport : L.technicianDistribution, info.technician);

    // Traffic light (cell) + criteria
    ws.mergeCells('I6:K8');
    put(ws, 'I6', DCO.lightLabels[s.light], { bold: true, fill: LIGHT_COLOR[s.light], color: s.light === 1 ? C.black : C.titleInk, wrap: true });
    s.criteria.forEach((c, i) => {
        const row = 9 + i;
        ws.mergeCells(row, 9, row, 11);
        put(ws, `I${row}`, `${DCO.lightBullet} ${GDCO_CRITERION_LABELS[c.label.key] ?? c.label.key}`, { color: LIGHT_COLOR[c.light], align: 'left', size: 9, box: false });
    });

    ws.mergeCells('B16:K16');
    put(ws, 'B16', tr ? L.indicatorsTransport : L.indicatorsDistribution, { bold: true, size: 13 });
    let row = 18;

    for (const [label, value, light] of indicators(x)) {
        ws.mergeCells(row, 2, row, 8);
        put(ws, `B${row}`, label, { align: 'left', wrap: true });
        ws.mergeCells(row, 9, row, 11);
        const isDate = value instanceof Date;
        put(ws, `I${row}`, value as Cell['value'], {
            fill: C.input,
            bold: light !== undefined,
            color: light === undefined ? undefined : LIGHT_COLOR[light],
            numFmt: isDate ? 'dd/mm/yyyy hh:mm' : undefined,
        });
        row++;
    }

    ws.mergeCells(row + 1, 2, row + 3, 11);
    put(ws, `B${row + 1}`, L.comment(info.comment ?? ''), { bold: true, align: 'left', wrap: true });
}

type Indicator = [string, string | number | Date, (0 | 1 | 2)?];

function indicators(x: Ctx): Indicator[] {
    const { d, s, info } = x;
    const L = DCO.indicators;
    const reported = d.files.filter((f) => !isPairedFile(x, f));
    const dates = (reported.length ? reported : d.files).map((f) => f.date.getTime());
    const oldest = new Date(Math.min(...dates));
    const newest = new Date(Math.max(...dates));
    const an = s.anomalies.filter((a) => a.blocking);
    const count = (t: AnomalyType) => an.filter((a) => a.type === t).length;
    const lightOf = (n: number): 0 | 2 => (n > 0 ? 2 : 0);
    const pct = (n: number, tot: number) => (tot ? Math.round((n / tot) * 10000) / 100 : 0);
    const splices = x.rows.flatMap((r) => r.events.filter((e) => e.kind === 'splice' && e.value !== 0));
    const connectors = x.rows.flatMap((r) => r.events.filter((e) => e.kind === 'connector'));
    const f1310 = x.rows.filter((r) => r.lambda === 1310);
    // Excel has no time zone: the generation date is written in local time, the OTDR dates stay in UTC.
    const localNow = new Date(info.generatedAt.getTime() - info.generatedAt.getTimezoneOffset() * 60000);
    const common: Indicator[] = [
        [L.reportDate, localNow],
        [L.txtDate(), localNow],
        [L.oldestSor, oldest],
        [L.newestSor, newest],
    ];

    if (d.kind === 'transport') {
        const measured = new Set(x.rows.map((r) => r.fiber)).size;
        const lengths = f1310.map((r) => r.lengthM);
        const over = (t: number) => splices.filter((e) => e.value > t).length;

        return [
            ...common,
            [L.totalFibersNroPm, info.totalFibersNroPm ?? ''],
            [L.ropMeasuredTransport, measured],
            [L.ropMeasuredPercentTransport, info.totalFibersNroPm ? Math.round((measured / info.totalFibersNroPm) * 100) : ''],
            [L.maxLengthNroPm, lengths.length ? r3(Math.max(...lengths)) : ''],
            [L.minLengthNroPm, lengths.length ? r3(Math.min(...lengths)) : ''],
            [L.maxAttenuationNroPm, f1310.length ? r3(Math.max(...f1310.map((r) => r.totalLoss))) : ''],
            [L.connectorsOver06, pct(count('connector'), connectors.length), lightOf(count('connector'))],
            [L.connectorReflectance, pct(count('connector_reflectance'), connectors.length), lightOf(count('connector_reflectance'))],
            [L.constraints, count('constraint'), lightOf(count('constraint'))],
            [L.closures, Math.max(0, x.nEvents - 2)],
            [L.spliceReflectanceTransport, pct(count('splice_reflectance'), splices.length), lightOf(count('splice_reflectance'))],
            [L.splicesOver015, pct(over(0.15), splices.length), s.criteria[0]!.light],
            [L.splicesOver03, pct(over(0.3), splices.length)],
            [L.splicesOver04, pct(over(0.4), splices.length)],
        ];
    }

    const ind = s.indicators;
    const pboCovered = Number(ind.indicator_pbo_covered ?? 0);
    const ropMeasured = Number(ind.indicator_rop_measured ?? 0);
    const maxLen = Number(ind.indicator_max_length_pm_pbo_1310 ?? 0);
    const maxAtt = r2(Number(ind.indicator_max_attenuation_pm_pbo_1310 ?? 0));
    const totLen = info.nroPmLengthM === undefined ? '' : r2(info.nroPmLengthM + maxLen);
    const totAtt = info.nroPmAttenuation1310 === undefined ? '' : r2(info.nroPmAttenuation1310 + maxAtt);
    const p03 = Number(ind.indicator_unjustified_splices_over_03_percent ?? 0);
    const p05 = Number(ind.indicator_unjustified_splices_over_05_percent ?? 0);

    return [
        ...common,
        [L.pboInReference, info.rop?.pbo ?? ''],
        [L.pboCovered, pboCovered],
        [L.pboCoveredPercent, info.rop?.pbo ? Math.round((pboCovered / info.rop.pbo) * 100) : ''],
        [L.fibersInReference, info.rop?.fibers ?? ''],
        [L.ropMeasured, ropMeasured],
        [L.ropMeasuredPercent, info.rop?.fibers ? Math.round((ropMeasured / info.rop.fibers) * 100) : ''],
        [L.maxLengthPmPbo, maxLen],
        [L.maxAttenuationPmPbo, maxAtt],
        [L.declaredNroPmLength, info.nroPmLengthM ?? ''],
        [L.declaredNroPmAttenuation, info.nroPmAttenuation1310 ?? ''],
        [L.totalLength, totLen, s.criteria[5]!.light],
        [L.totalAttenuation, totAtt],
        [L.unjustifiedOver03, p03, s.criteria[0]!.light],
        [L.unjustifiedOver04, Number(ind.indicator_unjustified_splices_over_04_percent ?? 0)],
        [L.unjustifiedOver05, p05, p05 > 0 ? 2 : 0],
        [L.spliceReflectance, Number(ind.indicator_splice_reflectance_percent ?? 0), lightOf(count('splice_reflectance'))],
        [L.connectorsOver07, Number(ind.indicator_connectors_over_07_percent ?? 0), lightOf(count('connector'))],
        [L.connectorReflectance, Number(ind.indicator_connector_reflectance_over_55_percent ?? 0), lightOf(count('connector_reflectance'))],
        [L.constraints, count('constraint'), lightOf(count('constraint'))],
        [L.repairedSplices, Number(ind.indicator_failed_repair_splices ?? 0)],
        [L.repairedSplicesOver07, Number(ind.indicator_failed_repair_splices_over_07 ?? 0)],
    ];
}

// ------------------------------------------------------------------ 2_SEUILS

function thresholdsSheet(ws: Worksheet, x: Ctx) {
    const tr = x.d.kind === 'transport';
    const t = GDCO_THRESHOLDS[x.d.kind];
    const L = DCO.thresholds;
    ws.columns = [{ width: 50 }, { width: 36 }, { width: 26 }];
    ws.mergeCells('A1:C1');
    put(ws, 'A1', tr ? L.titleTransport : L.titleDistribution, { bold: true, size: 14, box: false });
    ws.getRow(1).height = 40;
    head(ws, 2, 1, '');
    head(ws, 2, 2, tr ? L.limitTransport : L.limitDistribution);
    head(ws, 2, 3, L.wavelength);
    const nf = (v: number, digits = 2) => v.toLocaleString(FILE_LOCALE, { minimumFractionDigits: digits });
    const rows: [string, string, string][] = [
        [L.pigtail, L.atMost(nf(t.connector, 1), 'dB'), L.bothWavelengths],
        [L.splice, L.atMost(nf(t.splice), 'dB'), L.bothWavelengths],
        [tr ? L.slopeTransport : L.slopeDistribution, L.atMost(nf(t.slope[1310]), 'dB/km'), L.at1310],
        ['', L.atMost(nf(t.slope[1550]), 'dB/km'), L.at1550],
        [L.connectorReflectance, L.atMost(String(t.reflectance), 'dB'), L.bothWavelengths],
        [L.spliceReflectance, L.none, L.bothWavelengths],
        [L.constraint, L.atMost(nf(t.constraint, 1), 'dB'), L.perEvent],
    ];
    rows.forEach((r, i) => r.forEach((v, j) => put(ws, `${'ABC'[j]}${3 + i}`, v, { wrap: true })));
    ws.mergeCells('A5:A6');
}

// ------------------------------------------------------------------ tables

/** "ID / Fiber / Lambda" header (rows 1-2) shared by the measurement sheets. */
function idHeader(ws: Worksheet, extra: string[] = [], lambdaCol = true) {
    const L = DCO.tables;
    head(ws, 1, 1, '', [1, 0]);
    head(ws, 1, 2, L.cableId, [1, 0]);
    head(ws, 1, 3, L.fiber, [1, 0]);
    let col = 4;

    if (lambdaCol) {
        head(ws, 1, col++, L.lambda, [1, 0]);
    }

    for (const e of extra) {
        head(ws, 1, col++, e, [1, 0]);
    }

    ws.getColumn(1).width = 6;
    ws.getColumn(2).width = 48;
    ws.getColumn(3).width = 10;

    return col;
}

function groupHeader(ws: Worksheet, col: number, n: number, labels: string[], first?: string[]) {
    for (let i = 0; i < n; i++) {
        const c0 = col + i * labels.length;
        head(ws, 1, c0, i + 1, labels.length > 1 ? [0, labels.length - 1] : undefined);
        labels.forEach((l, j) => head(ws, 2, c0 + j, i === 0 && first ? first[j]! : l));
    }
}

function idCells(ws: Worksheet, row: number, r: FiberResult, withLambda = true) {
    val(ws, row, 2, r.cableId);
    val(ws, row, 3, r.fiber);

    if (withLambda) {
        val(ws, row, 4, r.lambda);
    }
}

function meanRows(ws: Worksheet, row: number, x: Ctx, firstCol: number, n: number, width: number, offset: number, value: (r: FiberResult, i: number) => number | null | undefined) {
    for (const [k, lambda] of [[0, 1310], [1, 1550]] as const) {
        const rr = row + k;
        ws.mergeCells(rr, 2, rr, 3);
        val(ws, rr, 2, DCO.tables.mean, { fill: C.mean });
        val(ws, rr, 4, lambda, { fill: C.mean });
        val(ws, rr, 1, null, { fill: C.mean });

        for (let c = 5; c < firstCol; c++) {
            val(ws, rr, c, null, { fill: C.grey });
        }

        const rows = x.rows.filter((r) => r.lambda === lambda);

        for (let i = 0; i < n; i++) {
            for (let j = 0; j < width; j++) {
                val(ws, rr, firstCol + i * width + j, null, { fill: C.grey });
            }

            const vals = rows.map((r) => value(r, i)).filter((v): v is number => v !== null && v !== undefined);
            val(ws, rr, firstCol + i * width + offset, vals.length ? r3(vals.reduce((a, b) => a + b, 0) / vals.length) : null, { fill: C.mean });
        }
    }
}

function attenuationsSheet(ws: Worksheet, x: Ctx) {
    const k = x.nEvents;
    const L = DCO.tables;

    if (x.bidirectional) {
        const col = idHeader(ws, [L.meanConnectorsTransport, L.meanSplicesTransport]);
        head(ws, 1, col, L.totalLossTransport, [0, 2]);
        [L.oe, L.eo, L.mean].forEach((l, j) => head(ws, 2, col + j, l));
        const first = col + 3;
        const connectorLabels = [L.oeConnector, L.eoConnector, L.meanConnector];
        groupHeader(ws, first, k, [L.oe, L.eo, L.mean], connectorLabels);

        if (k > 1) {
            connectorLabels.forEach((l, j) => head(ws, 2, first + (k - 1) * 3 + j, l));
        }

        meanRows(ws, 3, x, first, k, 3, 2, (r, i) => r.events[i]?.value);
        x.rows.forEach((r, n) => {
            const row = 5 + n;
            idCells(ws, row, r);
            const conns = r.events.filter((e) => e.kind === 'connector').map((e) => e.value);
            const spl = r.events.filter((e) => e.kind === 'splice').map((e) => e.value);
            val(ws, row, 5, conns.length ? r3(conns.reduce((a, b) => a + b, 0) / conns.length) : null);
            val(ws, row, 6, spl.length ? r3(spl.reduce((a, b) => a + b, 0) / spl.length) : null);
            val(ws, row, col, r.totalLossOe === null ? null : r3(r.totalLossOe));
            val(ws, row, col + 1, r.totalLossEo === null ? null : r3(r.totalLossEo));
            val(ws, row, col + 2, r.totalLoss);
            let ok = true;
            r.events.forEach((e, i) => {
                const a = x.anomalies.get(anomalyKey(r.cableId, r.fiber, r.lambda, e.index, e.kind));

                if (outOfRange(x, e.kind, e.value, a)) {
                    ok = false;
                }

                const c0 = first + i * 3;
                val(ws, row, c0, e.oe, { color: numColor(e.oe, false) });
                val(ws, row, c0 + 1, e.eo, { color: numColor(e.eo, false) });
                val(ws, row, c0 + 2, e.value, { color: numColor(e.value, !!a?.blocking), bold: !!a?.blocking });
            });
            tick(ws, row, ok);
        });

        for (let c = 4; c < first + 3 * k; c++) {
            ws.getColumn(c).width = 12;
        }

        return;
    }

    const col = idHeader(ws, [L.meanConnectorsDistribution, L.meanSplicesDistribution]);
    head(ws, 1, col, L.totalLossDistribution);
    head(ws, 2, col, L.oe);
    const first = col + 1;
    groupHeader(ws, first, k, [L.oe], [L.oeConnector]);
    meanRows(ws, 3, x, first, k, 1, 0, (r, i) => r.events[i]?.value);
    x.rows.forEach((r, n) => {
        const row = 5 + n;
        idCells(ws, row, r);
        const spl = r.events.filter((e) => e.kind === 'splice').map((e) => e.value);
        val(ws, row, 5, r.events[0]?.value);
        val(ws, row, 6, spl.length ? r3(spl.reduce((a, b) => a + b, 0) / spl.length) : null);
        val(ws, row, col, r.totalLoss || null);
        let ok = true;
        r.events.forEach((e, i) => {
            const a = x.anomalies.get(anomalyKey(r.cableId, r.fiber, r.lambda, e.index, e.kind));

            if (outOfRange(x, e.kind, e.value, a)) {
                ok = false;
            }

            val(ws, row, first + i, e.value, { color: numColor(e.value, !!a?.blocking, !!a?.neutralized), bold: !!a && (a.blocking || !!a.neutralized) });
        });
        tick(ws, row, ok);
    });

    for (let c = 4; c < first + k; c++) {
        ws.getColumn(c).width = 12;
    }
}

function slopesSheet(ws: Worksheet, x: Ctx) {
    const L = DCO.tables;
    const ns = x.nSections;
    const col = idHeader(ws, [L.mean]);
    const width = x.bidirectional ? 3 : 1;
    groupHeader(ws, col, ns, x.bidirectional ? [L.oe, L.eo, L.mean] : [L.oe]);
    meanRows(ws, 3, x, col, ns, width, width - 1, (r, j) => r.sections[j]?.slope);
    x.rows.forEach((r, n) => {
        const row = 5 + n;
        idCells(ws, row, r);
        const sl = r.sections.map((s) => s.slope).filter((v): v is number => v !== null);
        val(ws, row, 5, sl.length ? r3(sl.reduce((a, b) => a + b, 0) / sl.length) : null);
        r.sections.forEach((s, j) => {
            const c0 = col + j * width;
            const over = x.anomalies.has(anomalyKey(r.cableId, r.fiber, r.lambda, s.index, 'slope'));

            if (x.bidirectional) {
                val(ws, row, c0, s.slopeOe);
                val(ws, row, c0 + 1, s.slopeEo);
                val(ws, row, c0 + 2, s.slope, { color: over ? C.red : undefined, bold: over });
            } else {
                val(ws, row, c0, s.slope);
            }
        });
        tick(ws, row, !r.sections.some((s) => x.anomalies.has(anomalyKey(r.cableId, r.fiber, r.lambda, s.index, 'slope'))));
    });

    for (let c = 4; c < col + ns * width; c++) {
        ws.getColumn(c).width = 12;
    }
}

function reflectancesSheet(ws: Worksheet, x: Ctx) {
    const L = DCO.tables;
    const k = x.nEvents;
    let col = idHeader(ws);

    if (!x.bidirectional) {
        head(ws, 1, col, L.orl);
        head(ws, 2, col, L.oe);
        col++;
    }

    const width = x.bidirectional ? 2 : 1;
    groupHeader(ws, col, k, x.bidirectional ? [L.oe, L.eo] : [L.oe], x.bidirectional ? [L.oeConnector, L.eoConnector] : [L.oeConnector]);
    x.rows.forEach((r, n) => {
        const row = 3 + n;
        idCells(ws, row, r);

        if (!x.bidirectional) {
            const m = x.meta.get(r.files[0]!);
            val(ws, row, 5, m && m.orl ? r2(m.orl) : null);
        }

        let ok = true;
        r.events.forEach((e, i) => {
            const t: AnomalyType = e.kind === 'connector' ? 'connector_reflectance' : 'splice_reflectance';
            const bad = x.anomalies.has(anomalyKey(r.cableId, r.fiber, r.lambda, e.index, t));

            if (bad) {
                ok = false;
            }

            val(ws, row, col + i * width, e.reflOe, { color: bad ? C.red : undefined, bold: bad });

            if (x.bidirectional) {
                val(ws, row, col + i * width + 1, e.reflEo, { color: bad ? C.red : undefined, bold: bad });
            }
        });
        tick(ws, row, ok);
    });

    for (let c = 4; c < col + k * width; c++) {
        ws.getColumn(c).width = 12;
    }
}

function lengthsSheet(ws: Worksheet, x: Ctx) {
    const L = DCO.tables;
    const ns = x.nSections;
    const col = idHeader(ws);
    const width = x.bidirectional ? 3 : 1;
    groupHeader(ws, col, ns, x.bidirectional ? [L.oe, L.eo, L.mean] : [L.oe]);
    x.rows.forEach((r, n) => {
        const row = 3 + n;
        idCells(ws, row, r);
        r.sections.forEach((s, j) => {
            const c0 = col + j * width;

            if (x.bidirectional) {
                val(ws, row, c0, s.lengthOe === null ? null : r3(s.lengthOe));
                val(ws, row, c0 + 1, s.lengthEo === null ? null : r3(s.lengthEo));
                val(ws, row, c0 + 2, r3(s.lengthM));
            } else {
                val(ws, row, c0, r3(s.lengthM));
            }
        });
    });

    for (let c = 4; c < col + ns * width; c++) {
        ws.getColumn(c).width = 12;
    }
}

function positionsSheet(ws: Worksheet, x: Ctx) {
    const L = DCO.tables;
    const n = x.bidirectional ? x.nEvents : x.nSections + 1;
    head(ws, 1, 1, '');
    head(ws, 1, 2, L.cableId);
    head(ws, 1, 3, L.fiber);
    let col = 4;

    if (!x.bidirectional) {
        head(ws, 1, col++, L.colour);
    }

    head(ws, 1, col++, L.lambda);

    for (let i = 0; i < n; i++) {
        head(ws, 1, col + i, i + 1);
    }

    ws.getColumn(2).width = 48;
    x.rows.forEach((r, k) => {
        const row = 2 + k;
        val(ws, row, 2, r.cableId);
        val(ws, row, 3, r.fiber);
        val(ws, row, col - 1, r.lambda);
        let acc = 0;
        val(ws, row, col, 0);
        r.sections.forEach((s, j) => {
            acc += s.lengthM;
            val(ws, row, col + j + 1, r3(acc));
        });
    });
}

function constraintsSheet(ws: Worksheet, x: Ctx) {
    const L = DCO.tables;
    const k = x.nEvents;
    head(ws, 1, 1, '', [1, 0]);
    head(ws, 1, 2, L.cableId, [1, 0]);
    head(ws, 1, 3, L.fiber, [1, 0]);
    groupHeader(ws, 4, k, [L.nm1310, L.nm1550, L.difference]);
    ws.getColumn(2).width = 48;
    const by = new Map<string, { r1310?: FiberResult; r1550?: FiberResult; cable: string; fiber: number }>();

    for (const r of x.rows) {
        const rowKey = `${r.cableId}|${r.fiber}`;
        const e = by.get(rowKey) ?? { cable: r.cableId, fiber: r.fiber };

        if (r.lambda === 1310) {
            e.r1310 = r;
        } else {
            e.r1550 = r;
        }

        by.set(rowKey, e);
    }

    [...by.values()].forEach((f, n) => {
        const row = 3 + n;
        val(ws, row, 2, f.cable);
        val(ws, row, 3, f.fiber);
        let ok = true;

        for (let i = 0; i < k; i++) {
            const a = f.r1310?.events[i];
            const b = f.r1550?.events[i];

            if (!a && !b) {
                continue;
            }

            const bad = x.anomalies.has(anomalyKey(f.cable, f.fiber, null, i + 1, 'constraint'));

            if (bad) {
                ok = false;
            }

            val(ws, row, 4 + i * 3, a?.value);
            val(ws, row, 5 + i * 3, b?.value);
            val(ws, row, 6 + i * 3, a && b ? r3(b.value - a.value) : null, { color: bad ? C.red : undefined, bold: bad });
        }

        tick(ws, row, ok);
    });
}

/**
 * Loop: like FiberCable (and the macro after it), the pair of files is reported on the first fiber of the pair; the
 * file of the second fiber is not listed in the measurement information (configuration sheet, .sor dates).
 */
function isPairedFile(x: Ctx, m: FileMeta): boolean {
    const p = parseSorName(m.name);

    return x.d.mode === 'loop' && !!p && p.fiber % 2 === 0
        && x.rows.some((r) => r.cableId === p.cableId && r.fiber === p.fiber - 1 && Math.round(m.lambda) === r.lambda);
}

function configurationSheet(ws: Worksheet, x: Ctx) {
    const tr = x.bidirectional;
    const L = DCO.configuration;
    ws.mergeCells(1, 1, 1, tr ? 16 : 15);
    put(ws, 'A1', L.title, { bold: true });
    const cols = tr ? L.columnsTransport : L.columnsDistribution;
    const firstParam = tr ? 5 : 4;
    const spool = cols.length - 2;
    const h = (row: number, col: number, v: string, merge?: [number, number]) => {
        head(ws, row, col, v, merge).fill = solid(C.configHead);
    };

    for (let i = 1; i < firstParam; i++) {
        h(2, i, cols[i - 1]!, [1, 0]);
    }

    h(2, firstParam, L.parameters, [0, spool - 1 - firstParam]);
    h(2, spool, L.spool, [0, cols.length - spool]);

    for (let i = firstParam; i <= cols.length; i++) {
        h(3, i, cols[i - 1]!);
    }

    ws.getColumn(1).width = 62;

    for (let c = 2; c <= cols.length; c++) {
        ws.getColumn(c).width = 16;
    }

    const files = [...x.d.files].sort((a, b) => cmp(a.name, b.name));
    const cableOf = new Map<string, string>();

    for (const r of x.rows) {
        for (const f of r.files) {
            if (!cableOf.has(f)) {
                cableOf.set(f, r.cableId);
            }
        }
    }

    files.forEach((m, n) => {
        const row = 4 + n;

        // the row of the second fiber of a loop pair only keeps its cable, a null range and the wavelength
        if (isPairedFile(x, m)) {
            val(ws, row, 4, cableOf.get(m.name) ?? '');
            val(ws, row, 12, 0);
            val(ws, row, 13, Math.round(m.lambda));

            return;
        }

        const day = new Date(Date.UTC(m.date.getUTCFullYear(), m.date.getUTCMonth(), m.date.getUTCDate()));
        // Excel time value (day 0 + time of the measurement)
        const time = new Date(Date.UTC(1899, 11, 30, m.date.getUTCHours(), m.date.getUTCMinutes()));
        const values: (string | number | Date)[] = [
            m.name, day, time,
            ...(tr ? [cableOf.get(m.name) ?? ''] : []),
            m.otdr, /^\d+$/.test(m.otdrSerial) ? Number(m.otdrSerial) : m.otdrSerial, m.module,
            /^\d+$/.test(m.moduleSerial) ? Number(m.moduleSerial) : m.moduleSerial,
            m.ior, m.pulseNs, m.averagingTimeS, Math.round(m.rangeKm * 1000), Math.round(m.lambda),
            ...spoolFlags(x.d.acquisition, tr),
        ];
        values.forEach((v, i) => {
            const c = val(ws, row, i + 1, v as string | number);

            if (v instanceof Date) {
                c.value = v;
                c.numFmt = i === 1 ? 'dd/mm/yyyy' : 'h:mm';
            }
        });
    });
}

function repairsSheet(ws: Worksheet, x: Ctx) {
    DCO.repairs.forEach((c, i) => head(ws, 1, i + 1, c));
    [18, 12, 18, 48, 8, 14, 16, 18, 16, 10].forEach((w, i) => (ws.getColumn(i + 1).width = w));
    if (!x.info.failedRepairs?.length) {
        ws.getCell(6, 4).value = DCO.repairsMissing;

        return;
    }

    x.info.failedRepairs.forEach((p, n) => {
        const row = 2 + n;
        const values: (string | number | Date | null)[] = [x.info.pm, '', pboOf(p.cableId), p.cableId, p.fiber, p.event, p.startValue ?? null, p.lastValue, p.date ?? null, p.lambda];
        values.forEach((v, i) => {
            const c = val(ws, row, i + 1, v as string | number | null);

            if (v instanceof Date) {
                c.value = v;
                c.numFmt = 'dd/mm/yyyy';
            }
        });
    });
}
