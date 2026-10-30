// Library behaviour on invented traces: .sor reading, file warnings, screen workflow helpers and deliverables.
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { strFromU8, unzipSync } from 'fflate';
import { describe, expect, it } from 'vitest';
import {
    analyze, analyzeGdco, analyzeStandard, buildFiberCableTxt, configureProduct, correctionsDeliverable, DEFAULT_ACQUISITION, dcoDeliverable, evaluateGdco, evaluateStandard,
    gdcoArchiveDeliverable, gdcoDossierForStandard, gdcoTxtDeliverable, lightMessages, measurementReportDeliverable, PRESETS, readSor, SorFormatError,
    standardArchiveDeliverable, standardTxtDeliverable, suggestCableName, suggestPm, traceEvents, tryAddEvent, XLSX_MIME, type InputFile,
} from '../../../resources/js/otdr';
import { TXT } from '../../../resources/js/otdr/export/format';
import { buildSor, distributionTrace } from './sor-builder';

const file = (name: string, bytes: Uint8Array): InputFile => ({ name, bytes });
const CABLE = 'SRO-BPI-1234567_PBO001_B1-T1-MA';
const fiberFiles = (n: number) => [file(`${CABLE}_00${n}_OE_1310.sor`, distributionTrace(1310)), file(`${CABLE}_00${n}_OE_1550.sor`, distributionTrace(1550, 0.15))];
const inputs = [...fiberFiles(1), ...fiberFiles(2)];

describe('.sor reading', () => {
    it('reads the standard blocks of a synthetic trace', () => {
        const sor = readSor('a.sor', distributionTrace());
        expect(sor.wavelengthNm).toBe(1310);
        expect(sor.ior).toBeCloseTo(1.468, 5);
        expect(sor.pulseNs).toBe(30);
        expect(sor.otdr).toBe('OTDR-X');
        expect(sor.origin).toBe('SRO-BPI-1234567');
        expect(sor.events.map((e) => e.code)).toEqual(['1F9999LS', '0F9999LS', '1E9999LS']);
        expect(sor.events[1]!.km).toBeCloseTo(2.2, 4);
        expect(sor.events[1]!.loss).toBe(0.12);
        expect(sor.sampleKm).toBeCloseTo(0.000638, 6);
    });

    it('unreadable files give a translatable warning', () => {
        const error = (() => {
            try {
                readSor('bad.sor', new Uint8Array(32));
            } catch (e) {
                return e;
            }

            return null;
        })();
        expect(error).toBeInstanceOf(SorFormatError);
        expect((error as SorFormatError).detail).toEqual({ key: 'file_unreadable_unknown_format', params: { file: 'bad.sor' } });
        const truncated = distributionTrace().slice(0, 400);
        const d = analyze([file(`${CABLE}_001_OE_1310.sor`, truncated)]);
        expect(d.warnings[0]!.key).toMatch(/^file_unreadable/);
        expect(d.warnings[0]!.params.file).toBe(`${CABLE}_001_OE_1310.sor`);
    });

    it('file warnings: name, wavelength, duplicate, EO files; other files skipped silently', () => {
        const d = analyze([
            ...fiberFiles(1),
            file('notes.txt', new Uint8Array(4)),
            file('wrong-name.sor', distributionTrace()),
            file(`${CABLE}_003_OE_1625.sor`, buildSor({ wavelengthNm: 1625, events: [{ km: 0.5, loss: 0.2, code: '1F9999LS' }, { km: 4, loss: 0, code: '1E9999LS' }] })),
            file(`${CABLE}_004_OE_1550.sor`, distributionTrace(1310)),
            file(`${CABLE}-001_OE_1310.sor`, distributionTrace(1310)),
            file(`${CABLE}_001_EO_1310.sor`, distributionTrace(1310)),
        ]);
        expect(d.warnings.map((w) => w.key)).toEqual(['file_name_invalid', 'wavelength_ignored', 'wavelength_mismatch', 'duplicate_file', 'eo_files_ignored', ...d.warnings.slice(5).map((w) => w.key)]);
        expect(d.warnings[1]!.params).toEqual({ file: `${CABLE}_003_OE_1625.sor`, wavelength: 1625 });
        expect(d.warnings[2]!.params).toEqual({ file: `${CABLE}_004_OE_1550.sor`, name_wavelength: 1550, wavelength: 1310 });
        expect(d.warnings[3]!.params).toEqual({ file: `${CABLE}-001_OE_1310.sor`, original: `${CABLE}_001_OE_1310.sor` });
        expect(d.kind).toBe('distribution');
        expect(d.mode).toBe('unidirectional');
    });

    it('builds a distribution dossier: connector then splice, values to the thousandth', () => {
        const d = analyze(inputs, undefined, [], { completeAnalysis: false });
        const f = d.fibers.find((x) => x.fiber === 1 && x.lambda === 1310)!;
        expect(f.events.map((e) => [e.kind, e.value])).toEqual([['connector', 0.25], ['splice', 0.12]]);
        expect(f.sections).toHaveLength(2);
        expect(f.lengthM).toBeCloseTo(3600, 0);
        expect(d.acquisition).toMatchObject({ startSpools: 1, endSpools: 0, loop: false });
    });
});

