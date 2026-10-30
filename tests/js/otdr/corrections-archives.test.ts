// Corrections file (traceability, import), device persistence and deliverables archives (rules §11.4).
import ExcelJS from 'exceljs';
import { unzipSync } from 'fflate';
import { describe, expect, it } from 'vitest';
import {
    analyze, buildCorrections, buildDco, buildFailedRepairsWorkbook, buildFiberCableTxt, buildGdcoArchive, buildStandardArchive, configureProduct,
    correctionsDeliverable, correctionsImportMessages, correctionsLog, CORRECTIONS_FORMAT, dcoFileName, dossierKey, encodeUtf16le, evaluateGdco,
    failedRepairsFileName, gdcoArchiveFileName, gdcoArchiveLayout, isCorrectionsFormat, OtdrError, parseCorrections, parseStoredCorrections,
    storedCorrectionsPayload, txtFileName, type EventOverride,
} from '../../../resources/js/otdr';
import { byNameHash, loadSorFolder, SETS } from './support';

const failedRepairs = [{ cableId: 'SRO-BPI-1234567_PBO012_B2-T2-ME', fiber: 20, event: 2, lambda: 1310 as const, lastValue: 0.35, startValue: 0.41, date: new Date(Date.UTC(2026, 9, 18)) }];

describe('corrections file', () => {
    const emptyDossier = analyze([]);

    it('is exported with the neutral format id and the configured product', () => {
        configureProduct({ name: 'Acme Recette OTDR', version: '2.0.0' });
        const file = buildCorrections(emptyDossier, [], failedRepairs, new Date(0));
        expect(file.format).toBe(CORRECTIONS_FORMAT);
        expect(file.format).toBe('otdr-corrections');
        expect(file.generator).toBe('Acme Recette OTDR v2.0.0');
        expect(file.failedRepairs).toEqual([{ ...failedRepairs[0], date: '2026-10-18T00:00:00.000Z' }]);
        configureProduct({ name: 'Recette OTDR', version: '1.0.0' });
    });

    it('imports the files of the previous platform (product-prefixed id, French values, rows under "pv")', () => {
        expect(isCorrectionsFormat('otdr-corrections')).toBe(true);
        expect(isCorrectionsFormat('previous-otdr-corrections')).toBe(true);
        expect(isCorrectionsFormat('other-format')).toBe(false);
        const legacy = JSON.stringify({
            format: 'previous-otdr-corrections', version: 1, generatedAt: '', generator: '', dossier: {},
            overrides: [
                { file: 'a.sor', event: 2, action: 'ignorer' },
                { file: 'a.sor', event: 900, action: 'ajouter', km: 0.7, kind: 'épissure' },
                { file: 'a.sor', event: 3, action: 'connecteur' },
                { file: 'b.sor', event: 1, action: 'épissure' },
                { file: 'a.sor', event: 4, action: 'unknown' },
            ],
            pv: [{ ...failedRepairs[0], date: '2026-10-18T00:00:00.000Z' }],
        });
        const back = parseCorrections(legacy, ['a.sor']);
        expect(back.overrides).toEqual([
            { file: 'a.sor', event: 2, action: 'ignore' },
            { file: 'a.sor', event: 900, action: 'add', km: 0.7, kind: 'splice' },
            { file: 'a.sor', event: 3, action: 'connector' },
        ]);
        expect(back.missingFiles).toEqual(['b.sor']);
        expect(back.failedRepairs).toEqual(failedRepairs);
        expect(correctionsImportMessages(back)).toEqual([
            { key: 'corrections_imported', params: { overrides: 3, failed_repairs: 1 } },
            { key: 'corrections_missing_files', params: { files: 'b.sor' } },
        ]);
    });

    it('refuses what is not a corrections file', () => {
        const keyOf = (text: string) => {
            try {
                parseCorrections(text, []);
            } catch (e) {
                return e instanceof OtdrError ? e.detail.key : 'other';
            }

            return 'none';
        };
        expect(keyOf('{}')).toBe('corrections_invalid_format');
        expect(keyOf('not json')).toBe('corrections_unreadable');
    });

    it('device persistence: key of the file set, payload, removal when empty', () => {
        const inputs = [{ name: 'b.sor', bytes: new Uint8Array(3) }, { name: 'a.sor', bytes: new Uint8Array(5) }];
        expect(dossierKey(inputs)).toBe(dossierKey([...inputs].reverse()));
        expect(dossierKey(inputs)).toMatch(/^[0-9a-f]{8}$/);
        expect(storedCorrectionsPayload([], [])).toBeNull();
        const overrides: EventOverride[] = [{ file: 'a.sor', event: 2, action: 'ignore' }];
        const payload = storedCorrectionsPayload(overrides, failedRepairs, new Date(0));
        expect(parseStoredCorrections(payload)).toEqual({ overrides, failedRepairs, savedAt: new Date(0) });
        expect(parseStoredCorrections('broken')).toBeNull();
    });

    it('exported file name carries the configured brand', () => {
        configureProduct({ name: 'Acme Recette OTDR', brand: 'Acme' });
        const file = correctionsDeliverable({ ...emptyDossier, kind: 'distribution', origin: 'SRO-BPI-1' }, [], []);
        expect(file.name).toBe('Corrections_Acme_SRO-BPI-1.json');
        expect(file.mime).toBe('application/json');
        configureProduct({ name: 'Recette OTDR', brand: 'Recette OTDR' });
    });
});

