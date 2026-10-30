// What the result panels show, built from the dossier and its summary: light, criteria, field to-do list, indicators,
// detail tables per cable / PBO, failed repair rows and file summary. Pure functions (no DOM).
import { CUSTOM_PROFILE_ID } from '../engine/profile';
import { pboOf, type Anomaly, type FailedRepair, type Summary } from '../engine/rules';
import type { Dossier, FiberResult } from '../engine/types';
import { anomalyTypeMessage, canAddFailedRepair, failedRepairStatus, lightMessages, type Treatment } from '../workflow';
import type { Translator } from './texts';

/** Key of a fiber at one wavelength (trace view). */
export const fiberKey = (f: Pick<FiberResult, 'cableId' | 'fiber' | 'lambda'>): string => `${f.cableId}|${f.fiber}|${f.lambda}`;

export const repairKey = (p: Pick<FailedRepair, 'cableId' | 'fiber' | 'event' | 'lambda'>): string => `${p.cableId}|${p.fiber}|${p.event}|${p.lambda}`;

/** "{n} file(s) read · kind · mode [· origin X] [→ Y]". */
export function filesSummary(t: Translator, d: Dossier, fileCount: number): string {
    let out = t.t('files.summary', { count: fileCount, kind: t.message({ key: `dossier_kind_${d.kind}`, params: {} }), mode: t.message({ key: `measure_mode_${d.mode}`, params: {} }) });

    if (d.origin) {
        out += ` · ${t.t('files.origin', { origin: d.origin })}`;
    }

    if (d.termination) {
        out += ` ${t.t('files.termination', { termination: d.termination })}`;
    }

    return out;
}

// ------------------------------------------------------------------ light and criteria

export interface LightView {
    dotClass: string;
    text: string;
    status: string;
    hint: string;
}

export function lightView(t: Translator, treatment: Treatment, s: Summary): LightView {
    const m = lightMessages(treatment, s.light);

    return { dotClass: `otdr__dot otdr__level-${s.light}`, text: t.message(m.text), status: `(${s.status})`, hint: m.hint ? t.message(m.hint) : '' };
}

export interface CriterionView {
    key: string;
    className: string;
    label: string;
    detail: string;
}

export const criteriaView = (t: Translator, s: Summary): CriterionView[] => s.criteria.map((c, i) => ({
    key: String(i), className: `otdr__criterion otdr__level-${c.light}`, label: t.message(c.label), detail: `— ${t.message(c.detail)}`,
}));

// ------------------------------------------------------------------ to-do list

export interface AnomalyView {
    key: string;
    /** Index in the summary anomalies (actions). */
    index: number;
    className: string;
    where: string;
    subject: string;
    value: string;
    note: string;
    canViewTrace: boolean;
    canAddFailedRepair: boolean;
}

function anomalyFiber(d: Dossier, a: Anomaly): FiberResult | undefined {
    return d.fibers.find((x) => x.cableId === a.cableId && x.fiber === a.fiber && (a.lambda === null || x.lambda === a.lambda));
}

/** Fiber and event (trace focus) of an anomaly. */
export function anomalyTarget(d: Dossier, a: Anomaly): { fiber: FiberResult | undefined; eventNum: number | undefined } {
    const fiber = anomalyFiber(d, a);
    const event = a.type !== 'slope' ? fiber?.events[a.index - 1] : undefined;

    return { fiber, eventNum: event?.num };
}