describe('loop measurement', () => {
    const TRANSPORT = '9900000001_9900001_B1-T1-AA';
    // Launch spool, O connector of fiber a, splice, loop-back at the extremity, splice, O connector of fiber b, receive spool.
    const loopTrace = (loopBack: { km: number; loss: number }[]) => buildSor({
        lengthKm: 8,
        events: [
            { km: 1, loss: 0.3, code: '1F9999LS' }, { km: 2, loss: 0.05, code: '0F9999LS' },
            ...loopBack.map((e) => ({ ...e, code: '0F9999LS' })),
            { km: 4.5, loss: 0.06, code: '0F9999LS' }, { km: 5.5, loss: 0.25, code: '1F9999LS' }, { km: 6.5, loss: 0, code: '1E9999LS' },
        ],
    });
    const pair = (loopBack: { km: number; loss: number }[]) => [1, 2].map((n) => file(`${TRANSPORT}-00${n}_OE_1310.sor`, loopTrace(loopBack)));

    it('direct loop-back (odd number of events): deduced, joint listed on the first fiber of the pair only', () => {
        const d = analyze(pair([{ km: 3.25, loss: 0.1 }]), undefined, [], { completeAnalysis: false });
        expect(d.mode).toBe('loop');
        expect(d.acquisition.loopSpools).toBe(0);
        const [a, b] = [1, 2].map((n) => d.fibers.find((f) => f.fiber === n)!);
        expect(a!.events.map((e) => e.kind)).toEqual(['connector', 'splice', 'connector']);
        expect(a!.loopJoint).toBeUndefined();
        expect(b!.events.map((e) => e.kind)).toEqual(['connector', 'splice']);
        expect(b!.loopJoint).toMatchObject({ kind: 'connector', oe: 0.1, eo: 0.1 });
        // the joint still closes the last section and the total loss of the second fiber
        expect(b!.sections).toHaveLength(a!.sections.length);
        expect(b!.sections[1]!.lengthOe).toBeCloseTo(1250, -1);
        const txt = buildFiberCableTxt(d).split('\r\n');
        expect(txt.find((l) => l.startsWith(TXT.header.spools))!.split('\t')).toEqual([TXT.header.spools, '1', '0', '1', '', '2']);
        // spool flags of the measurements: launch, no loop-back spool, receive
        const info = txt.find((l) => l.startsWith(`${TRANSPORT}\t1\t1310\t${TRANSPORT}-001`))!.split('\t');
        expect(info.slice(51, 57)).toEqual(['-1', '-1', '0', '0', '-1', '-1']);
    });

    it('one loop-back spool (even number of events): deduced, each fiber keeps its extremity connector', () => {
        const d = analyze(pair([{ km: 3, loss: 0.1 }, { km: 3.5, loss: 0.12 }]), undefined, [], { completeAnalysis: false });
        expect(d.acquisition.loopSpools).toBe(1);
        expect(d.fibers.every((f) => f.events.length === 3 && !f.loopJoint)).toBe(true);
    });
});

