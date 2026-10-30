// .sor naming (OTDR measurement rules §7):
//   transport     <G2R>_<SRO>_<B>-<T>-<M>-<NNN>_OE_<λ>.sor      e.g. 9900000001_9900001_B1-T4-AU-001_OE_1310.sor
//   distribution  SRO-BPI-<n>_<PBO>_<B>-<T>-<M>_<NNN>_OE_<λ>.sor e.g. SRO-BPI-1234567_PBO001_B2-T3-MC_005_OE_1550.sor
// Direction and wavelength may be swapped (…_009_1310_OE.sor).

import type { DossierKind } from './types';

export interface ParsedName {
    cableId: string;
    fiber: number;
    direction: 'OE' | 'EO';
    lambda: number;
}

const PATTERNS = [
    /^(.+?)[-_](\d{1,4})_(OE|EO)_(\d{4})\.sor$/i,
    /^(.+?)[-_](\d{1,4})_(\d{4})_(OE|EO)\.sor$/i,
];

export function parseSorName(fileName: string): ParsedName | null {
    const base = fileName.split(/[\\/]/).pop() ?? fileName;
    const a = PATTERNS[0]!.exec(base);

    if (a) {
        return { cableId: a[1]!, fiber: Number(a[2]), direction: a[3]!.toUpperCase() as 'OE' | 'EO', lambda: Number(a[4]) };
    }

    const b = PATTERNS[1]!.exec(base);

    if (b) {
        return { cableId: b[1]!, fiber: Number(b[2]), direction: b[4]!.toUpperCase() as 'OE' | 'EO', lambda: Number(b[3]) };
    }

    return null;
}

/** Distribution when the origin is an SRO-BPI (PM → PBO), transport when it is the G2R of a NRO. */
export function guessKind(cableId: string): DossierKind {
    return /^SRO-BPI-/i.test(cableId) ? 'distribution' : 'transport';
}
