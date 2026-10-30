// Settings and fields kept on the device (settings, deliverable fields, corrections per file set).
import { DEFAULT_DETECTION } from '../engine/analysis';
import { cloneProfile, CUSTOM_PROFILE_ID, DEFAULT_ACQUISITION, PRESETS, type AcquisitionConfig, type LambdaThresholds, type Profile, type ThresholdKey } from '../engine/profile';
import type { FailedRepair } from '../engine/rules';
import type { EventOverride } from '../engine/types';
import { parseStoredCorrections, storedCorrectionsPayload, type Corrections } from '../export/corrections';
import { CUSTOM_PROFILE_NAME } from '../export/format';
import { DEFAULT_REPORT_OPTIONS, type CableInfo, type ReportOptions } from '../export/measurementReport';
import type { Treatment } from '../workflow';

export const STORAGE_KEYS = {
    treatment: 'otdr:treatment',
    profile: 'otdr:profile',
    customProfile: 'otdr:custom-profile',
    acquisition: 'otdr:acquisition',
    gdcoFields: 'otdr:operator-fields',
    reportFields: 'otdr:report-fields',
    corrections: 'otdr:corrections:',
} as const;

export type DeviceStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

/** The browser storage, or null when it cannot be used (private browsing, blocked site data). */
export function browserStorage(): DeviceStorage | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
}

function read(storage: DeviceStorage | null, key: string): string | null {
    try {
        return storage?.getItem(key) ?? null;
    } catch {
        return null;
    }
}

function readJson(storage: DeviceStorage | null, key: string): unknown {
    const raw = read(storage, key);

    if (raw === null) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function write(storage: DeviceStorage | null, key: string, value: string | null): void {
    try {
        if (value === null) {
            storage?.removeItem(key);
        } else {
            storage?.setItem(key, value);
        }
    } catch {
        // Storage unavailable or full: the screen keeps working, the value is simply not remembered.
    }
}

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): string | undefined => (typeof value === 'string' ? value : undefined);
const flag = (value: unknown): boolean | undefined => (typeof value === 'boolean' ? value : undefined);

// ------------------------------------------------------------------ treatment

export function loadTreatment(storage = browserStorage()): Treatment {
    const value = readJson(storage, STORAGE_KEYS.treatment);

    return value === 'gdco' ? 'gdco' : 'standard';
}

export const saveTreatment = (treatment: Treatment, storage = browserStorage()): void => write(storage, STORAGE_KEYS.treatment, JSON.stringify(treatment));

// ------------------------------------------------------------------ profiles

const THRESHOLD_KEYS: ThresholdKey[] = ['connector', 'splice', 'slope', 'reflectance', 'fiberConnector', 'fiberSplice', 'fiberSlope', 'totalLossMin', 'totalLossMax', 'orl'];
function readThresholds(source: unknown, fallback: LambdaThresholds): LambdaThresholds {
    const out = { ...fallback };

    if (isRecord(source)) {
        for (const key of THRESHOLD_KEYS) {
            const value = source[key];

            if (typeof value === 'number' && Number.isFinite(value)) {
                out[key] = value;
            }
        }
    }

    return out;
}

/** Copy of the first preset named as the custom profile. */
export const blankCustomProfile = (): Profile => cloneProfile(PRESETS[0]!, CUSTOM_PROFILE_ID, CUSTOM_PROFILE_NAME);

/** Custom profile saved on the device (an unreadable one gives a copy of the first preset). */
export function loadCustomProfile(storage = browserStorage()): Profile {
    const saved = readJson(storage, STORAGE_KEYS.customProfile);
    const base = blankCustomProfile();

    if (!isRecord(saved) || !isRecord(saved.thresholds) || !isRecord(saved.thresholds[1310]) || !('fiberSplice' in saved.thresholds[1310])) {
        return base;
    }

    const active = { ...base.active };

    if (isRecord(saved.active)) {
        for (const key of THRESHOLD_KEYS) {
            const value = saved.active[key];

            if (typeof value === 'boolean') {
                active[key] = value;
            }
        }
    }

    return {
        ...base,
        thresholds: { 1310: readThresholds(saved.thresholds[1310], base.thresholds[1310]), 1550: readThresholds(saved.thresholds[1550], base.thresholds[1550]) },
        active,
        constraint: saved.constraint === null ? null : typeof saved.constraint === 'number' ? saved.constraint : base.constraint,
        negativeAlarm: flag(saved.negativeAlarm) ?? base.negativeAlarm,
        ignoreMissingSlopes: flag(saved.ignoreMissingSlopes) ?? base.ignoreMissingSlopes,
    };
}