export function anomalyView(t: Translator, d: Dossier, a: Anomaly, index: number, failedRepairs: FailedRepair[], treatment: Treatment): AnomalyView {
    const fiber = anomalyFiber(d, a);
    const event = a.type !== 'slope' ? fiber?.events[a.index - 1] : undefined;
    const unit = t.t(a.type === 'slope' ? 'results.unit_db_km' : 'results.unit_db');
    const reflectance = a.type === 'connector_reflectance' || a.type === 'splice_reflectance';
    const where = [
        d.kind === 'distribution' ? pboOf(a.cableId) : a.cableId,
        a.fiber === 0 ? t.t('results.whole_cable') : t.t('results.fiber', { fiber: a.fiber }),
        ...(a.lambda ? [t.t('results.wavelength', { wavelength: a.lambda })] : []),
    ].join(' · ');
    const position = event
        ? t.t(d.kind === 'transport' ? 'results.position_nro' : 'results.position_pm', { position: Math.round(event.positionM), trace: Math.round(event.traceM) })
        : '';
    const number = a.type === 'slope' ? t.t('results.section', { number: a.index }) : a.index === 0 ? '' : t.t('results.event_number', { number: a.index });
    const threshold = a.type === 'splice_reflectance'
        ? t.t('results.no_reflectance_allowed')
        : t.t('results.threshold', { value: t.fixed(a.threshold, reflectance ? 1 : 2), unit: reflectance ? t.t('results.unit_db') : unit });
    const className = a.neutralized ? 'otdr__todo-item is-neutralized' : a.blocking ? 'otdr__todo-item' : 'otdr__todo-item is-information';
    const suffix = a.neutralized ? ` ${t.t('results.neutralized')}` : !a.blocking ? ` ${t.t('results.information')}` : '';

    return {
        key: `${a.type}|${a.cableId}|${a.fiber}|${a.lambda}|${a.index}|${index}`,
        index,
        className,
        where,
        subject: t.t('results.what', { subject: [t.message(anomalyTypeMessage(a)), number, position].filter((s) => s !== '').join(' ') }),
        value: t.t('results.value', { value: t.fixed(a.value, reflectance ? 2 : 3), unit }),
        note: `(${threshold})${suffix}`,
        canViewTrace: fiber !== undefined,
        canAddFailedRepair: treatment === 'gdco' && canAddFailedRepair(a, d, failedRepairs),
    };
}

/** Blocking anomalies first, then the information ones. */
export function todoView(t: Translator, d: Dossier, s: Summary, failedRepairs: FailedRepair[], treatment: Treatment): { count: number; items: AnomalyView[] } {
    const indexed = s.anomalies.map((a, index) => ({ a, index }));
    const ordered = [...indexed.filter((x) => x.a.blocking), ...indexed.filter((x) => !x.a.blocking)];

    return {
        count: s.anomalies.filter((a) => a.blocking && !a.neutralized).length,
        items: ordered.map(({ a, index }) => anomalyView(t, d, a, index, failedRepairs, treatment)),
    };
}

// ------------------------------------------------------------------ indicators

export function indicatorsView(t: Translator, s: Summary, profileId: string): { key: string; label: string; value: string }[] {
    return Object.entries(s.indicators).map(([key, value]) => ({
        key,
        label: t.t(`messages.${key}`),
        value: key === 'indicator_profile' && profileId === CUSTOM_PROFILE_ID ? t.t('config.custom_profile_name') : t.indicator(value),
    }));
}

// ------------------------------------------------------------------ detail per cable / PBO

export interface DetailCell {
    key: string;
    text: string;
    className: string;
    title: string;
}

export interface DetailRow {
    key: string;
    cableId: string;
    fiber: number;
    lambda: number;
    label: string;
    cells: DetailCell[];
    totalLoss: string;
    totalLossTitle: string;
    length: string;
}

export interface DetailGroup {
    key: string;
    name: string;
    fibersBadge: string;
    outBadge: string;
    defaultOpen: boolean;
    headers: { key: string; label: string }[];
    rows: DetailRow[];
    warnings: string[];
}

/** Neutralised by a failed repair report, out of limits, or apparent gain. */
function cellClass(value: number | null, out: boolean, neutralized: boolean): string {
    if (neutralized) {
        return 'otdr__value--neutralized';
    }

    if (out) {
        return 'otdr__value--out';
    }

    return value !== null && value < 0 ? 'otdr__value--gain' : '';
}