describe('archives', () => {
    it('transport: Transport/ and Transport/SOR/FTTE/', () => {
        const z = {
            dossier: { ...analyze([]), kind: 'transport' as const }, pm: 'SRO-BPI-1234567', transportType: 'FTTE' as const,
            dco: { name: 'FTTE_x_OK.xlsx', data: new Uint8Array(1) }, txt: { name: 'SRO-BPI-1234567_Transport FTTE.txt', data: new Uint8Array(1) },
            sor: [{ name: 'a.sor', data: new Uint8Array(1) }],
        };
        expect(Object.keys(gdcoArchiveLayout(z))).toEqual([
            'SRO-BPI-1234567/Transport/FTTE_x_OK.xlsx',
            'SRO-BPI-1234567/Transport/SOR/FTTE/a.sor',
            'SRO-BPI-1234567/Transport/SOR/FTTE/SRO-BPI-1234567_Transport FTTE.txt',
        ]);
        expect(gdcoArchiveFileName(z)).toBe('SRO-BPI-1234567_Transport_FTTE_livrables.zip');
    });

    it('standard: <name>/report, <name>/.txt, <name>/SOR/*.sor, corrections with the brand', () => {
        configureProduct({ name: 'Acme Recette OTDR', brand: 'Acme' });
        const zip = buildStandardArchive({
            name: 'CABLE/1', report: { name: 'CABLE_1 OK.xlsx', data: new Uint8Array([1]) }, txt: { name: 'CABLE_1.txt', data: new Uint8Array([2]) },
            sor: [{ name: 'a.sor', data: new Uint8Array([3]) }], corrections: { json: new Uint8Array([4]), log: new Uint8Array([5]) },
        });
        expect(Object.keys(unzipSync(zip)).sort()).toEqual(['CABLE_1/CABLE_1 OK.xlsx', 'CABLE_1/CABLE_1.txt', 'CABLE_1/Corrections_Acme.json', 'CABLE_1/Corrections_Acme.txt', 'CABLE_1/SOR/a.sor']);
        configureProduct({ name: 'Recette OTDR', brand: 'Recette OTDR' });
    });
});