describe('screen workflow', () => {
    it('treatments, light messages and suggestions', () => {
        const gdco = analyzeGdco(inputs, 'auto', [], DEFAULT_ACQUISITION);
        const standard = analyzeStandard(inputs, [], DEFAULT_ACQUISITION);
        expect(gdcoDossierForStandard(inputs, [], DEFAULT_ACQUISITION, standard)).toBe(standard);
        expect(gdcoDossierForStandard(inputs, [], { ...DEFAULT_ACQUISITION, totalLossOutput: false }, standard)).not.toBe(standard);
        expect(evaluateGdco(gdco).status).toBe('OK');
        expect(lightMessages('gdco', 2)).toEqual({ text: { key: 'light_gdco_2', params: {} }, hint: { key: 'light_gdco_hint_2', params: {} } });
        expect(lightMessages('standard', 1).hint).toBeNull();
        expect(suggestPm(gdco)).toBe('SRO-BPI-1234567');
        expect(suggestCableName(gdco)).toBe(CABLE);
    });

    it('trace view markers and manual addition', () => {
        const target = inputs[0]!;
        const added = tryAddEvent(target, [], 1.3, 'distribution');
        expect(added.override).toMatchObject({ action: 'add', event: 900, kind: 'splice' });
        const rows = traceEvents(target, [added.override!]);
        expect(rows.map((r) => r.marker.key)).toEqual(['trace_marker_otdr', 'trace_marker_added', 'trace_marker_otdr', 'trace_marker_end']);
        expect(rows[1]!.marker.params).toEqual({ number: 1 });
        const d = analyze([target], undefined, [added.override!]);
        const analyzed = traceEvents(target, [added.override!], d.traces);
        expect(analyzed.some((r) => r.added)).toBe(true);
        expect(tryAddEvent(target, [], 0.501, 'distribution').error?.key).toMatch(/^add_event_refused_/);
    });
});

