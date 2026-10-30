// Screen-side workflow helpers: how the screen drives the engine (treatments, corrections, trace view, failed repairs)
// and the translatable messages of the results. Pure functions, no DOM.

import { DETECTED_EVENT_BASE, isDetected } from './engine/analysis';
import { analyze, applyOverrides, type InputFile } from './engine/dossier';
import { message, type Message, OtdrError } from './engine/messages';
import type { AcquisitionConfig } from './engine/profile';
import type { Anomaly, FailedRepair, Light, Summary } from './engine/rules';
import { isEndEvent, readSor, type SorEvent } from './engine/sor';
import { addEvent } from './engine/trace';
import { ADDED_EVENT_BASE, type Dossier, type DossierKind, type EventOverride } from './engine/types';

/** Standard treatment (FiberCable, profile thresholds) or operator treatment (GDCO macro). */
export type Treatment = 'standard' | 'gdco';

/** Dossier kind choice of the operator treatment. */
export type KindChoice = 'auto' | DossierKind;

// ------------------------------------------------------------------ reading

/** Reads the chosen files (non .sor files are skipped); `onProgress` gets the number of files handled. */
export async function readInputFiles(files: Iterable<File>, onProgress?: (done: number) => void): Promise<InputFile[]> {
    const out: InputFile[] = [];
    let done = 0;

    for (const f of files) {
        if (/\.sor$/i.test(f.name)) {
            out.push({ name: f.name, bytes: new Uint8Array(await f.arrayBuffer()) });
        }

        onProgress?.(++done);
    }

    return out;
}

// ------------------------------------------------------------------ treatments

/** Operator treatment: acquisition always deduced from the files (kind can be forced), complete analysis settings kept. */
export function analyzeGdco(inputs: InputFile[], kind: KindChoice, overrides: EventOverride[], acq: Pick<AcquisitionConfig, 'completeAnalysis' | 'detection'>): Dossier {
    return analyze(inputs, kind === 'auto' ? undefined : kind, overrides, { completeAnalysis: acq.completeAnalysis, detection: acq.detection });
}

/** Standard treatment: OTDR settings of the screen (automatic or manual). */
export function analyzeStandard(inputs: InputFile[], overrides: EventOverride[], acq: AcquisitionConfig): Dossier {
    return analyze(inputs, undefined, overrides, acq);
}

/**
 * Operator dossier for the optional DCO export of the standard treatment: same setting as the operator control
 * (automatic, complete total loss). The standard dossier is reused when it already has that setting.
 */
export function gdcoDossierForStandard(inputs: InputFile[], overrides: EventOverride[], acq: AcquisitionConfig, standardDossier: Dossier): Dossier {
    if (acq.auto && acq.totalLossInput && acq.totalLossOutput) {
        return standardDossier;
    }

    return analyze(inputs, undefined, overrides, { completeAnalysis: acq.completeAnalysis, detection: acq.detection });
}

/** Summary light texts of the screen. */
export function lightMessages(treatment: Treatment, light: Light): { text: Message; hint: Message | null } {
    if (treatment === 'gdco') {
        return { text: message(`light_gdco_${light}`), hint: message(`light_gdco_hint_${light}`) };
    }

    return { text: message(`light_standard_${light}`), hint: light === 1 ? null : message(`light_standard_hint_${light}`) };
}

/** Label of an anomaly type. */
export const anomalyTypeMessage = (a: Pick<Anomaly, 'type'>): Message => message(`anomaly_type_${a.type}`);

// ------------------------------------------------------------------ corrections

/** One correction per (file, event): replaces the previous one, or removes it (back to the automatic value). */
export function upsertOverride(list: EventOverride[], o: EventOverride, remove = false): EventOverride[] {
    return [...list.filter((x) => !(x.file === o.file && x.event === o.event)), ...(remove ? [] : [o])];
}

/**
 * Manual event added at `km` on a trace: computed as the engine will do it. Returns the correction to keep, or the
 * reason of the refusal.
 */
export function tryAddEvent(file: InputFile, overrides: EventOverride[], km: number, kind: DossierKind): { override: EventOverride; error?: undefined } | { override?: undefined; error: Message } {
    const mine = overrides.filter((o) => o.file === file.name);
    const used = mine.filter((o) => o.action === 'add').map((o) => o.event);
    const o: EventOverride = {
        file: file.name, event: Math.max(ADDED_EVENT_BASE - 1, ...used) + 1, action: 'add', km,
        kind: kind === 'distribution' ? 'splice' : undefined,
    };
    const test = readSor(file.name, file.bytes);
    const warnings: Message[] = [];
    applyOverrides(test, mine, warnings);

    try {
        test.events = [...test.events, addEvent(test, km, o.event)];
    } catch (error) {
        return { error: error instanceof OtdrError ? error.detail : message('add_event_refused_section_too_short', { distance: Math.round(km * 1000) }) };
    }

    if (warnings.length) {
        return { error: warnings[warnings.length - 1]! };
    }

    return { override: o };
}

export interface TraceEventRow {
    event: SorEvent;
    added: boolean;
    detected: boolean;
    ignored: boolean;
    end: boolean;
    override?: EventOverride;
    /** Marker shown on the trace: OTDR number, added (A1…), detected (D1…) or fiber end. */
    marker: Message;
}

