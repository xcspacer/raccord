// Ready-to-download deliverables: each generator returns the bytes, the file name and the media type; the UI only
// triggers the download. Workbooks load ExcelJS on demand.

import type { InputFile } from '../engine/dossier';
import type { Profile } from '../engine/profile';
import type { FailedRepair, Summary } from '../engine/rules';
import type { Dossier, EventOverride } from '../engine/types';
import { buildGdcoArchive, buildStandardArchive, gdcoArchiveFileName, safeFileName, type NamedBytes } from './archives';
import { buildCorrections, correctionsLog, hasDetected } from './corrections';
import { buildDco, type DcoInfo, dcoFileName } from './dco';
import { loadExcelJS, workbookBytes, XLSX_MIME } from './excel';
import { buildFailedRepairsWorkbook, failedRepairsFileName } from './failedRepairs';
import { buildFiberCableTxt, encodeUtf16le, txtFileName } from './fiberCableTxt';
import { ARCHIVE, REPORT } from './format';
import { buildMeasurementReport, type CableInfo, DEFAULT_REPORT_OPTIONS, measurementReportFileName, type ReportOptions } from './measurementReport';

export interface FileOutput {
    name: string;
    data: Uint8Array;
    mime: string;
}

const TXT_MIME = 'text/plain;charset=utf-16le';
const ZIP_MIME = 'application/zip';
const JSON_MIME = 'application/json';
const LOG_MIME = 'text/plain;charset=utf-8';

const bytesOf = (b: ArrayBuffer | Uint8Array) => (b instanceof Uint8Array ? b : new Uint8Array(b));
const usedSor = (d: Dossier, inputs: InputFile[]): NamedBytes[] => {
    const used = new Set(d.files.map((m) => m.name));

    return inputs.filter((x) => used.has(x.name)).map((x) => ({ name: x.name, data: bytesOf(x.bytes) }));
};

// ------------------------------------------------------------------ suggestions for the deliverable fields

/** PM reference deduced from the dossier (distribution: origin SRO-BPI; transport: extremity). */
export function suggestPm(d: Dossier): string {
    if (d.kind === 'distribution') {
        return /^SRO-BPI-/i.test(d.origin) ? d.origin : '';
    }

    return /^\d+$/.test(d.termination) ? `SRO-BPI-${d.termination}` : d.termination;
}

/** Technician read in the files (first operator found). */
export function suggestTechnician(d: Dossier): string {
    return d.files.find((m) => m.operator)?.operator ?? '';
}

/** Default cable name: shared identifier of the files, otherwise common prefix. */
export function suggestCableName(d: Dossier): string {
    const ids = [...new Set(d.fibers.map((f) => f.cableId))];

    if (ids.length === 1) {
        return ids[0]!;
    }

    let p = ids[0] ?? '';

    for (const id of ids) {
        while (!id.startsWith(p)) {
            p = p.slice(0, -1);
        }
    }

    return p.replace(/[_\-\s]+$/, '') || d.origin;
}

/** Default extremity of the standard measurement dossier. */
export function suggestExtremity(d: Dossier): string {
    return d.termination || d.files[0]?.termination || '';
}

/** PM reference of a failed repair report (from its rows, otherwise from the anomalies). */
export function failedRepairsPm(failedRepairs: FailedRepair[], s: Summary): string {
    return /^(SRO-BPI-\d+)/i.exec(failedRepairs[0]?.cableId ?? s.anomalies[0]?.cableId ?? '')?.[1] ?? ARCHIVE.defaultPm;
}

// ------------------------------------------------------------------ single files

/** Operator (GDCO) treatment `.txt`: thresholds of the operator reference deliverable. */
export function gdcoTxtDeliverable(d: Dossier, pm: string, transportType: 'FTTH' | 'FTTE' = 'FTTH'): FileOutput {
    return { name: txtFileName(d, pm || ARCHIVE.defaultPm, transportType), data: encodeUtf16le(buildFiberCableTxt(d)), mime: TXT_MIME };
}

/** Standard treatment `.txt`: thresholds of the chosen profile. */
export function standardTxtDeliverable(d: Dossier, name: string, profile: Profile): FileOutput {
    return { name: ARCHIVE.standardTxtName(safeFileName(name || REPORT.defaultName)), data: encodeUtf16le(buildFiberCableTxt(d, profile)), mime: TXT_MIME };
}

export async function dcoDeliverable(d: Dossier, s: Summary, info: DcoInfo): Promise<FileOutput> {
    const excel = await loadExcelJS();

    return { name: dcoFileName(d, s, info), data: await workbookBytes(buildDco(excel, d, s, info)), mime: XLSX_MIME };
}

