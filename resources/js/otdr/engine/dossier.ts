import { endEdge, endIndex, findMissing, finishTrace, geometry, inferEnd, insertEvents, isDetected, Lsa, measureAt, measureReflectance, sameEventTol } from './analysis';
import { message, type Message } from './messages';
import { guessKind, parseSorName } from './naming';
import { DEFAULT_ACQUISITION, GDCO_ACQUISITION, type AcquisitionConfig } from './profile';
import { isEndEvent, readSor, type SorEvent, type SorFile, unreadableFileMessage } from './sor';
import { AddEventError, addEvent, mean3, round2, round3, totalLoss } from './trace';
import type {
    Dossier, DossierKind, EventKind, EventOverride, EventResult, FiberResult, FileMeta, Lambda, MeasureMode, SectionResult,
} from './types';

export interface InputFile {
    name: string;
    bytes: ArrayBuffer | Uint8Array;
}

interface Loaded {
    sor: SorFile;
    cableId: string;
    fiber: number;
    direction: 'OE' | 'EO';
    lambda: Lambda;
    /** Kinds forced per event number. */
    kinds: Map<number, EventKind>;
    edited: boolean;
}

const key = (cable: string, fiber: number, lambda: number, dir = 'OE') => `${cable}|${fiber}|${lambda}|${dir}`;

function lambdaOf(nm: number): Lambda | null {
    if (Math.abs(nm - 1310) < 15) {
        return 1310;
    }

    if (Math.abs(nm - 1550) < 15) {
        return 1550;
    }

    return null;
}

function meta(s: SorFile): FileMeta {
    return {
        name: s.name, date: s.date, otdr: s.otdr, otdrSerial: s.otdrSerial, module: s.module,
        moduleSerial: s.moduleSerial, ior: s.ior, pulseNs: s.pulseNs, averagingTimeS: s.averagingTimeS,
        rangeKm: s.rangeKm, lambda: s.wavelengthNm, origin: s.origin, termination: s.termination,
        fiberId: s.fiberId, operator: s.operator, comment: s.comment, orl: s.orl,
        backscatterDb: s.backscatterDb, sampleM: s.sampleKm * 1000,
    };
}

/**
 * Applies the corrections of a file to its trace (in place): ignored events removed (never the fiber end), added
 * events computed on the trace. Returns the kinds forced per event number.
 */
export function applyOverrides(sor: SorFile, ov: EventOverride[], warnings: Message[] = []): Map<number, EventKind> {
    const ignored = new Set(ov.filter((o) => o.action === 'ignore').map((o) => o.event));

    if (ignored.size) {
        sor.events = sor.events.filter((e) => isEndEvent(e) || !ignored.has(e.num));
    }

    for (const o of ov) {
        if (o.action !== 'add' || o.km === undefined) {
            continue;
        }

        try {
            const e = addEvent(sor, o.km, o.event);
            sor.events = [...sor.events, e].sort((a, b) => a.time - b.time);
        } catch (err) {
            const reason = err instanceof AddEventError ? err.reason : 'section_too_short';
            warnings.push(message(`added_event_refused_${reason}`, { file: sor.name, distance: Math.round(o.km * 1000) }));
        }
    }

    const kinds = new Map<number, EventKind>();

    for (const o of ov) {
        if (o.action === 'connector' || o.action === 'splice') {
            kinds.set(o.event, o.action);
        } else if (o.action === 'add' && o.kind) {
            kinds.set(o.event, o.kind);
        }
    }

    return kinds;
}

/**
 * "Fiber start" event placed at 0 m by some OTDRs (SmartOTDR 25.x: code …2P, time 0): it is the connector of the
 * instrument, not an event of the link. It is removed.
 */
export function dropOrigin(sor: SorFile): void {
    sor.events = sor.events.filter((e) => isEndEvent(e) || e.time > 0);
}

/** Useful events of a trace: everything before the fiber end. */
function untilEnd(events: SorEvent[]): { useful: SorEvent[]; end: SorEvent | null } {
    const i = events.findIndex(isEndEvent);

    return i < 0 ? { useful: events, end: null } : { useful: events.slice(0, i), end: events[i]! };
}

const refl = (e: SorEvent): number | null => (e.reflectance === 0 ? null : round2(e.reflectance));
const nz = (v: number): number | null => (v === 0 ? null : v);

/** EO measurement files present (classic bidirectional measurement). */
const hasEo = (files: { direction: 'OE' | 'EO' }[]) => files.some((l) => l.direction === 'EO');

/**
 * OTDR settings deduced from the files, like the usual settings of the operator dossiers:
 * distribution (SRO-BPI → PBO) classic with one launch spool; transport looped (launch spool, receive spool, one
 * loop-back spool, or none when both fibers are joined directly at the extremity) or classic bidirectional when EO
 * files are present.
 */