describe('deliverables', () => {
    const texts = (data: Uint8Array) => {
        const files = unzipSync(data);

        return Object.entries(files).filter(([p]) => /\.(json|txt)$/.test(p)).map(([p, b]) => (p.endsWith('.json') || p.includes('Corrections') ? strFromU8(b) : new TextDecoder('utf-16le').decode(b)));
    };

    it('operator deliverables: .txt, DCO, archive with corrections; identity from the platform', async () => {
        configureProduct({ name: 'Acme Recette OTDR', version: '9.9.9', brand: 'Acme' });
        const d = analyzeGdco(inputs, 'auto', [], DEFAULT_ACQUISITION);
        const s = evaluateGdco(d);
        const info = { zone: 'ZONE', g2r: '1', pm: 'SRO-BPI-1234567', stit: 'STIT', technician: 'TECH', generatedAt: new Date(2026, 0, 2, 3, 4) };
        const txt = gdcoTxtDeliverable(d, '');
        expect(txt.name).toBe('SRO-BPI_Distribution.txt');
        expect(txt.mime).toBe('text/plain;charset=utf-16le');
        const dco = await dcoDeliverable(d, s, info);
        expect(dco).toMatchObject({ name: 'ZONE_SRO-BPI-1234567_Distribution_20260102_0304_OK.xlsx', mime: XLSX_MIME });
        expect(dco.data.length).toBeGreaterThan(1000);
        const failedRepairs = [{ cableId: CABLE, fiber: 1, event: 2, lambda: 1310 as const, lastValue: 0.12 }];
        const zip = await gdcoArchiveDeliverable({ dossier: d, summary: s, info, inputs, overrides: [{ file: inputs[0]!.name, event: 2, action: 'connector' }], failedRepairs });
        expect(zip.name).toBe('SRO-BPI-1234567_Distribution_livrables.zip');
        const paths = Object.keys(unzipSync(zip.data)).sort();
        expect(paths).toContain('SRO-BPI-1234567/Distribution/SOR/Corrections_Acme.json');
        expect(paths).toContain('SRO-BPI-1234567/Distribution/EchecsDeReprises/PVECHECS_SRO-BPI-1234567.xlsx');
        expect(paths.filter((p) => p.endsWith('.sor'))).toHaveLength(4);
        const all = texts(zip.data).join('\n');
        expect(all).toContain('Acme Recette OTDR (v 9.9.9)');
        expect(all).toContain('"format": "otdr-corrections"');
        expect(all).toContain('type forcé : connecteur');
        configureProduct({ name: 'Recette OTDR', version: '1.0.0', brand: 'Recette OTDR' });
    });

    it('standard deliverables: .txt, measurement dossier, archive', async () => {
        const p = PRESETS.find((x) => x.id === 'xpfibre-distribution')!;
        const d = analyzeStandard(inputs, [], DEFAULT_ACQUISITION);
        const s = evaluateStandard(d, p);
        const info = { name: 'CABLE/1', origin: d.origin, extremity: 'PBO' };
        expect(standardTxtDeliverable(d, info.name, p).name).toBe('CABLE_1.txt');
        const report = await measurementReportDeliverable(d, s, p, info);
        expect(report.name).toBe(`CABLE_1 ${s.status === 'OK' ? 'OK' : 'HN'}.xlsx`);
        const zip = await standardArchiveDeliverable({ dossier: d, summary: s, profile: p, info, inputs, overrides: [] });
        expect(zip.name).toBe('CABLE_1_mesures.zip');
        expect(Object.keys(unzipSync(zip.data)).filter((x) => x.startsWith('CABLE_1/SOR/'))).toHaveLength(4);
        expect(correctionsDeliverable(d, [], []).name).toBe('Corrections_Recette OTDR_SRO-BPI-1234567.json');
    });
});

describe('library sources', () => {
    const root = join(__dirname, '../../../resources/js/otdr');
    const sources = (dir: string): string[] => readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? sources(join(dir, e.name)) : e.name.endsWith('.ts') ? [join(dir, e.name)] : []));
    const code = sources(root).map((p) => readFileSync(p, 'utf8')).join('\n');
    const locales = ['en_GB', 'fr', 'es', 'pt', 'uk'].map((l) => readFileSync(join(__dirname, `../../../lang/${l}/otdr.php`), 'utf8'));

    it('every message key emitted by the library is translated in every locale', () => {
        const keys = new Set<string>();

        for (const m of code.matchAll(/message\('([a-z0-9_]+)'/g)) {
            keys.add(m[1]!);
        }

        for (const m of code.matchAll(/\b(indicator_[a-z0-9_]+):/g)) {
            keys.add(m[1]!);
        }

        for (const reason of ['out_of_trace', 'too_close_previous', 'too_close_next', 'section_too_short']) {
            keys.add(`add_event_refused_${reason}`);
            keys.add(`added_event_refused_${reason}`);
        }

        for (const k of ['connectors', 'splices', 'slopes', 'reflectances']) {
            keys.add(`criterion_standard_${k}`);
            keys.add(`criterion_standard_${k}_by_wavelength`);
        }

        for (const l of [0, 1, 2]) {
            keys.add(`light_gdco_${l}`);
            keys.add(`light_gdco_hint_${l}`);
            keys.add(`light_standard_${l}`);
        }

        keys.add('light_standard_hint_0');
        keys.add('light_standard_hint_2');

        for (const t of ['connector', 'splice', 'connector_reflectance', 'splice_reflectance', 'constraint', 'slope', 'length', 'total_loss', 'orl', 'connector_mean', 'splice_mean', 'slope_mean']) {
            keys.add(`anomaly_type_${t}`);
        }

        expect(keys.size).toBeGreaterThan(100);

        for (const key of keys) {
            for (const php of locales) {
                expect(php, key).toContain(`'${key}' =>`);
            }
        }
    });
});
