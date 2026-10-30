// Screen helpers of the OTDR acceptance: texts and number formatting, device storage, trace viewer geometry and the
// result panels, on invented traces.
import { describe, expect, it } from 'vitest';
import { analyzeGdco, analyzeStandard, cloneProfile, CUSTOM_PROFILE_ID, DEFAULT_ACQUISITION, evaluateGdco, evaluateStandard, PRESETS, readSor, traceEvents, type InputFile } from '../../../resources/js/otdr';
import { detailView, filesSummary, indicatorsView, lightView, repairsView, todoView } from '../../../resources/js/otdr/ui/results';
import {
    blankCustomProfile, loadAcquisition, loadCorrections, loadCustomProfile, loadGdcoFields, loadProfile, loadReportFields, loadTreatment, saveAcquisition,
    saveCorrections, saveCustomProfile, saveGdcoFields, saveProfileChoice, saveReportFields, saveTreatment, STORAGE_KEYS, type DeviceStorage,
} from '../../../resources/js/otdr/ui/storage';
import { intlLocale, parseDecimal, replaceParams, Translator } from '../../../resources/js/otdr/ui/texts';
import {
    automaticKind, axisTicks, clipSegment, decimate, defaultWindow, focusWindow, markerTone, panWindow, plotHeight, plotKm, plotWidth, rowKind, zoomWindow,
} from '../../../resources/js/otdr/ui/trace-model';
import { distributionTrace } from './sor-builder';

const CABLE = 'SRO-BPI-1234567_PBO001_B1-T1-MA';
const file = (name: string, bytes: Uint8Array): InputFile => ({ name, bytes });
const inputs = [1, 2].flatMap((n) => [file(`${CABLE}_00${n}_OE_1310.sor`, distributionTrace(1310)), file(`${CABLE}_00${n}_OE_1550.sor`, distributionTrace(1550, 0.4))]);

/** In-memory storage; `broken` throws like a browser with blocked site data. */
function memoryStorage(initial: Record<string, string> = {}, broken = false): DeviceStorage & { data: Record<string, string> } {
    const data = { ...initial };
    const guard = () => {
        if (broken) {
            throw new Error('SecurityError');
        }
    };

    return {
        data,
        getItem: (key) => (guard(), data[key] ?? null),
        setItem: (key, value) => {
            guard();
            data[key] = value;
        },
        removeItem: (key) => {
            guard();
            delete data[key];
        },
    };
}

describe('texts', () => {
    it('replaces Laravel placeholders, longest names first', () => {
        expect(replaceParams(':count / :count_total', { count: 2, count_total: 9 })).toBe('2 / 9');
    });

    it('formats message numbers for the locale', () => {
        const fr = new Translator({ messages: {
            criterion_detail_splices_over: ':count / :total hors seuil (:percent %)',
            failed_repair_status_over_limit: ':value dB > 0,70 dB',
            criterion_detail_total_length: ':length m',
            wavelength_ignored: ':file : :wavelength nm',
        } }, intlLocale('fr'));
        expect(fr.message({ key: 'criterion_detail_splices_over', params: { count: 3, total: 200, percent: 1.5 } })).toBe('3 / 200 hors seuil (1,5 %)');
        expect(fr.message({ key: 'failed_repair_status_over_limit', params: { value: 0.8 } })).toBe('0,800 dB > 0,70 dB');
        expect(fr.message({ key: 'criterion_detail_total_length', params: { length: 24000 } })).toBe('24 000 m');
        expect(fr.message({ key: 'wavelength_ignored', params: { file: 'a.sor', wavelength: 1625 } })).toBe('a.sor : 1625 nm');
        expect(fr.message({ key: 'unknown', params: {} })).toBe('messages.unknown');

        const en = new Translator({}, intlLocale('en_GB'));
        expect(en.fixed(0.1234, 3)).toBe('0.123');
        expect(en.inputValue(0.05)).toBe('0.05');
        expect(fr.inputValue(-72)).toBe('-72');
        expect(fr.inputValue(0.05)).toBe('0,05');
        expect(fr.indicator(12)).toBe('12');
        expect(fr.indicator(1.2)).toBe('1,20');
    });

    it('reads typed numbers with a comma or a dot', () => {
        expect(parseDecimal(' 0,3 ')).toBe(0.3);
        expect(parseDecimal('12.5')).toBe(12.5);
        expect(parseDecimal('')).toBeUndefined();
        expect(parseDecimal('abc')).toBeUndefined();
    });
});