describe.skipIf(!SETS.distribution)('corrections and archive on a real dossier', () => {
    const files = loadSorFolder(SETS.distribution);
    const name = byNameHash(files, '864d41f613c1')[0]?.name ?? '';
    const overrides: EventOverride[] = [
        { file: name, event: 2, action: 'ignore' },
        { file: name, event: 900, action: 'add', km: 0.7, kind: 'splice' },
        { file: 'absent.sor', event: 1, action: 'connector' },
    ];

    it('export → import: same corrections, missing files reported', () => {
        const d = analyze(files, undefined, overrides);
        const json = JSON.stringify(buildCorrections(d, overrides, failedRepairs));
        const back = parseCorrections(json, files.map((f) => f.name));
        expect(back.overrides).toEqual(overrides.slice(0, 2));
        expect(back.missingFiles).toEqual(['absent.sor']);
        expect(back.failedRepairs).toEqual(failedRepairs);
    });

    it('readable log: each correction and the computed loss of the addition (French file format)', () => {
        const d = analyze(files, undefined, overrides);
        const log = correctionsLog(d, overrides.slice(0, 2), failedRepairs);
        expect(log).toContain(`${name} · événement 2 : ignoré`);
        expect(log).toMatch(new RegExp(`${name.replace(/[.]/g, '\\.')} · ajout A1 : ajouté à 700 m sur la courbe \\(épissure\\) — perte calculée -?\\d,\\d{3} dB`));
        expect(log).toContain('PBO012 · fibre 20 · événement 2 · 1310 nm : départ 0,410 dB → dernière reprise 0,350 dB');
        expect(log).toContain('non modifiés');
        expect(log.split('\r\n')[0]).toBe('Registre des corrections — Recette OTDR v1.0.0');
    });

    it('distribution archive: §11.4 tree and untouched contents', async () => {
        const d = analyze(files, undefined, overrides.slice(0, 2));
        const s = evaluateGdco(d, { failedRepairs });
        const info = { zone: 'ZONE TEST', g2r: '9900000001', pm: 'SRO-BPI-1234567', stit: 'STIT', technician: 'TECH', generatedAt: new Date(2026, 9, 18, 16, 0), failedRepairs };
        const dco = new Uint8Array(await buildDco(ExcelJS, d, s, info).xlsx.writeBuffer());
        const repairs = new Uint8Array(await buildFailedRepairsWorkbook(ExcelJS, failedRepairs, info.pm).xlsx.writeBuffer());
        const z = {
            dossier: d, pm: info.pm,
            dco: { name: dcoFileName(d, s, info), data: dco },
            txt: { name: txtFileName(d, info.pm), data: encodeUtf16le(buildFiberCableTxt(d)) },
            sor: files.map((f) => ({ name: f.name, data: f.bytes as Uint8Array })),
            rop: { name: 'ROP_ROPVTL_SRO-BPI-1234567_20241207.csv', data: new TextEncoder().encode('a;b\n') },
            failedRepairs: { name: failedRepairsFileName(info.pm), data: repairs },
            preRepairSor: [{ name: 'before_repair.sor', data: new Uint8Array([1, 2, 3]) }],
            corrections: { json: new TextEncoder().encode('{}'), log: new TextEncoder().encode('log') },
        };
        const paths = Object.keys(gdcoArchiveLayout(z));
        expect(paths).toContain('SRO-BPI-1234567/Distribution/ZONE TEST_SRO-BPI-1234567_Distribution_20261018_1600_OK.xlsx');
        expect(paths).toContain('SRO-BPI-1234567/Distribution/SOR/SRO-BPI-1234567_Distribution.txt');
        expect(paths).toContain('SRO-BPI-1234567/Distribution/SOR/ROP_ROPVTL_SRO-BPI-1234567_20241207.csv');
        expect(paths).toContain('SRO-BPI-1234567/Distribution/SOR/Corrections_Recette OTDR.txt');
        expect(paths).toContain('SRO-BPI-1234567/Distribution/EchecsDeReprises/PVECHECS_SRO-BPI-1234567.xlsx');
        expect(paths).toContain('SRO-BPI-1234567/Distribution/EchecsDeReprises/before_repair.sor');
        expect(paths.filter((p) => p.endsWith('.sor') && p.includes('/SOR/'))).toHaveLength(932);

        const unz = unzipSync(buildGdcoArchive(z));
        const sorIn = unz[`SRO-BPI-1234567/Distribution/SOR/${name}`]!;
        expect(Buffer.from(sorIn).equals(Buffer.from(files.find((f) => f.name === name)!.bytes as Uint8Array))).toBe(true);
        expect(unz['SRO-BPI-1234567/Distribution/SOR/SRO-BPI-1234567_Distribution.txt']!.length).toBe(z.txt.data.length);
        expect(gdcoArchiveFileName(z)).toBe('SRO-BPI-1234567_Distribution_livrables.zip');
    });
});