export function suggestAcquisition(kind: DossierKind, files: { direction: 'OE' | 'EO'; sor?: SorFile }[]): AcquisitionConfig {
    if (kind === 'distribution') {
        return { ...GDCO_ACQUISITION.distribution, auto: true };
    }

    if (hasEo(files)) {
        return { ...GDCO_ACQUISITION.transport, auto: true, loop: false };
    }

    const cfg: AcquisitionConfig = { ...GDCO_ACQUISITION.transport, auto: true };

    return { ...cfg, loopSpools: loopSpoolsOf(files, cfg) };
}

/**
 * Loop-back spools deduced from the loop traces: one spool gives 2k events (k per fiber), a direct joint 2k − 1 (the
 * joint is common to both fibers). Most traces with an odd number of events → direct loop-back.
 */
function loopSpoolsOf(files: { sor?: SorFile }[], cfg: AcquisitionConfig): 0 | 1 {
    const counts = files.filter((f) => f.sor).map((f) => trimSpools(untilEnd(f.sor!.events).useful, cfg).length);
    const odd = counts.filter((n) => n % 2 === 1).length;

    return odd * 2 > counts.length ? 0 : 1;
}

/**
 * @param forceKind  transport = bidirectional treatment (both directions), distribution = unidirectional (O→E).
 * @param acq        OTDR settings of the standard treatment; missing or `auto`: deduced from the files.
 */