export function detailView(t: Translator, d: Dossier, s: Summary): DetailGroup[] {
    const groups = new Map<string, FiberResult[]>();

    for (const f of d.fibers) {
        groups.set(f.cableId, [...(groups.get(f.cableId) ?? []), f]);
    }

    const flagged = new Map<string, Anomaly>();

    for (const a of s.anomalies) {
        if (a.type === 'splice' || a.type === 'connector') {
            flagged.set(`${a.cableId}|${a.fiber}|${a.lambda}|${a.index}`, a);
        }
    }

    const bidirectional = d.kind === 'transport';
    const f3 = (v: number | null) => t.optionalFixed(v, 3);

    return [...groups.entries()].map(([name, fibers]) => {
        const maxEvents = Math.max(...fibers.map((f) => f.events.length));
        const bad = s.anomalies.filter((a) => a.cableId === name && a.blocking && !a.neutralized).length;
        const headers = Array.from({ length: maxEvents }, (_, i) => ({
            key: String(i),
            label: t.t(i === 0 || (bidirectional && i === maxEvents - 1) ? 'results.column_connector' : 'results.column_splice', { number: i + 1 }),
        }));

        return {
            key: name,
            name: d.kind === 'distribution' ? pboOf(name) : name,
            fibersBadge: t.t('results.fibers_badge', { count: new Set(fibers.map((f) => f.fiber)).size }),
            outBadge: bad > 0 ? t.t('results.out_of_limits_badge', { count: bad }) : '',
            defaultOpen: groups.size <= 3 || bad > 0,
            headers,
            rows: fibers.map((f) => ({
                key: `${f.fiber}-${f.lambda}`,
                cableId: f.cableId,
                fiber: f.fiber,
                lambda: f.lambda,
                label: `${f.fiber}${f.edited ? ' ✎' : ''}`,
                cells: Array.from({ length: maxEvents }, (_, i): DetailCell => {
                    const e = f.events[i];

                    if (!e) {
                        return { key: String(i), text: '', className: '', title: '' };
                    }

                    const a = flagged.get(`${f.cableId}|${f.fiber}|${f.lambda}|${e.index}`);
                    const position = Math.round(e.positionM);
                    const title = bidirectional
                        ? t.t('results.cell_bidirectional', { oe: f3(e.oe), eo: f3(e.eo), position })
                        : e.reflOe !== null ? t.t('results.cell_reflectance', { position, reflectance: t.fixed(e.reflOe, 2) }) : t.t('results.cell_position', { position });

                    return { key: String(i), text: f3(e.value), className: cellClass(e.value, !!a && !a.neutralized, !!a?.neutralized), title };
                }),
                totalLoss: `${f3(f.totalLoss)}${f.totalLossEstimated ? '*' : ''}`,
                totalLossTitle: f.totalLossEstimated ? t.t('results.estimated') : '',
                length: t.fixed(f.lengthM, 1),
            })),
            warnings: fibers.flatMap((f) => f.warnings.map((w) => t.t('results.fiber_warning', { fiber: f.fiber, wavelength: f.lambda, warning: t.message(w) }))),
        };
    });
}

// ------------------------------------------------------------------ failed repair report

export interface RepairView {
    key: string;
    where: string;
    what: string;
    ok: boolean;
}

export const repairsView = (t: Translator, failedRepairs: FailedRepair[], s: Summary): RepairView[] => failedRepairs.map((p) => {
    const status = failedRepairStatus(p, s);

    return {
        key: repairKey(p),
        where: t.t('failed_repairs.where', { pbo: pboOf(p.cableId), fiber: p.fiber, event: p.event, wavelength: p.lambda }),
        what: t.t('failed_repairs.what', {
            start: p.startValue === undefined ? t.t('failed_repairs.no_start') : t.fixed(p.startValue, 3),
            last: t.fixed(p.lastValue, 3),
            status: t.message(status.message),
        }),
        ok: status.ok,
    };
});