export const saveCustomProfile = (profile: Profile, storage = browserStorage()): void => write(storage, STORAGE_KEYS.customProfile, JSON.stringify(profile));

/** Chosen profile: a preset, or the custom profile. */
export function loadProfile(storage = browserStorage()): Profile {
    const id = readJson(storage, STORAGE_KEYS.profile);

    if (id === CUSTOM_PROFILE_ID) {
        return loadCustomProfile(storage);
    }

    return cloneProfile(PRESETS.find((p) => p.id === id) ?? PRESETS[0]!);
}

export const saveProfileChoice = (profile: Profile, storage = browserStorage()): void => write(storage, STORAGE_KEYS.profile, JSON.stringify(profile.id));

// ------------------------------------------------------------------ OTDR settings

export function loadAcquisition(storage = browserStorage()): AcquisitionConfig {
    const saved = readJson(storage, STORAGE_KEYS.acquisition);

    if (!isRecord(saved)) {
        return { ...DEFAULT_ACQUISITION, detection: { ...DEFAULT_DETECTION } };
    }

    return {
        ...DEFAULT_ACQUISITION,
        ...(saved as Partial<AcquisitionConfig>),
        detection: { ...DEFAULT_DETECTION, ...(isRecord(saved.detection) ? saved.detection : {}) },
    };
}

export const saveAcquisition = (acquisition: AcquisitionConfig, storage = browserStorage()): void => write(storage, STORAGE_KEYS.acquisition, JSON.stringify(acquisition));

// ------------------------------------------------------------------ deliverable fields

/** Operator deliverable fields kept between dossiers (never the PM nor the total number of fibers). */
export interface GdcoFields {
    zone: string;
    g2r: string;
    stit: string;
    technician: string;
    transportType: 'FTTH' | 'FTTE';
}

export function loadGdcoFields(storage = browserStorage()): Partial<GdcoFields> {
    const saved = readJson(storage, STORAGE_KEYS.gdcoFields);

    if (!isRecord(saved)) {
        return {};
    }

    const transportType = saved.transportType === 'FTTH' || saved.transportType === 'FTTE' ? saved.transportType : undefined;

    return { zone: text(saved.zone), g2r: text(saved.g2r), stit: text(saved.stit), technician: text(saved.technician), transportType };
}

export const saveGdcoFields = (fields: GdcoFields, storage = browserStorage()): void => write(storage, STORAGE_KEYS.gdcoFields, JSON.stringify({
    zone: fields.zone, g2r: fields.g2r, stit: fields.stit, technician: fields.technician, transportType: fields.transportType,
}));

/** Measurement dossier fields kept between dossiers (not the name, origin and extremity, deduced from the files). */
export type ReportFields = Required<Omit<CableInfo, 'name' | 'origin' | 'extremity'>>;

const REPORT_FIELD_KEYS: (keyof ReportFields)[] = ['connection', 'projectManager', 'cableManufacturer', 'cableType', 'fiberManufacturer', 'fiberType'];
const REPORT_OPTION_KEYS: (keyof ReportOptions)[] = ['totalLoss', 'orl', 'comparison', 'outOfLimits'];

export function loadReportFields(storage = browserStorage()): { fields: ReportFields; options: ReportOptions } {
    const saved = readJson(storage, STORAGE_KEYS.reportFields);
    const source = isRecord(saved) ? saved : {};
    const fields = {} as ReportFields;
    const options = { ...DEFAULT_REPORT_OPTIONS };

    for (const key of REPORT_FIELD_KEYS) {
        fields[key] = text(source[key]) ?? '';
    }

    for (const key of REPORT_OPTION_KEYS) {
        options[key] = flag(source[key]) ?? options[key];
    }

    return { fields, options };
}

export const saveReportFields = (fields: ReportFields, options: ReportOptions, storage = browserStorage()): void => write(storage, STORAGE_KEYS.reportFields, JSON.stringify({ ...fields, ...options }));

// ------------------------------------------------------------------ corrections per file set

export const loadCorrections = (key: string, storage = browserStorage()): (Corrections & { savedAt: Date }) | null => parseStoredCorrections(read(storage, STORAGE_KEYS.corrections + key));

/** Saves the corrections of a file set (removed when there is nothing left). */
export const saveCorrections = (key: string, overrides: EventOverride[], failedRepairs: FailedRepair[], storage = browserStorage()): void => write(storage, STORAGE_KEYS.corrections + key, storedCorrectionsPayload(overrides, failedRepairs));
