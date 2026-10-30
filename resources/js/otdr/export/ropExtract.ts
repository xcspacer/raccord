// Base ROP extract (ROP_ROPVTL_*.csv, ";" separator): number of optical routes and PBO of the reference, for the DCO
// summary.

import { message, OtdrError } from '../engine/messages';
import { ROP_EXTRACT } from './format';

export interface RopSummary {
    /** PM-PBO optical routes of the reference (one line per route). */
    fibers: number;
    /** Distinct PBO ("reference PBO" column). */
    pbo: number;
    pm: string;
}

/** Throws an OtdrError when the file is not a Base ROP extract. */
export function parseRopCsv(text: string): RopSummary {
    const lines = text.replace(/^﻿/, '').split(/\r?\n/).filter((l) => l.trim() !== '');

    if (lines.length === 0) {
        throw new OtdrError(message('rop_empty'));
    }

    const header = lines[0]!.split(';').map((h) => h.trim());
    const iPbo = header.indexOf(ROP_EXTRACT.pboColumn);
    const iPm = header.indexOf(ROP_EXTRACT.pmColumn);

    if (iPbo < 0) {
        throw new OtdrError(message('rop_missing_pbo_column'));
    }

    const rows = lines.slice(1).map((l) => l.split(';'));
    const pbo = new Set(rows.map((r) => (r[iPbo] ?? '').trim()).filter(Boolean));
    const pm = iPm >= 0 ? (rows.find((r) => r[iPm]?.trim())?.[iPm]?.trim() ?? '') : '';

    return { fibers: rows.length, pbo: pbo.size, pm };
}