export function analyze(inputs: InputFile[], forceKind?: DossierKind, overrides: EventOverride[] = [], acq?: Partial<AcquisitionConfig>): Dossier {
    const warnings: Message[] = [];
    const byFile = new Map<string, EventOverride[]>();

    for (const o of overrides) {
        byFile.set(o.file, [...(byFile.get(o.file) ?? []), o]);
    }

    const loaded: Loaded[] = [];

    for (const f of inputs) {
        if (!/\.sor$/i.test(f.name)) {
            continue;
        }

        let sor: SorFile;

        try {
            sor = readSor(f.name, f.bytes);
        } catch (e) {
            warnings.push(unreadableFileMessage(f.name, e));
            continue;
        }

        const parsed = parseSorName(f.name);
        const lambda = lambdaOf(sor.wavelengthNm);

        if (!lambda) {
            warnings.push(message('wavelength_ignored', { file: f.name, wavelength: sor.wavelengthNm }));
            continue;
        }

        if (!parsed) {
            warnings.push(message('file_name_invalid', { file: f.name }));
            continue;
        }

        if (parsed.lambda !== lambda) {
            warnings.push(message('wavelength_mismatch', { file: f.name, name_wavelength: parsed.lambda, wavelength: lambda }));
        }

        dropOrigin(sor);
        loaded.push({ sor, cableId: parsed.cableId, fiber: parsed.fiber, direction: parsed.direction, lambda, kinds: new Map(), edited: false });
    }

    if (loaded.length === 0) {
        return { kind: forceKind ?? 'distribution', mode: 'unidirectional', origin: '', termination: '', fibers: [], files: [], warnings, acquisition: { ...DEFAULT_ACQUISITION, ...acq } };
    }

    const byKey = new Map<string, Loaded>();

    for (const l of loaded) {
        const k = key(l.cableId, l.fiber, l.lambda, l.direction);

        if (byKey.has(k)) {
            warnings.push(message('duplicate_file', { file: l.sor.name, original: byKey.get(k)!.sor.name }));
        } else {
            byKey.set(k, l);
        }
    }

    const unique = [...byKey.values()];
    const first = unique[0]!.sor;

    // Effective configuration: manual setting, or deduced from the files.
    let cfg: AcquisitionConfig;
    let kind: DossierKind;

    if (acq && acq.auto === false) {
        cfg = { ...DEFAULT_ACQUISITION, ...acq };

        // Safeguard: distribution measurements PM → PBO (one file per fiber, O→E only) are never loop measurements;
        // the manual "loop" setting of another site would pair them wrongly (empty E→O columns).
        if (cfg.loop && !hasEo(unique) && guessKind(loaded[0]!.cableId) === 'distribution') {
            cfg = { ...cfg, loop: false };
            warnings.push(message('loop_setting_ignored'));
        }

        kind = cfg.loop || hasEo(unique) ? 'transport' : 'distribution';
    } else {
        kind = forceKind ?? guessKind(loaded[0]!.cableId);
        cfg = suggestAcquisition(kind, unique);

        if (acq?.totalLossInput !== undefined) {
            cfg.totalLossInput = acq.totalLossInput;
        }

        if (acq?.totalLossOutput !== undefined) {
            cfg.totalLossOutput = acq.totalLossOutput;
        }

        if (acq?.completeAnalysis !== undefined) {
            cfg.completeAnalysis = acq.completeAnalysis;
        }

        if (acq?.detection !== undefined) {
            cfg.detection = acq.detection;
        }
    }

    // Trace analysis (missed events, markers, slopes), then the operator corrections.
    // Markers and slopes here; missed events afterwards, fiber by fiber, all directions and wavelengths together.
    const ctx: SyncCtx = { cfg, detected: 0, ignored: new Map(), ignoredKm: new Map(), ignoredEv: new Map() };

    for (const l of unique) {
        if (cfg.completeAnalysis) {
            inferEnd(l.sor, cfg.detection);
            finishTrace(l.sor);
        }

        const ov = byFile.get(l.sor.name) ?? [];
        // An event ignored by the operator is not detected again by the analysis.
        const ign = new Set(ov.filter((o) => o.action === 'ignore').map((o) => o.event));
        const ignoredEv = l.sor.events.filter((e) => ign.has(e.num) && !isEndEvent(e));
        ctx.ignoredKm.set(l.sor.name, ignoredEv.map((e) => e.km));
        ctx.ignoredEv.set(l.sor.name, ignoredEv);
        l.kinds = applyOverrides(l.sor, ov, warnings);
        l.edited = ov.length > 0;
        ctx.ignored.set(l.sor.name, new Set(ov.filter((o) => o.action === 'ignore').map((o) => o.event)));
    }

    let mode: MeasureMode;
    let fibers: FiberResult[];

    if (kind === 'distribution') {
        mode = 'unidirectional';

        if (hasEo(unique)) {
            warnings.push(message('eo_files_ignored'));
        }

        const oe = unique.filter((l) => l.direction === 'OE');

        if (cfg.completeAnalysis) {
            // All the fibers of a cable go through the same closures: synchronisation per cable.
            for (const grp of groupBy(oe, (l) => l.cableId)) {
                const dirs: SyncDir[] = [];

                for (const l of grp) {
                    const { useful, end } = untilEnd(l.sor.events);
                    const ev = trimSpools(useful, cfg);

                    if (!ev.length) {
                        continue;
                    }

                    const g = geometry(l.sor);
                    const endIdx = end ? Math.round(end.km / l.sor.sampleKm) : endIndex(l.sor, new Lsa(l.sor.data), g, cfg.detection);
                    dirs.push({ key: String(l.fiber), sor: l.sor, events: ev, reversed: false, from: Math.round(ev[0]!.km / l.sor.sampleKm) + g.deadPts, to: endEdge(l.sor, endIdx) - 2 });
                }

                syncGroup(dirs, ctx, (distance, count) => warnings.push(message('cable_event_not_measurable', { cable: grp[0]!.cableId, distance, count })));
            }
        }

        fibers = oe.map((l) => distributionFiber(l, cfg));

        if (cfg.completeAnalysis) {
            fillSetSlopes(fibers, cfg);
        }

        fillMissingTotals(fibers, cfg);
    } else if (cfg.loop) {
        mode = 'loop';
        fibers = loopFibers(unique, cfg, warnings, ctx);
    } else {
        mode = 'bidirectional';
        fibers = bidirectionalFibers(unique, byKey, cfg, ctx);
    }

    if (ctx.detected) {
        warnings.push(message('events_detected', { count: ctx.detected }));
    }

    fibers.sort((a, b) => a.cableId.localeCompare(b.cableId) || a.lambda - b.lambda || a.fiber - b.fiber);

    return {
        kind, mode, origin: first.origin, termination: kind === 'transport' ? first.termination : '',
        fibers, files: unique.map((l) => meta(l.sor)).sort((a, b) => a.name.localeCompare(b.name)), warnings, acquisition: cfg,
        traces: Object.fromEntries(unique.map((l) => [l.sor.name, { events: l.sor.events, ignored: ctx.ignoredEv.get(l.sor.name) ?? [] }])),
    };
}

/** Removes the joints inside the spools: (start − 1) events at the head, (end − 1) before the fiber end. */
function trimSpools(useful: SorEvent[], cfg: AcquisitionConfig): SorEvent[] {
    const a = Math.max(0, cfg.startSpools - 1);
    const b = Math.max(0, cfg.endSpools - 1);

    return useful.slice(a, Math.max(a, useful.length - b));
}

// ---------------------------------------------------------------- classic measurement, one direction (O→E)

