// Standard treatment (like FiberCable 2): OTDR settings (acquisition, loop), alarm thresholds, miscellaneous.

import { DEFAULT_DETECTION, type DetectionSettings } from './analysis';
import type { Lambda } from './types';

// ------------------------------------------------------------------ OTDR settings

export type Spools = 0 | 1 | 2 | 3;

/** FiberCable "OTDR settings": acquisition configuration, loop, and total loss computation ("Miscellaneous"). */
export interface AcquisitionConfig {
    /** Setting deduced from the files (names, number of events). */
    auto: boolean;
    /** Launch spools: spools between the OTDR and the cable (0 = OTDR plugged directly). */
    startSpools: Spools;
    /** Receive spools: spools after the cable extremity. */
    endSpools: Spools;
    /** Measurement type: classic (one fiber per file) or loop (two fibers looped back at the extremity). */
    loop: boolean;
    /** Loop: loop-back spools between both fibers (0 = splice or direct connector). */
    loopSpools: Spools;
    /** Loop: measurement direction sequence. */
    loopDirection: 'OE' | 'EO' | 'OE+EO';
    /** Loop: fiber numbering of the files (n, n+1, n+2… or n, n, n+2, n+2…). */
    sequence: 'n+1' | 'n,n';
    /** Total loss: input connector (O) included. */
    totalLossInput: boolean;
    /** Total loss: output connector (E) included. */
    totalLossOutput: boolean;
    /**
     * Complete trace analysis: search for the events missed by the OTDR, LSA markers and missing slopes.
     * Disabled: only the events recorded by the OTDR are used.
     */
    completeAnalysis: boolean;
    /** Detection thresholds of the complete analysis. */
    detection: DetectionSettings;
}

export const DEFAULT_ACQUISITION: AcquisitionConfig = {
    auto: true, startSpools: 1, endSpools: 0, loop: false, loopSpools: 1, loopDirection: 'OE', sequence: 'n+1',
    totalLossInput: true, totalLossOutput: true, completeAnalysis: true, detection: DEFAULT_DETECTION,
};

/** Settings of the operator dossiers (GDCO macro reference): classic distribution, looped transport. */
export const GDCO_ACQUISITION = {
    distribution: { ...DEFAULT_ACQUISITION, auto: false, startSpools: 1, endSpools: 0, loop: false } as AcquisitionConfig,
    transport: { ...DEFAULT_ACQUISITION, auto: false, startSpools: 1, endSpools: 1, loop: true, loopSpools: 1 } as AcquisitionConfig,
};

// ------------------------------------------------------------------ alarm thresholds

/** Thresholds per wavelength, like the FiberCable "Alarm thresholds" window. */
export interface LambdaThresholds {
    /** Thresholds of the mean values (event value, mean of both directions when bidirectional). */
    connector: number;
    splice: number;
    slope: number;
    reflectance: number;
    /** Thresholds of the fiber and cable means. */
    fiberConnector: number;
    fiberSplice: number;
    fiberSlope: number;
    /** Other thresholds (link budget). */
    totalLossMin: number;
    totalLossMax: number;
    /** Minimum admissible ORL (dB). */
    orl: number;
}

export type ThresholdKey = keyof LambdaThresholds;

export interface Profile {
    id: string;
    name: string;
    thresholds: Record<Lambda, LambdaThresholds>;
    /** "Active" box of each line (shared by both wavelengths, like FiberCable). */
    active: Record<ThresholdKey, boolean>;
    /** Constraint detection: 1550 − 1310 gap (dB); null = disabled. */
    constraint: number | null;
    /** "Alarm on negative values": an apparent gain whose |value| exceeds the threshold is reported. */
    negativeAlarm: boolean;
    /** "Ignore missing slopes". */
    ignoreMissingSlopes: boolean;
}

/** Id of the profile created by any edit of the thresholds. */
export const CUSTOM_PROFILE_ID = 'custom';

const ALL_ON: Record<ThresholdKey, boolean> = {
    connector: true, splice: true, slope: true, reflectance: true, fiberConnector: true, fiberSplice: true, fiberSlope: true,
    totalLossMin: false, totalLossMax: false, orl: false,
};

function lambdaThresholds(connector: number, splice: number, slope: number, fiberSplice = splice, fiberSlope = slope): LambdaThresholds {
    return { connector, splice, slope, reflectance: -55, fiberConnector: connector, fiberSplice, fiberSlope, totalLossMin: 10, totalLossMax: 30, orl: 0 };
}

/** Predefined profiles (FiberCable values / operator measurement rules v24.30). Names are reference data. */
export const PRESETS: Profile[] = [
    {
        id: 'fibercable', name: 'FiberCable — transport',
        thresholds: { 1310: lambdaThresholds(0.6, 0.15, 0.38), 1550: lambdaThresholds(0.6, 0.15, 0.25) },
        active: { ...ALL_ON }, constraint: 0.5, negativeAlarm: false, ignoreMissingSlopes: true,
    },
    {
        id: 'xpfibre-transport', name: 'XpFibre transport NRO-PM (RI v24.30)',
        thresholds: { 1310: lambdaThresholds(0.6, 0.15, 0.38), 1550: lambdaThresholds(0.6, 0.15, 0.25) },
        active: { ...ALL_ON }, constraint: 0.1, negativeAlarm: false, ignoreMissingSlopes: true,
    },
    {
        id: 'xpfibre-distribution', name: 'XpFibre distribution PM-PBO (RI v24.30)',
        thresholds: { 1310: lambdaThresholds(0.7, 0.3, 0.38, 0.3, 0.38), 1550: lambdaThresholds(0.7, 0.3, 0.25, 0.3, 0.25) },
        active: { ...ALL_ON, slope: false, fiberSlope: false }, constraint: 0.2, negativeAlarm: false, ignoreMissingSlopes: true,
    },
];

export function cloneProfile(p: Profile, id = p.id, name = p.name): Profile {
    return {
        id, name, constraint: p.constraint, negativeAlarm: p.negativeAlarm, ignoreMissingSlopes: p.ignoreMissingSlopes,
        active: { ...ALL_ON, ...p.active },
        thresholds: { 1310: { ...p.thresholds[1310] }, 1550: { ...p.thresholds[1550] } },
    };
}

/** Active threshold, otherwise null. */
export function activeThreshold(p: Profile, l: Lambda, k: ThresholdKey): number | null {
    return p.active[k] ? p.thresholds[l][k] : null;
}
