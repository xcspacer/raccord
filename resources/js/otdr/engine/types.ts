import type { Message } from './messages';
import type { AcquisitionConfig } from './profile';
import type { SorEvent } from './sor';

export type Lambda = 1310 | 1550;
export type DossierKind = 'transport' | 'distribution';
export type MeasureMode = 'loop' | 'bidirectional' | 'unidirectional';
export type EventKind = 'connector' | 'splice';
export type SlopeSource = 'otdr' | 'lsa' | 'fiber' | 'cable' | 'set';

/** Manual correction of a trace event (what the operator does with the markers in FiberCable). */
export interface EventOverride {
    /** .sor file name. */
    file: string;
    /** Event number in the file (KeyEvents); ≥ 900 for a manually added event. */
    event: number;
    action: 'ignore' | 'connector' | 'splice' | 'add';
    /** "add": position on the trace, km. */
    km?: number;
    /** "add": wanted kind (distribution). */
    kind?: EventKind;
}

/** First number given to manually added events. */
export const ADDED_EVENT_BASE = 900;

export interface EventResult {
    /** Position in the link, 1…n (origin → extremity). */
    index: number;
    /** Event number in the O→E file (used by the corrections). */
    num: number;
    kind: EventKind;
    /** Distance from the first event, m (O→E). */
    positionM: number;
    /** Distance shown by the OTDR on the O→E trace (launch spool included), m. */
    traceM: number;
    oe: number | null;
    eo: number | null;
    /** Bidirectional mean (or O→E value when unidirectional), rounded to the thousandth like FiberCable. */
    value: number;
    reflOe: number | null;
    reflEo: number | null;
}

export interface SectionResult {
    index: number;
    lengthOe: number | null;
    lengthEo: number | null;
    lengthM: number;
    slopeOe: number | null;
    slopeEo: number | null;
    slope: number | null;
    /**
     * Complete analysis: OTDR slope confirmed by the trace, LSA line (adjusted markers), slope of the fiber (section too
     * short for a reliable line), median slope of the fibers of the same cable, or of the whole set (fiber too short).
     */
    slopeSource?: SlopeSource;
}

export interface FiberResult {
    cableId: string;
    fiber: number;
    lambda: Lambda;
    events: EventResult[];
    /**
     * Loop without loop-back spool, second fiber of the pair: joint shared with the first fiber (listed among its
     * events), which closes the last section and the connector columns of this fiber.
     */
    loopJoint?: EventResult;
    sections: SectionResult[];
    totalLossOe: number | null;
    totalLossEo: number | null;
    totalLoss: number;
    /** true when the total loss was estimated (section slope not given by the OTDR). */
    totalLossEstimated: boolean;
    lengthM: number;
    /** Files used for the computation (O→E then E→O). */
    files: string[];
    date: Date;
    warnings: Message[];
    /** At least one event was corrected by hand. */
    edited: boolean;
}

export interface FileMeta {
    name: string;
    date: Date;
    otdr: string;
    otdrSerial: string;
    module: string;
    moduleSerial: string;
    ior: number;
    pulseNs: number;
    averagingTimeS: number;
    rangeKm: number;
    lambda: number;
    origin: string;
    termination: string;
    fiberId: string;
    operator: string;
    comment: string;
    /** ORL, dB (0 = not given). */
    orl: number;
    backscatterDb: number;
    /** Sampling step, m. */
    sampleM: number;
}

export interface Dossier {
    kind: DossierKind;
    mode: MeasureMode;
    /** Origin / extremity read in the files (G2R / SRO-BPI / PBO). */
    origin: string;
    termination: string;
    fibers: FiberResult[];
    files: FileMeta[];
    /** Ignored files or files to check. */
    warnings: Message[];
    /** Applied OTDR settings (spools, loop, total loss). */
    acquisition: AcquisitionConfig;
    /** Events of each trace after analysis and corrections (trace view), and those ignored by the operator. */
    traces?: Record<string, { events: SorEvent[]; ignored: SorEvent[] }>;
}