function distributionFiber(l: Loaded, cfg: AcquisitionConfig): FiberResult {
    const { useful: raw, end } = untilEnd(l.sor.events);
    const useful = trimSpools(raw, cfg);
    const fw: Message[] = [];

    if (!end) {
        fw.push(message('fiber_end_not_detected'));
    }

    if (useful.length === 0) {
        fw.push(message(end ? 'fiber_not_measured' : 'no_event_on_trace'));
    }

    const origin = useful[0]?.km ?? 0;
    const last = useful.length - 1;
    const events: EventResult[] = useful.map((e, i) => ({
        index: i + 1,
        num: e.num,
        kind: l.kinds.get(e.num) ?? ((i === 0 && cfg.startSpools >= 1) || (i === last && i > 0 && cfg.endSpools >= 1) ? 'connector' : 'splice'),
        positionM: (e.km - origin) * 1000,
        traceM: e.km * 1000,
        oe: e.loss, eo: null, value: round3(e.loss),
        reflOe: refl(e), reflEo: null,
    }));

    if (cfg.completeAnalysis) {
        events.forEach((ev, i) => {
            if (ev.kind === 'connector' && ev.reflOe === null) {
                ev.reflOe = measureReflectance(l.sor, useful[i]!);
            }
        });
    }

    // End of the link: fiber extremity (without receive spool) or cable / receive spool joint.
    const points = cfg.endSpools >= 1 || !end ? useful : [...useful, end];
    const sections: SectionResult[] = [];

    for (let j = 0; j + 1 < points.length; j++) {
        const len = (points[j + 1]!.km - points[j]!.km) * 1000;
        const sl = nz(points[j + 1]!.slope);
        sections.push({ index: j + 1, lengthOe: len, lengthEo: null, lengthM: len, slopeOe: sl, slopeEo: null, slope: sl === null ? null : round3(sl), slopeSource: sl === null ? undefined : points[j + 1]!.slopeSource });
    }

    // Total loss = Σ losses + Σ slope × length; section without OTDR slope: mean slope of the fiber.
    const known = sections.filter((s) => s.slopeOe !== null).map((s) => s.slopeOe!);
    const { total: tl, estimated } = distributionTotal(events, sections, known.length ? known.reduce((a, b) => a + b, 0) / known.length : null, cfg);

    return {
        cableId: l.cableId, fiber: l.fiber, lambda: l.lambda, events, sections,
        totalLossOe: tl, totalLossEo: null, totalLoss: tl === null ? 0 : round3(tl), totalLossEstimated: estimated,
        lengthM: sections.reduce((s, x) => s + x.lengthM, 0),
        files: [l.sor.name], date: l.sor.date, warnings: fw, edited: l.edited,
    };
}

/** Connector losses excluded from the total loss by "Miscellaneous" (input = O side, output = E side). */
function excludedConnectors(events: EventResult[], cfg: AcquisitionConfig, pick: (e: EventResult) => number | null): number {
    let x = 0;
    const first = events[0];
    const last = events[events.length - 1];

    if (!cfg.totalLossInput && first?.kind === 'connector') {
        x += pick(first) ?? 0;
    }

    if (!cfg.totalLossOutput && last && last !== first && last.kind === 'connector') {
        x += pick(last) ?? 0;
    }

    return x;
}

function distributionTotal(events: EventResult[], sections: SectionResult[], fallbackSlope: number | null, cfg: AcquisitionConfig): { total: number | null; estimated: boolean } {
    let total: number | null = events.reduce((s, e) => s + (e.oe ?? 0), 0) - excludedConnectors(events, cfg, (e) => e.oe);
    let estimated = false;

    for (const s of sections) {
        const sl = s.slopeOe ?? fallbackSlope;

        if (s.slopeOe === null) {
            estimated = true;
        }

        if (sl === null) {
            return { total: null, estimated: true };
        }

        total += (sl * s.lengthM) / 1000;
    }

    return { total, estimated };
}

/** Least direct source of both directions (to report a slope taken from the fiber). */
function worstSource(a?: SectionResult['slopeSource'], b?: SectionResult['slopeSource']): SectionResult['slopeSource'] {
    const order = ['otdr', 'lsa', 'fiber', 'cable', 'set'] as const;
    const ia = a ? order.indexOf(a) : -1;
    const ib = b ? order.indexOf(b) : -1;

    return ia < 0 && ib < 0 ? undefined : order[Math.max(ia, ib)];
}

/**
 * Complete analysis: sections still without slope (no reliable section on the trace) → median slope of the set at the
 * same wavelength (measured sections), then total loss computed again.
 */
function fillSetSlopes(fibers: FiberResult[], cfg: AcquisitionConfig): void {
    const median = (list: FiberResult[], min: number, direct: boolean) => {
        const v = list.flatMap((f) => f.sections.filter((s) => s.slope !== null && (direct ? s.slopeSource === 'otdr' || s.slopeSource === 'lsa' : s.slopeSource !== 'set' && s.slopeSource !== 'cable')).map((s) => s.slope!)).sort((a, b) => a - b);

        return v.length >= min ? v[Math.floor(v.length / 2)]! : null;
    };

    for (const lambda of [1310, 1550] as const) {
        const same = fibers.filter((f) => f.lambda === lambda);
        const all = median(same, 3, true) ?? median(same, 1, false);

        for (const f of fibers) {
            if (f.lambda !== lambda || f.sections.every((s) => s.slope !== null)) {
                continue;
            }

            const cable = median(fibers.filter((x) => x.lambda === lambda && x.cableId === f.cableId), 3, true);
            const med = cable ?? all;

            if (med === null) {
                continue;
            }

            for (const s of f.sections) {
                if (s.slope === null) {
                    s.slopeOe = med;
                    s.slope = round3(med);
                    s.slopeSource = cable !== null ? 'cable' : 'set';
                }
            }

            const { total } = distributionTotal(f.events, f.sections, med, cfg);
            f.totalLossOe = total;
            f.totalLoss = total === null ? 0 : round3(total);
            f.totalLossEstimated = true;
        }
    }
}