describe('device storage', () => {
    it('reads back what it saved', () => {
        const storage = memoryStorage();
        const custom = blankCustomProfile();
        custom.thresholds[1310].splice = 0.2;
        custom.thresholds[1310].totalLossMax = 25;
        custom.active.totalLossMax = true;
        custom.constraint = null;
        custom.negativeAlarm = true;
        saveTreatment('gdco', storage);
        saveCustomProfile(custom, storage);
        saveProfileChoice(custom, storage);
        saveAcquisition({ ...DEFAULT_ACQUISITION, auto: false, loop: true, detection: { ...DEFAULT_ACQUISITION.detection, splice: 0.08 } }, storage);
        saveGdcoFields({ zone: 'Z', g2r: '', stit: '', technician: 'Tech', transportType: 'FTTE' }, storage);
        saveReportFields({ connection: 'R', projectManager: 'P', cableManufacturer: '', cableType: '', fiberManufacturer: '', fiberType: '' }, { totalLoss: false, orl: true, comparison: true, outOfLimits: true }, storage);

        expect(Object.keys(storage.data).every((key) => key.startsWith('otdr:'))).toBe(true);
        expect(loadTreatment(storage)).toBe('gdco');
        const profile = loadProfile(storage);
        expect(profile.id).toBe(CUSTOM_PROFILE_ID);
        expect(profile.thresholds[1310].splice).toBe(0.2);
        expect(profile.thresholds[1310].totalLossMax).toBe(25);
        expect(profile.active.totalLossMax).toBe(true);
        expect(profile.constraint).toBeNull();
        expect(profile.negativeAlarm).toBe(true);
        expect(loadAcquisition(storage)).toMatchObject({ auto: false, loop: true, startSpools: 1, detection: { splice: 0.08, reflectance: -72, end: 3 } });
        expect(loadGdcoFields(storage)).toEqual({ zone: 'Z', g2r: '', stit: '', technician: 'Tech', transportType: 'FTTE' });
        const report = loadReportFields(storage);
        expect(report.fields).toMatchObject({ connection: 'R', projectManager: 'P', cableType: '' });
        expect(report.options).toEqual({ totalLoss: false, orl: true, comparison: true, outOfLimits: true });
    });

    it('falls back to the defaults and never throws when the storage is unavailable', () => {
        const broken = memoryStorage({}, true);
        expect(loadTreatment(broken)).toBe('standard');
        expect(loadProfile(broken).id).toBe(PRESETS[0]!.id);
        expect(loadCustomProfile(broken).id).toBe(CUSTOM_PROFILE_ID);
        expect(loadAcquisition(broken)).toEqual(DEFAULT_ACQUISITION);
        expect(() => saveTreatment('gdco', broken)).not.toThrow();
        expect(() => saveCorrections('k', [{ file: 'a.sor', event: 2, action: 'ignore' }], [], broken)).not.toThrow();
        expect(loadCorrections('k', broken)).toBeNull();
        expect(loadTreatment(null)).toBe('standard');
    });

    it('keeps the corrections per file set and removes them when empty', () => {
        const storage = memoryStorage();
        saveCorrections('abc', [{ file: 'a.sor', event: 2, action: 'ignore' }], [], storage);
        expect(loadCorrections('abc', storage)?.overrides).toEqual([{ file: 'a.sor', event: 2, action: 'ignore' }]);
        saveCorrections('abc', [], [], storage);
        expect(storage.data[`${STORAGE_KEYS.corrections}abc`]).toBeUndefined();
    });

    it('never keeps the PM nor the total number of fibers', () => {
        const storage = memoryStorage();
        saveGdcoFields({ zone: 'Z', g2r: '1', stit: 'S', technician: 'T', transportType: 'FTTH', ...{ pm: 'SRO-BPI-1', totalFibers: '12' } } as never, storage);
        expect(JSON.parse(storage.data[STORAGE_KEYS.gdcoFields]!)).toEqual({ zone: 'Z', g2r: '1', stit: 'S', technician: 'T', transportType: 'FTTH' });
    });
});

