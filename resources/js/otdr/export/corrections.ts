// Manual corrections (ignored, retyped, added events) and failed repair reports: traceability file attached to the
// deliverables. The original .sor files are never modified (they carry the OTDR signature); this file says exactly what
// was corrected, and can be imported again to get the same dossier back.

import { DETECTED_EVENT_BASE, isDetected } from '../engine/analysis';
import type { InputFile } from '../engine/dossier';
import { message, OtdrError } from '../engine/messages';
import { product } from '../engine/product';
import { pboOf, type FailedRepair } from '../engine/rules';
import { ADDED_EVENT_BASE, type Dossier, type EventKind, type EventOverride, type Lambda } from '../engine/types';
import { CORRECTIONS_LOG, EVENT_KIND_LABELS, PREVIOUS_CORRECTIONS_VALUES } from './format';

export const CORRECTIONS_FORMAT = 'otdr-corrections';

/**
 * Format ids accepted on import: the neutral id, and the ids prefixed with a product name used by previous platforms
 * ("{name}-otdr-corrections"), whose files hold the failed repairs under `pv` and French action values.
 */
export const isCorrectionsFormat = (id: unknown): boolean => typeof id === 'string' && /^([a-z0-9]+-)?otdr-corrections$/.test(id);

type SerializedRepair = Omit<FailedRepair, 'date'> & { date?: string };

export interface CorrectionsFile {
    format: typeof CORRECTIONS_FORMAT;
    version: 1;
    generatedAt: string;
    generator: string;
    dossier: { kind: Dossier['kind']; origin: string; termination: string; files: string[] };
    overrides: EventOverride[];
    failedRepairs: SerializedRepair[];
}

export interface Corrections {
    overrides: EventOverride[];
    failedRepairs: FailedRepair[];
}

const serializeRepairs = (list: FailedRepair[]): SerializedRepair[] => list.map((p) => ({ ...p, date: p.date?.toISOString() }));

export function buildCorrections(d: Dossier, overrides: EventOverride[], failedRepairs: FailedRepair[], now = new Date()): CorrectionsFile {
    return {
        format: CORRECTIONS_FORMAT, version: 1, generatedAt: now.toISOString(), generator: `${product().name} v${product().version}`,
        dossier: { kind: d.kind, origin: d.origin, termination: d.termination, files: d.files.map((f) => f.name) },
        overrides,
        failedRepairs: serializeRepairs(failedRepairs),
    };
}

const LEGACY_ACTIONS = PREVIOUS_CORRECTIONS_VALUES.actions;
const LEGACY_KINDS = PREVIOUS_CORRECTIONS_VALUES.kinds;
const ACTIONS: EventOverride['action'][] = ['ignore', 'connector', 'splice', 'add'];
const KINDS: EventKind[] = ['connector', 'splice'];

/** Keeps the well-formed overrides only; legacy French values are mapped to the current ones. */
function readOverrides(list: unknown): EventOverride[] {
    if (!Array.isArray(list)) {
        return [];
    }

    const out: EventOverride[] = [];

    for (const raw of list as Record<string, unknown>[]) {
        if (!raw || typeof raw.file !== 'string' || typeof raw.event !== 'number' || typeof raw.action !== 'string') {
            continue;
        }

        const action = ACTIONS.includes(raw.action as EventOverride['action']) ? (raw.action as EventOverride['action']) : LEGACY_ACTIONS[raw.action];

        if (!action) {
            continue;
        }

        const o: EventOverride = { file: raw.file, event: raw.event, action };

        if (typeof raw.km === 'number') {
            o.km = raw.km;
        }

        if (typeof raw.kind === 'string') {
            const kind = KINDS.includes(raw.kind as EventKind) ? (raw.kind as EventKind) : LEGACY_KINDS[raw.kind];

            if (kind) {
                o.kind = kind;
            }
        }

        out.push(o);
    }

    return out;
}

function readRepairs(list: unknown): FailedRepair[] {
    if (!Array.isArray(list)) {
        return [];
    }

    return (list as Record<string, unknown>[])
        .filter((p) => p && typeof p.cableId === 'string' && typeof p.fiber === 'number' && typeof p.event === 'number' && (p.lambda === 1310 || p.lambda === 1550) && typeof p.lastValue === 'number')
        .map((p) => ({
            cableId: p.cableId as string, fiber: p.fiber as number, event: p.event as number, lambda: p.lambda as Lambda, lastValue: p.lastValue as number,
            startValue: typeof p.startValue === 'number' ? p.startValue : undefined,
            date: typeof p.date === 'string' ? new Date(p.date) : undefined,
        }));
}

/** Reads an exported corrections file (current or legacy format). Throws an OtdrError when it is not one. */
export function parseCorrections(text: string, availableFiles: string[]): Corrections & { missingFiles: string[] } {
    let data: Record<string, unknown>;

    try {
        data = JSON.parse(text) as Record<string, unknown>;
    } catch {
        throw new OtdrError(message('corrections_unreadable'));
    }

    if (!data || !isCorrectionsFormat(data.format) || data.version !== 1) {
        throw new OtdrError(message('corrections_invalid_format'));
    }

    const overrides = readOverrides(data.overrides);
    const have = new Set(availableFiles);
    const missing = [...new Set(overrides.map((o) => o.file).filter((f) => !have.has(f)))];

    return {
        overrides: overrides.filter((o) => have.has(o.file)),
        failedRepairs: readRepairs(data.format === CORRECTIONS_FORMAT ? data.failedRepairs : data.pv),
        missingFiles: missing,
    };
}