/** Fibers without any section slope: mean slope of the set at the same wavelength. */
function fillMissingTotals(fibers: FiberResult[], cfg: AcquisitionConfig): void {
    for (const lambda of [1310, 1550] as const) {
        const slopes = fibers.filter((f) => f.lambda === lambda).flatMap((f) => f.sections.map((s) => s.slopeOe)).filter((x): x is number => x !== null);

        if (!slopes.length) {
            continue;
        }

        const mean = slopes.reduce((a, b) => a + b, 0) / slopes.length;

        for (const f of fibers) {
            if (f.lambda !== lambda || f.totalLossOe !== null) {
                continue;
            }

            const { total } = distributionTotal(f.events, f.sections, mean, cfg);
            f.totalLossOe = total;
            f.totalLoss = total === null ? 0 : round3(total);
            f.totalLossEstimated = true;
        }
    }
}

// ---------------------------------------------------------------- both directions

interface Direction {
    sor: SorFile;
    /** Events in the O→E order. */
    events: SorEvent[];
    /** true when the trace was measured E→O (decreasing distances). */
    reversed: boolean;
    edited: boolean;
    key?: string;
}

interface FiberDirs {
    cableId: string;
    fiber: number;
    lambda: Lambda;
    oe?: Direction;
    eo?: Direction;
    warnings: Message[];
    /** Direct loop-back: the last event is the joint shared with the first fiber of the pair, listed on that fiber only. */
    sharedJoint?: boolean;
}

/** Classic bidirectional measurement: one OE file and one EO file per fiber. */
function bidirectionalFibers(all: Loaded[], byKey: Map<string, Loaded>, cfg: AcquisitionConfig, ctx: SyncCtx): FiberResult[] {
    const out: FiberDirs[] = [];

    for (const l of all.filter((x) => x.direction === 'OE')) {
        const fw: Message[] = [];
        const mine = trimSpools(untilEnd(l.sor.events).useful, cfg);
        const e = byKey.get(key(l.cableId, l.fiber, l.lambda, 'EO'));
        let eo: Direction | undefined;

        if (!e) {
            fw.push(message('eo_file_missing'));
        } else {
            const theirs = trimSpools(untilEnd(e.sor.events).useful, cfg);

            if (theirs.length !== mine.length) {
                fw.push(message('event_count_mismatch', { file: e.sor.name, count: theirs.length, expected: mine.length }));
            }

            eo = { sor: e.sor, events: theirs.slice(0, mine.length).reverse(), reversed: true, edited: e.edited };
        }

        const d: FiberDirs = { cableId: l.cableId, fiber: l.fiber, lambda: l.lambda, oe: { sor: l.sor, events: mine, reversed: false, edited: l.edited }, eo, warnings: fw };
        out.push(d);
    }

    return finishTransport(out, { o: cfg.startSpools >= 1, e: cfg.endSpools >= 1 }, cfg, ctx);
}

/**
 * Loop measurement: each file holds a fiber a (out) then, after the loop-back at the extremity, the fiber b (back).
 * Pairs follow the numbering sequence (n, n+1…: 1↔2, 3↔4; n, n, n+2…: file n holds the pair n / n+1). Directions
 * follow the sequence: from O (a in O→E, b in E→O), from E (a in E→O, b in O→E), or both (_OE_ / _EO_ suffix of the
 * file).
 */