describe('trace viewer geometry', () => {
    const sor = readSor('a.sor', distributionTrace());

    it('keeps the extremes of every column when decimating', () => {
        const data = new Float32Array([1, 5, 2, 8, 3, 4]);
        expect(decimate(data, 0, 6, 3)).toEqual([[0, 1, 5], [2, 2, 8], [4, 3, 4]]);
    });

    it('sizes the plot to its box', () => {
        expect(plotWidth(200)).toBe(280);
        expect(plotHeight(320)).toBe(220);
        expect(plotHeight(1000)).toBe(340);
    });

    it('moves the window: default, focus, zoom and pan stay on the trace', () => {
        const [x0, x1] = defaultWindow(sor);
        expect(x0).toBe(0);
        expect(x1).toBeGreaterThan(sor.events[sor.events.length - 1]!.km);
        expect(focusWindow(sor, 0.05)).toEqual([0, expect.closeTo(0.2, 6)]);
        const zoomed = zoomWindow(sor, [1, 2], 0.5);
        expect(zoomed[0]).toBeCloseTo(1.25, 6);
        expect(zoomed[1]).toBeCloseTo(1.75, 6);
        expect(panWindow(sor, [0, 1], -0.5)).toEqual([0, 1]);
    });

    it('converts plot positions and ticks', () => {
        expect(plotKm([0, 1], 564, 30)).toBeNull();
        expect(plotKm([0, 1], 564, 56)).toBe(0);
        expect(plotKm([0, 1], 564, 556)).toBeCloseTo(1, 6);
        expect(axisTicks([0, 1], 900)).toHaveLength(11);
        expect(clipSegment([[0, 1], [2, 3]], [1, 1.5])).toEqual([[1, 2], [1.5, 2.5]]);
    });

    it('gives each event its kind and colour family', () => {
        const rows = traceEvents(file('a.sor', distributionTrace()), [{ file: 'a.sor', event: 2, action: 'connector' }]);
        expect(automaticKind(rows[0]!, 0)).toBe('connector');
        expect(rowKind(rows[1]!, 1)).toBe('connector');
        expect(markerTone(rows[1]!, 1, 'distribution')).toBe('connector');
        expect(markerTone(rows[1]!, 1, 'transport')).toBe('event');
        expect(markerTone(rows[rows.length - 1]!, rows.length - 1, 'distribution')).toBe('end');
    });
});

describe('result panels', () => {
    const t = new Translator({}, intlLocale('fr'));

    it('standard treatment: summary line, light, indicators and detail', () => {
        const d = analyzeStandard(inputs, [], { ...DEFAULT_ACQUISITION, completeAnalysis: false });
        const s = evaluateStandard(d, cloneProfile(PRESETS[0]!));
        expect(filesSummary(t, d, 4)).toContain('origin');
        expect(lightView(t, 'standard', s).dotClass).toBe(`otdr__dot otdr__level-${s.light}`);
        expect(indicatorsView(t, s, CUSTOM_PROFILE_ID)[0]).toEqual({ key: 'indicator_profile', label: 'messages.indicator_profile', value: 'config.custom_profile_name' });
        const [group] = detailView(t, d, s);
        expect(group!.name).toBe('PBO001');
        expect(group!.defaultOpen).toBe(true);
        expect(group!.headers.map((h) => h.key)).toEqual(['0', '1']);
        const splice1550 = group!.rows.find((r) => r.lambda === 1550)!.cells[1]!;
        expect(splice1550.text).toBe('0,400');
        expect(splice1550.className).toBe('otdr__value--out');
    });

    it('operator treatment: to-do list with the failed repair action, then neutralised rows', () => {
        const d = analyzeGdco(inputs, 'auto', [], { ...DEFAULT_ACQUISITION, completeAnalysis: false });
        const s = evaluateGdco(d);
        const todo = todoView(t, d, s, [], 'gdco');
        expect(todo.count).toBeGreaterThan(0);
        const splice = todo.items.find((i) => i.canAddFailedRepair)!;
        expect(splice.value).toBe('results.value');
        expect(splice.className).toBe('otdr__todo-item');
        expect(todoView(t, d, s, [], 'standard').items.some((i) => i.canAddFailedRepair)).toBe(false);

        const anomaly = s.anomalies[splice.index]!;
        const repairs = [{ cableId: anomaly.cableId, fiber: anomaly.fiber, event: anomaly.index, lambda: anomaly.lambda!, lastValue: anomaly.value, startValue: anomaly.value }];
        const neutralized = evaluateGdco(d, { failedRepairs: repairs });
        expect(repairsView(t, repairs, neutralized)[0]!.ok).toBe(true);
        expect(todoView(t, d, neutralized, repairs, 'gdco').items.find((i) => i.index === splice.index)!.className).toBe('otdr__todo-item is-neutralized');
    });
});