const isManual = (e: SorEvent) => e.num >= ADDED_EVENT_BASE && e.num < DETECTED_EVENT_BASE;

/**
 * Events of a trace for the trace view: the engine view when the dossier holds it (OTDR, detected and added events,
 * plus those ignored by the operator), otherwise the raw file with its corrections.
 */
export function traceEvents(file: InputFile, overrides: EventOverride[], analyzed?: Dossier['traces']): TraceEventRow[] {
    const mine = overrides.filter((o) => o.file === file.name);
    const byNum = new Map(mine.map((o) => [o.event, o]));
    const done = analyzed?.[file.name];
    let rows: Omit<TraceEventRow, 'marker'>[];

    if (done) {
        const live = done.events.map((e) => ({ event: e, added: isManual(e), detected: isDetected(e), ignored: false, end: isEndEvent(e), override: byNum.get(e.num) }));
        const off = done.ignored.map((e) => ({ event: e, added: false, detected: isDetected(e), ignored: true, end: false, override: byNum.get(e.num) }));
        rows = [...live, ...off];
    } else {
        const sor = readSor(file.name, file.bytes);
        const view = readSor(file.name, file.bytes);
        applyOverrides(view, mine);
        const base = sor.events.map((e) => ({ event: e, added: false, detected: false, ignored: byNum.get(e.num)?.action === 'ignore', end: isEndEvent(e), override: byNum.get(e.num) }));
        const added = view.events.filter(isManual).map((e) => ({ event: e, added: true, detected: false, ignored: false, end: false, override: byNum.get(e.num) }));
        rows = [...base, ...added];
    }

    let d = 0;

    return rows.sort((a, b) => a.event.km - b.event.km).map((r) => ({
        ...r,
        marker: r.end ? message('trace_marker_end')
            : r.detected ? message('trace_marker_detected', { number: ++d })
                : r.added ? message('trace_marker_added', { number: r.event.num - ADDED_EVENT_BASE + 1 })
                    : message('trace_marker_otdr', { number: r.event.num }),
    }));
}

// ------------------------------------------------------------------ failed repair reports

const repairKey = (p: { cableId: string; fiber: number; event: number; lambda: number }) => `${p.cableId}|${p.fiber}|${p.event}|${p.lambda}`;

/** A distribution splice 0.30 < v ≤ 0.70 dB not yet in the report can be added to it (after two repairs). */
export function canAddFailedRepair(a: Anomaly, d: Dossier, failedRepairs: FailedRepair[]): boolean {
    return d.kind === 'distribution' && a.type === 'splice' && a.lambda !== null && a.value > 0.3 && a.value <= 0.7
        && !failedRepairs.some((p) => repairKey(p) === repairKey({ cableId: a.cableId, fiber: a.fiber, event: a.index, lambda: a.lambda! }));
}

/** Adds (or replaces) the report row of an out of limits splice. */
export function addFailedRepair(list: FailedRepair[], a: Anomaly, now = new Date()): FailedRepair[] {
    if (a.lambda === null) {
        return list;
    }

    const row: FailedRepair = { cableId: a.cableId, fiber: a.fiber, event: a.index, lambda: a.lambda, lastValue: a.value, startValue: a.value, date: now };

    return [...list.filter((p) => repairKey(p) !== repairKey(row)), row];
}

/** Merges imported rows into the report (same splice: the imported row wins). */
export function mergeFailedRepairs(list: FailedRepair[], imported: FailedRepair[]): FailedRepair[] {
    const m = new Map(list.map((p) => [repairKey(p), p]));

    for (const p of imported) {
        m.set(repairKey(p), p);
    }

    return [...m.values()];
}

export const removeFailedRepair = (list: FailedRepair[], p: FailedRepair): FailedRepair[] => list.filter((x) => repairKey(x) !== repairKey(p));

/** State of a report row against the measurements (rules §9.2.2: neutralised when 0.30 < v ≤ 0.70 and same value). */
export function failedRepairStatus(p: FailedRepair, s: Summary): { ok: boolean; message: Message } {
    const a = s.anomalies.find((x) => x.type === 'splice' && x.cableId === p.cableId && x.fiber === p.fiber && x.index === p.event && x.lambda === p.lambda);

    if (!a) {
        return { ok: false, message: message('failed_repair_status_not_found') };
    }

    if (a.neutralized) {
        return { ok: true, message: message('failed_repair_status_neutralized') };
    }

    if (a.value > 0.7) {
        return { ok: false, message: message('failed_repair_status_over_limit', { value: a.value }) };
    }

    return { ok: false, message: message('failed_repair_status_value_mismatch', { value: a.value, report_value: p.lastValue }) };
}

/** Screen messages after importing a corrections file. */
export function correctionsImportMessages(result: { overrides: EventOverride[]; failedRepairs: FailedRepair[]; missingFiles: string[] }): Message[] {
    const out = [message('corrections_imported', { overrides: result.overrides.length, failed_repairs: result.failedRepairs.length })];

    if (result.missingFiles.length) {
        out.push(message('corrections_missing_files', { files: result.missingFiles.join(', ') }));
    }

    return out;
}