function loopFibers(all: Loaded[], cfg: AcquisitionConfig, warnings: Message[], ctx: SyncCtx): FiberResult[] {
    const dirs = new Map<string, FiberDirs>();
    const at = (cableId: string, fiber: number, lambda: Lambda) => {
        const k = key(cableId, fiber, lambda);
        let d = dirs.get(k);

        if (!d) {
            d = { cableId, fiber, lambda, warnings: [] };
            dirs.set(k, d);
        }

        return d;
    };
    const R = cfg.loopSpools;

    for (const l of [...all].sort((x, y) => x.sor.name.localeCompare(y.sor.name))) {
        const ev = trimSpools(untilEnd(l.sor.events).useful, cfg);
        // R ≥ 1: k events + (R − 1) joints between spools + k; R = 0: the loop-back joint is shared.
        const extra = Math.max(0, R - 1);
        const kk = R >= 1 ? (ev.length - extra) / 2 : (ev.length + 1) / 2;
        const k = Math.floor(kk);

        if (k < 1) {
            warnings.push(message('loop_too_few_events', { file: l.sor.name }));
            continue;
        }

        const segA = ev.slice(0, k);
        const segB = R >= 1 ? ev.slice(k + extra, k + extra + k) : ev.slice(k - 1, 2 * k - 1);
        const a = l.fiber;
        const b = cfg.sequence === 'n,n' ? l.fiber + 1 : l.fiber % 2 === 1 ? l.fiber + 1 : l.fiber - 1;
        const fromE = cfg.loopDirection === 'EO' || (cfg.loopDirection === 'OE+EO' && l.direction === 'EO');
        const da = at(l.cableId, a, l.lambda);
        const db = at(l.cableId, b, l.lambda);

        // Direct loop-back: like FiberCable, the common joint is an event of the first fiber of the pair only.
        if (R === 0) {
            (cfg.sequence === 'n,n' || a % 2 === 1 ? db : da).sharedJoint = true;
        }

        if (kk !== k) {
            da.warnings.push(message('loop_event_count_incompatible', { file: l.sor.name, count: ev.length, spools: R }));
        }

        if (segB.length !== k) {
            db.warnings.push(message('loop_return_incomplete', { file: l.sor.name, count: segB.length, expected: k }));
        }

        const put = (d: FiberDirs, dir: 'oe' | 'eo', events: SorEvent[], reversed: boolean) => {
            if (!d[dir]) {
                d[dir] = { sor: l.sor, events, reversed, edited: l.edited };
            }
        };

        if (!fromE) {
            put(da, 'oe', segA, false); // a measured O→E
            put(db, 'eo', [...segB].reverse(), true); // b measured E→O
        } else {
            put(da, 'eo', [...segA].reverse(), true); // a measured E→O
            put(db, 'oe', segB, false); // b measured O→E
        }
    }

    const out: FiberDirs[] = [];

    for (const d of dirs.values()) {
        if (!d.oe) {
            if (d.eo) {
                warnings.push(message('loop_only_eo', { fiber: d.fiber, wavelength: d.lambda }));
            }

            continue;
        }

        if (!d.eo) {
            d.warnings.push(message('loop_eo_unavailable'));
        }

        out.push(d);
    }

    return finishTransport(out, { o: cfg.startSpools >= 1 && cfg.endSpools >= 1, e: true }, cfg, ctx);
}

interface SyncCtx {
    cfg: AcquisitionConfig;
    detected: number;
    /** Events ignored by the operator, per file. */
    ignored: Map<string, Set<number>>;
    /** Positions (km on the trace) of the ignored OTDR events, per file. */
    ignoredKm: Map<string, number[]>;
    /** Ignored events (OTDR or detected), per file, for the trace view. */
    ignoredEv: Map<string, SorEvent[]>;
}

function groupBy<T>(items: T[], k: (x: T) => string): T[][] {
    const m = new Map<string, T[]>();

    for (const x of items) {
        m.set(k(x), [...(m.get(k(x)) ?? []), x]);
    }

    return [...m.values()];
}

/** One trace of a fiber (one direction, one wavelength): events in the O→E order, search zone. */
interface SyncDir {
    sor: SorFile;
    events: SorEvent[];
    /** Trace measured E→O. */
    reversed: boolean;
    /** Search zone (trace indices); by default, from the first to the last event. */
    from?: number;
    to?: number;
    /** Fiber (the "ignore" corrections apply to its traces, wavelengths and directions). */
    key?: string;
}

/** Transport: search fiber by fiber (both directions, both wavelengths), then computation. */
function finishTransport(list: FiberDirs[], conn: { o: boolean; e: boolean }, cfg: AcquisitionConfig, ctx: SyncCtx): FiberResult[] {
    if (cfg.completeAnalysis) {
        for (const grp of groupBy(list, (d) => d.cableId)) {
            const dirs = grp.flatMap((d) => [d.oe, d.eo].filter((x): x is Direction => !!x && x.events.length >= 2).map((x) => Object.assign(x, { key: String(d.fiber) })));
            syncGroup(dirs, ctx, (distance, count) => grp[0]!.warnings.push(message('event_not_measurable', { distance, count })));
        }
    }

    return list.map((d) => transportFiber(d, conn, cfg));
}

/**
 * Complete analysis of a fiber: the events missed by the OTDR are searched on each trace of the fiber (O→E, E→O,
 * 1310 and 1550 nm); an event found on one of them is measured at the same distance from the origin O on the others
 * (otherwise it is dropped with a warning): directions and wavelengths stay paired event by event (bidirectional means,
 * 1550 − 1310 gap).
 */