/** The dossier holds events found by the complete analysis (to trace in the log). */
export const hasDetected = (d: Dossier) => Object.values(d.traces ?? {}).some((t) => t.events.some(isDetected));

/** Readable log (text) of the corrections, for the controller. */
export function correctionsLog(d: Dossier, overrides: EventOverride[], failedRepairs: FailedRepair[], now = new Date()): string {
    const L = CORRECTIONS_LOG;
    const nf = (v: number, n = 3) => v.toFixed(n).replace('.', ',');
    const lines = [
        L.title(),
        L.dossier(d.kind, d.origin, d.termination),
        L.generatedAt(now),
        L.untouched,
        '',
        L.corrections(overrides.length),
    ];
    const valueOf = (file: string, num: number) => {
        for (const f of d.fibers) {
            if (f.files[0] !== file) {
                continue;
            }

            const e = f.events.find((x) => x.num === num);

            if (e) {
                return e.oe;
            }
        }

        return null;
    };
    const sorted = [...overrides].sort((a, b) => (a.file < b.file ? -1 : a.file > b.file ? 1 : a.event - b.event));

    for (const o of sorted) {
        const t = d.traces?.[o.file];
        const detected = o.event >= DETECTED_EVENT_BASE ? [...(t?.events ?? []), ...(t?.ignored ?? [])].find((e) => e.num === o.event) : undefined;
        const id = o.event >= DETECTED_EVENT_BASE ? L.detectedEvent(detected ? Math.round(detected.km * 1000) : null)
            : o.event >= ADDED_EVENT_BASE ? L.addedEvent(o.event - ADDED_EVENT_BASE + 1) : L.otdrEvent(o.event);
        let what: string;

        if (o.action === 'ignore') {
            what = L.ignored;
        } else if (o.action === 'add') {
            const v = valueOf(o.file, o.event);
            what = L.added(Math.round((o.km ?? 0) * 1000), o.kind ? EVENT_KIND_LABELS[o.kind] : null, v !== null ? nf(v) : null);
        } else {
            what = L.forcedKind(EVENT_KIND_LABELS[o.action]);
        }

        lines.push(L.line(o.file, id, what));
    }

    if (!overrides.length) {
        lines.push(L.noneFeminine);
    }

    // Traceability of the complete analysis: events missing from the OTDR list, measured on the trace.
    const found = Object.entries(d.traces ?? {}).flatMap(([file, t]) => t.events.filter(isDetected).map((e) => ({ file, e })));
    lines.push('', L.detected(found.length));

    for (const { file, e } of found.sort((a, b) => (a.file < b.file ? -1 : a.file > b.file ? 1 : a.e.km - b.e.km))) {
        lines.push(L.detectedLine(file, Math.round(e.km * 1000), nf(e.loss), e.reflectance ? nf(e.reflectance, 2) : null));
    }

    if (!found.length) {
        lines.push(L.noneMasculine);
    }

    lines.push('', L.failedRepairs(failedRepairs.length));

    for (const p of failedRepairs) {
        lines.push(L.failedRepairLine(pboOf(p.cableId), p.fiber, p.event, p.lambda, p.startValue === undefined ? L.noStartValue : nf(p.startValue), nf(p.lastValue)));
    }

    if (!failedRepairs.length) {
        lines.push(L.noneMasculine);
    }

    return lines.join('\r\n') + '\r\n';
}

// ------------------------------------------------------------------ device persistence

/** Fingerprint of the file set (names + sizes), FNV-1a 32 bits: same .sor files → same corrections on reload. */
export function dossierKey(inputs: InputFile[]): string {
    const s = inputs.map((f) => `${f.name}:${f.bytes.byteLength}`).sort().join('|');
    let h = 0x811c9dc5;

    for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = Math.imul(h, 0x01000193) >>> 0;
    }

    return h.toString(16).padStart(8, '0');
}

/** Payload saved on the device for a file set; null when there is nothing to keep (the saved entry is removed). */
export function storedCorrectionsPayload(overrides: EventOverride[], failedRepairs: FailedRepair[], now = new Date()): string | null {
    if (!overrides.length && !failedRepairs.length) {
        return null;
    }

    return JSON.stringify({ overrides, failedRepairs: serializeRepairs(failedRepairs), savedAt: now.toISOString() });
}

/** Reads a payload saved by `storedCorrectionsPayload`; null when missing or unreadable. */
export function parseStoredCorrections(raw: string | null): (Corrections & { savedAt: Date }) | null {
    if (!raw) {
        return null;
    }

    try {
        const s = JSON.parse(raw) as Record<string, unknown>;

        return { overrides: readOverrides(s.overrides), failedRepairs: readRepairs(s.failedRepairs ?? s.pv), savedAt: new Date(String(s.savedAt)) };
    } catch {
        return null;
    }
}
