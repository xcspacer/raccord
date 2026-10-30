// Deliverables archive following the measurement rules v24.30 §11.4:
//   <SRO-BPI>/Transport/<FTTH|FTTE>_…_OK.xlsx · <SRO-BPI>/Transport/SOR/<FTTH|FTTE>/*.sor + .txt
//   <SRO-BPI>/Distribution/…_OK.xlsx · <SRO-BPI>/Distribution/SOR/*.sor + .txt + Base ROP extract .csv
//   <SRO-BPI>/Distribution/EchecsDeReprises/PVECHECS_<SRO-BPI>.xlsx + .sor before repair
// The corrections log (when there are corrections or failed repairs) goes with the .txt.

import { type Zippable, zipSync } from 'fflate';
import type { Dossier } from '../engine/types';
import { ARCHIVE, REPORT } from './format';

export interface NamedBytes {
    name: string;
    data: Uint8Array;
}

export interface GdcoArchiveInput {
    dossier: Dossier;
    pm: string;
    transportType?: 'FTTH' | 'FTTE';
    dco: NamedBytes;
    txt: NamedBytes;
    sor: NamedBytes[];
    rop?: NamedBytes;
    failedRepairs?: NamedBytes;
    preRepairSor?: NamedBytes[];
    corrections?: { json: Uint8Array; log: Uint8Array };
}

export const safeFileName = (s: string) => s.replace(/[\\/:*?"<>|]/g, '_');

export function gdcoArchiveFileName(z: Pick<GdcoArchiveInput, 'dossier' | 'pm' | 'transportType'>): string {
    return ARCHIVE.gdcoFileName(safeFileName(z.pm), z.dossier.kind, z.transportType ?? 'FTTH');
}

/** Paths of the archive (also used to show the content before download). */
export function gdcoArchiveLayout(z: GdcoArchiveInput): Record<string, Uint8Array> {
    const root = safeFileName(z.pm);
    const files: Record<string, Uint8Array> = {};
    const put = (path: string, data: Uint8Array) => {
        files[path] = data;
    };

    if (z.dossier.kind === 'transport') {
        const type = z.transportType ?? 'FTTH';
        const sorDir = `${root}/${ARCHIVE.transport}/${ARCHIVE.sor}/${type}`;
        put(`${root}/${ARCHIVE.transport}/${safeFileName(z.dco.name)}`, z.dco.data);

        for (const s of z.sor) {
            put(`${sorDir}/${safeFileName(s.name)}`, s.data);
        }

        put(`${sorDir}/${safeFileName(z.txt.name)}`, z.txt.data);

        if (z.corrections) {
            put(`${sorDir}/${ARCHIVE.correctionsJson()}`, z.corrections.json);
            put(`${sorDir}/${ARCHIVE.correctionsLog()}`, z.corrections.log);
        }
    } else {
        const sorDir = `${root}/${ARCHIVE.distribution}/${ARCHIVE.sor}`;
        put(`${root}/${ARCHIVE.distribution}/${safeFileName(z.dco.name)}`, z.dco.data);

        for (const s of z.sor) {
            put(`${sorDir}/${safeFileName(s.name)}`, s.data);
        }

        put(`${sorDir}/${safeFileName(z.txt.name)}`, z.txt.data);

        if (z.rop) {
            put(`${sorDir}/${safeFileName(z.rop.name)}`, z.rop.data);
        }

        if (z.corrections) {
            put(`${sorDir}/${ARCHIVE.correctionsJson()}`, z.corrections.json);
            put(`${sorDir}/${ARCHIVE.correctionsLog()}`, z.corrections.log);
        }

        if (z.failedRepairs) {
            const dir = `${root}/${ARCHIVE.distribution}/${ARCHIVE.failedRepairs}`;
            put(`${dir}/${safeFileName(z.failedRepairs.name)}`, z.failedRepairs.data);

            for (const s of z.preRepairSor ?? []) {
                put(`${dir}/${safeFileName(s.name)}`, s.data);
            }
        }
    }

    return files;
}

export function buildGdcoArchive(z: GdcoArchiveInput): Uint8Array {
    const layout = gdcoArchiveLayout(z);
    const tree: Zippable = {};

    for (const [path, data] of Object.entries(layout)) {
        // Already compressed (.xlsx) or raw binary (.sor) files: stored; texts: compressed.
        tree[path] = [data, { level: /\.(txt|csv|json)$/i.test(path) ? 6 : 0 }];
    }

    return zipSync(tree);
}

export interface StandardArchiveInput {
    name: string;
    report: NamedBytes;
    txt: NamedBytes;
    sor: NamedBytes[];
    corrections?: { json: Uint8Array; log: Uint8Array };
}

/** Standard treatment archive: <cable>/measurement dossier + .txt, <cable>/SOR/*.sor originals, corrections log. */
export function buildStandardArchive(z: StandardArchiveInput): Uint8Array {
    const root = safeFileName(z.name || REPORT.defaultName);
    const tree: Zippable = {};
    tree[`${root}/${safeFileName(z.report.name)}`] = [z.report.data, { level: 0 }];
    tree[`${root}/${safeFileName(z.txt.name)}`] = [z.txt.data, { level: 6 }];

    for (const s of z.sor) {
        tree[`${root}/${ARCHIVE.sor}/${safeFileName(s.name)}`] = [s.data, { level: 0 }];
    }

    if (z.corrections) {
        tree[`${root}/${ARCHIVE.correctionsJson()}`] = [z.corrections.json, { level: 6 }];
        tree[`${root}/${ARCHIVE.correctionsLog()}`] = [z.corrections.log, { level: 6 }];
    }

    return zipSync(tree);
}