function syncGroup(dirs: SyncDir[], ctx: SyncCtx, warn: (distanceM: number, fibers: number) => void): void {
    if (!dirs.length) {
        return;
    }

    const xOf = (dir: SyncDir, km: number) => (dir.reversed ? dir.events[0]!.km - km : km - dir.events[0]!.km);
    const kmOf = (dir: SyncDir, x: number) => (dir.reversed ? dir.events[0]!.km - x : dir.events[0]!.km + x);
    // Same event: within a few pulses, and up to 0.4 % of the distance between fibers of a same cable (fiber over-length
    // in the tubes).
    const tolKm = (dir: SyncDir, x = 0) => Math.max(sameEventTol(dir.sor) * dir.sor.sampleKm, 0.004 * Math.abs(x));
    // Extent of the fiber on each trace (distance from O): up to the last event, or up to the given search zone
    // (distribution: up to the fiber end).
    const span = (dir: SyncDir) => (dir.to !== undefined ? xOf(dir, dir.to * dir.sor.sampleKm) : Math.max(...dir.events.map((e) => xOf(dir, e.km))));
    const known = (dir: SyncDir) => [...dir.sor.events.map((e) => e.km), ...(ctx.ignoredKm.get(dir.sor.name) ?? [])]
        .map((km) => xOf(dir, km)).filter((x) => x > -tolKm(dir) && x < span(dir) + tolKm(dir, x));
    const has = (dir: SyncDir, x: number) => known(dir).some((k) => Math.abs(k - x) <= tolKm(dir, x));
    // Sources: missed events found on each trace, and events already present on one trace (OTDR, manual addition)
    // that would be missing on another (wavelength, direction, other fiber of the cable).
    const found: { x: number; dir: SyncDir; ev: SorEvent; known?: boolean }[] = [];

    for (const dir of dirs) {
        for (const e of dir.events.slice(1)) {
            if (!isEndEvent(e)) {
                found.push({ x: xOf(dir, e.km), dir, ev: e, known: true });
            }
        }
    }

    for (const dir of dirs) {
        const g = geometry(dir.sor);
        const idx = dir.events.map((e) => Math.round(e.km / dir.sor.sampleKm));
        const from = dir.from ?? Math.min(...idx) + g.deadPts;
        const to = dir.to ?? Math.max(...idx) - Math.max(2, Math.ceil(g.pulsePts));

        for (const ev of findMissing(dir.sor, from, to, ctx.cfg.detection)) {
            if (!has(dir, xOf(dir, ev.km))) {
                found.push({ x: xOf(dir, ev.km), dir, ev });
            }
        }
    }

    found.sort((a, b) => a.x - b.x);
    const groups: (typeof found)[] = [];

    for (const f of found) {
        const last = groups[groups.length - 1];

        if (last && Math.abs(f.x - last[last.length - 1]!.x) <= tolKm(f.dir, f.x)) {
            last.push(f);
        } else {
            groups.push([f]);
        }
    }

    const unmeasured = new Map<number, number>();

    for (const grp of groups) {
        const x = grp.reduce((s, f) => s + f.x, 0) / grp.length;
        const isKnown = grp.some((f) => f.known);

        // Event only detected: kept when seen on at least two traces (wavelengths, directions or fibers) or when clear.
        if (!isKnown && new Set(grp.map((f) => f.dir)).size < 2 && Math.max(...grp.map((f) => Math.abs(f.ev.loss))) < 0.1) {
            continue;
        }

        // Per fiber, all or nothing: its traces (wavelengths, directions) stay paired event by event.
        const add: [SyncDir, SorEvent][] = [];
        let missing = 0;

        for (const fiberKey of new Set(dirs.map((d) => d.key))) {
            const mine: [SyncDir, SorEvent][] = [];
            let ok = true;

            for (const dir of dirs.filter((d) => d.key === fiberKey)) {
                if (x <= 0 || x >= span(dir) || has(dir, x)) {
                    continue;
                }

                const ev = grp.find((f) => f.dir === dir)?.ev ?? measureAt(dir.sor, kmOf(dir, x));

                if (!ev) {
                    ok = false;
                    break;
                }

                mine.push([dir, ev]);
            }

            if (ok) {
                add.push(...mine);
            } else {
                missing++;
            }
        }

        if (missing) {
            unmeasured.set(Math.round(x * 1000), missing);
        }

        if (!add.length) {
            continue;
        }

        for (const [dir, ev] of add) {
            insertEvents(dir.sor, [ev]);
            dir.events = [...dir.events, ev].sort((a, b) => xOf(dir, a.km) - xOf(dir, b.km));
        }

        ctx.detected++;
    }

    for (const [m, k] of unmeasured) {
        warn(m, k);
    }

    // Detected event ignored by the operator on one trace: removed from the traces of the same fiber (wavelengths and
    // directions).
    for (const fiberKey of new Set(dirs.map((d) => d.key))) {
        const mine = dirs.filter((d) => d.key === fiberKey);
        const drop: number[] = [];

        for (const dir of mine) {
            const ign = ctx.ignored.get(dir.sor.name);

            for (const e of dir.events) {
                if (isDetected(e) && ign?.has(e.num)) {
                    drop.push(xOf(dir, e.km));
                }
            }
        }

        if (!drop.length) {
            continue;
        }

        const gone = (dir: SyncDir) => (e: SorEvent) => isDetected(e) && drop.some((x) => Math.abs(xOf(dir, e.km) - x) <= tolKm(dir, x));

        for (const dir of mine) {
            const out = dir.sor.events.filter(gone(dir));

            if (out.length) {
                ctx.ignoredEv.set(dir.sor.name, [...(ctx.ignoredEv.get(dir.sor.name) ?? []), ...out]);
            }

            dir.events = dir.events.filter((e) => !gone(dir)(e));
            dir.sor.events = dir.sor.events.filter((e) => !gone(dir)(e));
        }
    }
}