export async function measurementReportDeliverable(d: Dossier, s: Summary, profile: Profile, info: CableInfo, options: ReportOptions = DEFAULT_REPORT_OPTIONS, generatedAt = new Date()): Promise<FileOutput> {
    const excel = await loadExcelJS();

    return { name: measurementReportFileName(info, s), data: await workbookBytes(buildMeasurementReport(excel, d, s, profile, info, options, generatedAt)), mime: XLSX_MIME };
}

export async function failedRepairsDeliverable(failedRepairs: FailedRepair[], pm: string): Promise<FileOutput> {
    const excel = await loadExcelJS();

    return { name: failedRepairsFileName(pm), data: await workbookBytes(buildFailedRepairsWorkbook(excel, failedRepairs, pm)), mime: XLSX_MIME };
}

/** Corrections file exported from the screen (to import again on another device). */
export function correctionsDeliverable(d: Dossier, overrides: EventOverride[], failedRepairs: FailedRepair[], now = new Date()): FileOutput {
    const reference = d.kind === 'distribution' ? d.origin : d.termination;

    return {
        name: ARCHIVE.correctionsExport(safeFileName(reference)),
        data: new TextEncoder().encode(JSON.stringify(buildCorrections(d, overrides, failedRepairs, now), null, 2)),
        mime: JSON_MIME,
    };
}

function correctionsPair(d: Dossier, overrides: EventOverride[], failedRepairs: FailedRepair[], now: Date) {
    return {
        json: new TextEncoder().encode(JSON.stringify(buildCorrections(d, overrides, failedRepairs, now), null, 2)),
        log: new TextEncoder().encode(correctionsLog(d, overrides, failedRepairs, now)),
    };
}

/** Readable corrections log alone. */
export function correctionsLogDeliverable(d: Dossier, overrides: EventOverride[], failedRepairs: FailedRepair[], now = new Date()): FileOutput {
    return { name: ARCHIVE.correctionsLog(), data: new TextEncoder().encode(correctionsLog(d, overrides, failedRepairs, now)), mime: LOG_MIME };
}

// ------------------------------------------------------------------ archives

export interface GdcoArchiveRequest {
    dossier: Dossier;
    summary: Summary;
    info: DcoInfo;
    inputs: InputFile[];
    overrides: EventOverride[];
    failedRepairs: FailedRepair[];
    /** Base ROP extract file, copied as is (distribution). */
    rop?: NamedBytes;
    /** .sor files measured before the repairs (proof of the failed repair report). */
    preRepairSor?: NamedBytes[];
}

/** Complete operator deliverable (§11.4): DCO, .txt, original .sor, [ROP extract], [failed repairs], [corrections]. */
export async function gdcoArchiveDeliverable(r: GdcoArchiveRequest): Promise<FileOutput> {
    const excel = await loadExcelJS();
    const { dossier: d, info } = r;
    const transportType = info.transportType ?? 'FTTH';
    const dco = await workbookBytes(buildDco(excel, d, r.summary, info));
    const repairs = r.failedRepairs.length ? await workbookBytes(buildFailedRepairsWorkbook(excel, r.failedRepairs, info.pm)) : undefined;
    const now = new Date();
    const data = buildGdcoArchive({
        dossier: d, pm: info.pm, transportType,
        dco: { name: dcoFileName(d, r.summary, info), data: dco },
        txt: { name: txtFileName(d, info.pm, transportType), data: encodeUtf16le(buildFiberCableTxt(d)) },
        sor: usedSor(d, r.inputs),
        rop: r.rop,
        failedRepairs: repairs ? { name: failedRepairsFileName(info.pm), data: repairs } : undefined,
        preRepairSor: r.preRepairSor ?? [],
        corrections: r.overrides.length || r.failedRepairs.length || hasDetected(d) ? correctionsPair(d, r.overrides, r.failedRepairs, now) : undefined,
    });

    return { name: gdcoArchiveFileName({ dossier: d, pm: info.pm, transportType }), data, mime: ZIP_MIME };
}

export interface StandardArchiveRequest {
    dossier: Dossier;
    summary: Summary;
    profile: Profile;
    info: CableInfo;
    options?: ReportOptions;
    inputs: InputFile[];
    overrides: EventOverride[];
}

/** Complete standard deliverable: measurement dossier, .txt, original .sor, [corrections]. */
export async function standardArchiveDeliverable(r: StandardArchiveRequest): Promise<FileOutput> {
    const report = await measurementReportDeliverable(r.dossier, r.summary, r.profile, r.info, r.options);
    const txt = standardTxtDeliverable(r.dossier, r.info.name, r.profile);
    const data = buildStandardArchive({
        name: r.info.name,
        report,
        txt,
        sor: usedSor(r.dossier, r.inputs),
        corrections: r.overrides.length || hasDetected(r.dossier) ? correctionsPair(r.dossier, r.overrides, [], new Date()) : undefined,
    });

    return { name: ARCHIVE.standardFileName(safeFileName(r.info.name || REPORT.defaultName)), data, mime: ZIP_MIME };
}