function transportFiber(d: FiberDirs, conn: { o: boolean; e: boolean }, cfg: AcquisitionConfig): FiberResult {
    const oe = d.oe!;
    const eo = d.eo ?? null;
    const k = oe.events.length;
    const origin = oe.events[0]?.km ?? 0;
    const all: EventResult[] = oe.events.map((e, i) => {
        const b = eo?.events[i];
        const vOe = e.loss;
        const vEo = b ? b.loss : null;

        return {
            index: i + 1,
            num: e.num,
            kind: (i === 0 && conn.o) || (i === k - 1 && i > 0 && conn.e) ? 'connector' : 'splice',
            positionM: (e.km - origin) * 1000,
            traceM: e.km * 1000,
            oe: vOe, eo: vEo,
            value: vEo === null ? round3(vOe) : mean3(vOe, vEo),
            reflOe: refl(e), reflEo: b ? refl(b) : null,
        };
    });

    if (cfg.completeAnalysis) {
        all.forEach((ev, i) => {
            if (ev.kind !== 'connector') {
                return;
            }

            if (ev.reflOe === null) {
                ev.reflOe = measureReflectance(oe.sor, oe.events[i]!);
            }

            const b = eo?.events[i];

            if (b && ev.reflEo === null) {
                ev.reflEo = measureReflectance(eo!.sor, b);
            }
        });
    }

    const sections: SectionResult[] = [];

    for (let j = 0; j + 1 < k; j++) {
        const lOe = (oe.events[j + 1]!.km - oe.events[j]!.km) * 1000;
        const sOe = nz(oe.events[j + 1]!.slope);
        let lEo: number | null = null;
        let sEo: number | null = null;

        if (eo && eo.events[j] && eo.events[j + 1]) {
            lEo = (eo.events[j]!.km - eo.events[j + 1]!.km) * 1000;
            sEo = nz(eo.events[j]!.slope);
        }

        const slopes = [sOe, sEo].filter((x): x is number => x !== null);
        sections.push({
            index: j + 1, lengthOe: lOe, lengthEo: lEo,
            lengthM: lEo === null ? lOe : (lOe + lEo) / 2,
            slopeOe: sOe, slopeEo: sEo,
            slope: slopes.length === 2 ? mean3(slopes[0]!, slopes[1]!) : slopes.length ? round3(slopes[0]!) : null,
            slopeSource: worstSource(oe.events[j + 1]!.slopeSource, eo?.events[j]?.slopeSource),
        });
    }

    const rawOe = k ? totalLoss(oe.sor, oe.events[0]!, oe.events[k - 1]!) : null;
    // E→O: the first measured event is the last one in the O→E order.
    const rawEo = eo && eo.events.length === k && k ? totalLoss(eo.sor, eo.events[k - 1]!, eo.events[0]!) : null;
    const tOe = rawOe === null ? null : rawOe - excludedConnectors(all, cfg, (e) => e.oe);
    const tEo = rawEo === null ? null : rawEo - excludedConnectors(all, cfg, (e) => e.eo);
    const tl = [tOe, tEo].filter((x): x is number => x !== null);
    // Shared loop-back joint: it still ends the last section and the total loss, but is listed on the paired fiber.
    const loopJoint = d.sharedJoint && k >= 2 ? all[k - 1] : undefined;
    const events = loopJoint ? all.slice(0, k - 1) : all;

    return {
        cableId: d.cableId, fiber: d.fiber, lambda: d.lambda, events, sections, ...(loopJoint ? { loopJoint } : {}),
        totalLossOe: tOe, totalLossEo: tEo,
        totalLoss: tl.length ? round3(tl.reduce((a, b) => a + b, 0) / tl.length) : 0,
        totalLossEstimated: false,
        lengthM: sections.reduce((s, x) => s + x.lengthM, 0),
        files: [oe.sor.name, ...(eo ? [eo.sor.name] : [])], date: oe.sor.date, warnings: d.warnings,
        edited: oe.edited || (eo?.edited ?? false),
    };
}
