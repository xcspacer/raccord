// « Recette OTDR » screen: the Alpine component driving the measurement library. Everything is read and computed on
// the device; nothing is sent anywhere. The state lives at module level so that it survives the in-app navigation
// (the component is rebuilt from it when the page is opened again), like the previous tool that was never unmounted.
import {
    addFailedRepair, analyzeGdco, analyzeStandard, cloneProfile, configureProduct, correctionsDeliverable, correctionsImportMessages, CUSTOM_PROFILE_ID,
    dcoDeliverable, DEFAULT_DETECTION, dossierKey, evaluateGdco, evaluateStandard, failedRepairsDeliverable, failedRepairsPm, gdcoArchiveDeliverable,
    gdcoDossierForStandard, gdcoTxtDeliverable, importFailedRepairsFile, loadExcelJS, measurementReportDeliverable, mergeFailedRepairs, message, OtdrError,
    parseCorrections, parseRopCsv, pboOf, PRESETS, readInputFiles, readSor, removeFailedRepair, standardArchiveDeliverable, standardTxtDeliverable,
    suggestCableName, suggestExtremity, suggestPm, suggestTechnician, traceEvents, tryAddEvent, upsertOverride,
    type AcquisitionConfig, type DcoInfo, type DetectionSettings, type Dossier, type EventKind, type EventOverride, type FailedRepair, type FileOutput,
    type InputFile, type KindChoice, type Lambda, type Message, type NamedBytes, type Profile, type ReportOptions, type RopSummary, type SorFile,
    type Spools, type Summary, type ThresholdKey, type TraceEventRow, type Treatment,
} from '../index';
import { CUSTOM_PROFILE_NAME, productName } from '../export/format';
import { component, raw } from './alpine';
import { saveFile } from './download';
import {
    anomalyTarget, criteriaView, detailView, fiberKey, filesSummary, indicatorsView, lightView, repairKey, repairsView, todoView,
    type AnomalyView, type CriterionView, type DetailGroup, type DetailRow, type LightView, type RepairView,
} from './results';
import {
    loadAcquisition, loadCorrections, loadCustomProfile, loadGdcoFields, loadProfile, loadReportFields, loadTreatment, saveAcquisition, saveCorrections,
    saveCustomProfile, saveGdcoFields, saveProfileChoice, saveReportFields, saveTreatment, type ReportFields,
} from './storage';
import { drawAcquisitionDiagram, drawIcon, drawTrace, type IconKind } from './svg';
import { intlLocale, parseDecimal, Translator, type TextTree } from './texts';
import {
    axisTicks, defaultWindow, eventMarkers, focusWindow, markerTone, panWindow, plotGeometry, plotHeight, plotKm, plotWidth, rowKind, automaticKind,
    zoomWindow, type PlotWindow,
} from './trace-model';

type ConfigTab = 'otdr' | 'detection' | 'thresholds' | 'misc';

interface TraceState {
    key: string;
    /** Event to show first (number in the first file). */
    focus: number | null;
    tab: number;
    window: PlotWindow | null;
    selected: number | null;
    adding: boolean;
    addAt: string;
    error: string;
}

interface GdcoFieldValues {
    zone: string;
    g2r: string;
    pm: string;
    stit: string;
    technician: string;
    transportType: 'FTTH' | 'FTTE';
    totalFibers: string;
}

type ReportValues = { name: string; origin: string; extremity: string } & ReportFields;

interface Views {
    filesSummary: string;
    light: LightView;
    criteria: CriterionView[];
    todo: { count: number; items: AnomalyView[] };
    indicators: { key: string; label: string; value: string }[];
    detail: DetailGroup[];
    warnings: string[];
    repairs: RepairView[];
    gdcoExportSummary: string;
}

const EMPTY_LIGHT: LightView = { dotClass: 'otdr__dot', text: '', status: '', hint: '' };

const emptyViews = (): Views => ({
    filesSummary: '', light: EMPTY_LIGHT, criteria: [], todo: { count: 0, items: [] }, indicators: [], detail: [], warnings: [], repairs: [], gdcoExportSummary: '',
});

/** Heavy data kept out of Alpine's reactivity (files, dossiers, curves) and the screen state between visits. */
const session = {
    inputs: [] as InputFile[],
    inputsVersion: 0,
    storeKey: null as string | null,
    signature: '',
    dossier: null as Dossier | null,
    gdcoDossier: null as Dossier | null,
    summary: null as Summary | null,
    gdcoSummary: null as Summary | null,
    views: emptyViews(),
    groupDefaults: {} as Record<string, boolean>,
    sors: new Map<string, SorFile>(),
    traceRows: null as { revision: number; key: string; tab: number; rows: TraceEventRow[] } | null,
    rop: null as NamedBytes | null,
    preRepair: [] as NamedBytes[],
    gdcoKey: '',
    reportKey: '',
    resizeObserver: null as ResizeObserver | null,
    state: null as Record<string, unknown> | null,
};

/** Reactive fields kept when the page is left and opened again. */
const KEPT_FIELDS = [
    'treatment', 'kindChoice', 'acquisition', 'profile', 'configTab', 'nroPmLength', 'nroPmAttenuation', 'overrides', 'failedRepairs', 'correctionsNotice',
    'openGroups', 'trace', 'repairsMessages', 'gdcoFields', 'ropSummary', 'ropFileName', 'preRepairCount', 'gdcoError', 'reportValues', 'reportOptions',
    'reportError', 'gdcoExportOpen',
] as const;

let translator = new Translator({}, 'en-GB');
let platform = '';

/** Screen texts, locale and platform name handed over by the page. */
export function setup(texts: TextTree, locale: string, platformName: string): void {
    translator = new Translator(texts, locale);
    platform = platformName;
    configureProduct({ name: productName(platformName), brand: platformName });
}

const t = (key: string, params: Record<string, string | number> = {}) => translator.t(key, params);

/** Deep-freezes view models so that Alpine does not wrap them in reactive proxies (large tables). */
function frozen<T>(value: T): T {
    if (value && typeof value === 'object' && !Object.isFrozen(value)) {
        Object.freeze(value);

        for (const child of Object.values(value)) {
            frozen(child);
        }
    }

    return value;
}

const inputOf = (event: Event) => event.target as HTMLInputElement;

/** Text of a failure: translated library message, ExcelJS unavailable, or the error itself. */
function errorText(error: unknown): string {
    if (error instanceof OtdrError) {
        return translator.message(error.detail);
    }

    return error instanceof Error ? error.message : String(error);
}

/** ExcelJS is fetched on demand: when that fails (no network yet), a clear message is shown. */
async function excelReady(): Promise<void> {
    try {
        await loadExcelJS();
    } catch {
        throw new Error(t('excel_unavailable'));
    }
}

const SPOOLS: Spools[] = [0, 1, 2, 3];

const DETECTION_ROWS: [keyof DetectionSettings, string][] = [['splice', 'detection_splice'], ['reflectance', 'detection_reflectance'], ['end', 'detection_end']];

const THRESHOLD_GROUPS: { key: string; rows: [ThresholdKey, string][] }[] = [
    { key: 'group_means', rows: [['connector', 'threshold_connectors'], ['splice', 'threshold_splices'], ['slope', 'threshold_slopes'], ['reflectance', 'threshold_reflectances']] },
    { key: 'group_fiber_means', rows: [['fiberConnector', 'threshold_connectors'], ['fiberSplice', 'threshold_splices'], ['fiberSlope', 'threshold_slopes']] },
    { key: 'group_other', rows: [['totalLossMin', 'threshold_total_loss_min'], ['totalLossMax', 'threshold_total_loss_max'], ['orl', 'threshold_orl']] },
];

const REPORT_FIELDS: [keyof ReportValues, string][] = [
    ['name', 'name'], ['origin', 'origin'], ['extremity', 'extremity'], ['connection', 'connection'], ['projectManager', 'project_manager'],
    ['cableManufacturer', 'cable_manufacturer'], ['cableType', 'cable_type'], ['fiberManufacturer', 'fiber_manufacturer'], ['fiberType', 'fiber_type'],
];

const REPORT_OPTIONS: [keyof ReportOptions, string][] = [['comparison', 'comparison'], ['totalLoss', 'total_loss'], ['orl', 'orl'], ['outOfLimits', 'out_of_limits']];

const GDCO_REQUIRED: [keyof GdcoFieldValues, string][] = [['zone', 'missing_zone'], ['pm', 'missing_pm'], ['stit', 'missing_stit'], ['technician', 'missing_technician']];

function acquisitionSummary(c: AcquisitionConfig): string {
    if (!c.loop) {
        return t('config.summary_classic', { start: c.startSpools, end: c.endSpools });
    }

    return t('config.summary_loop', {
        start: c.startSpools, end: c.endSpools, loop: c.loopSpools === 0 ? t('config.loop_direct') : t('config.loop_spools_count', { count: c.loopSpools }),
    });
}

export const otdrScreen = () => component({
    treatment: 'standard' as Treatment,
    kindChoice: 'auto' as KindChoice,
    acquisition: loadAcquisition(),
    profile: loadProfile(),
    configTab: 'otdr' as ConfigTab,
    detectionDraft: {} as Record<string, string>,
    thresholdDraft: {} as Record<string, string>,
    nroPmLength: '',
    nroPmAttenuation: '',
    progress: null as { done: number; total: number } | null,
    dragOver: false,
    overrides: [] as EventOverride[],
    failedRepairs: [] as FailedRepair[],
    correctionsNotice: '',
    openGroups: {} as Record<string, boolean>,
    trace: null as TraceState | null,
    traceWidth: 800,
    repairsMessages: [] as string[],
    repairsBusy: false,
    gdcoFields: { zone: '', g2r: '', pm: '', stit: '', technician: '', transportType: 'FTTH', totalFibers: '' } as GdcoFieldValues,
    ropSummary: null as RopSummary | null,
    ropFileName: '',
    preRepairCount: 0,
    gdcoBusy: false,
    gdcoError: '',
    gdcoExportOpen: false,
    reportValues: { name: '', origin: '', extremity: '', ...loadReportFields().fields } as ReportValues,
    reportOptions: loadReportFields().options,
    reportBusy: false,
    reportError: '',
    revision: 0,

    init(): void {
        if (session.state) {
            Object.assign(this, session.state);
            session.state = null;
        } else {
            this.treatment = loadTreatment();
        }

        this.refresh();
    },

    destroy(): void {
        const kept: Record<string, unknown> = {};

        for (const field of KEPT_FIELDS) {
            kept[field] = raw(this[field]);
        }

        session.state = kept;
        session.resizeObserver?.disconnect();
        session.resizeObserver = null;
    },

    // ------------------------------------------------------------------ computation

    /** Analyses the files again when needed, evaluates the controls and rebuilds what the panels show. */
    refresh(): void {
        const s = session;
        const gdco = this.treatment === 'gdco';

        if (!s.inputs.length) {
            s.signature = '';
            s.dossier = s.gdcoDossier = s.summary = s.gdcoSummary = null;
        } else {
            const acquisition = raw(this.acquisition);
            const overrides = raw(this.overrides);
            const signature = JSON.stringify([s.inputsVersion, this.treatment, gdco ? this.kindChoice : '', acquisition, overrides]);

            if (signature !== s.signature) {
                s.signature = signature;
                s.dossier = gdco ? analyzeGdco(s.inputs, this.kindChoice, overrides, acquisition) : analyzeStandard(s.inputs, overrides, acquisition);
                s.gdcoDossier = gdco ? s.dossier : gdcoDossierForStandard(s.inputs, overrides, acquisition, s.dossier);
            }

            s.gdcoSummary = evaluateGdco(s.gdcoDossier!, {
                nroPmLengthM: parseDecimal(this.nroPmLength), nroPmAttenuation1310: parseDecimal(this.nroPmAttenuation), failedRepairs: raw(this.failedRepairs),
            });
            s.summary = gdco ? s.gdcoSummary : evaluateStandard(s.dossier!, raw(this.profile));
        }

        this.buildViews();
        this.syncDeliverables();
        this.revision++;
    },

    buildViews(): void {
        const s = session;
        const d = s.dossier;
        const summary = s.summary;
        const views = emptyViews();

        if (d && summary) {
            views.filesSummary = filesSummary(translator, d, s.inputs.length);
            views.light = lightView(translator, this.treatment, summary);
            views.criteria = criteriaView(translator, summary);
            views.todo = todoView(translator, d, summary, raw(this.failedRepairs), this.treatment);
            views.indicators = indicatorsView(translator, summary, this.profile.id);
            views.detail = detailView(translator, d, summary);
            views.warnings = d.warnings.map((m) => translator.message(m));
            views.repairs = repairsView(translator, raw(this.failedRepairs), summary);
        }

        if (s.gdcoSummary) {
            views.gdcoExportSummary = t('gdco_export.summary', { light: translator.message(message(`light_gdco_${s.gdcoSummary.light}`)), status: s.gdcoSummary.status });
        }

        const defaults: Record<string, boolean> = {};
        const open = { ...this.openGroups };

        for (const group of views.detail) {
            defaults[group.key] = group.defaultOpen;

            if (s.groupDefaults[group.key] !== group.defaultOpen) {
                open[group.key] = group.defaultOpen;
            }
        }

        s.groupDefaults = defaults;
        this.openGroups = open;
        s.views = frozen(views);
    },

    /** Deliverable fields start again for a new dossier (suggestions from the files, values kept on the device). */
    syncDeliverables(): void {
        const s = session;
        const g = s.gdcoDossier;
        const gdcoKey = g && g.fibers.length ? `${this.treatment}|${g.kind}|${g.origin}|${s.inputs.length}` : '';

        if (gdcoKey !== s.gdcoKey) {
            s.gdcoKey = gdcoKey;
            s.rop = null;
            s.preRepair = [];
            this.ropSummary = null;
            this.ropFileName = '';
            this.preRepairCount = 0;
            this.gdcoError = '';

            if (g && gdcoKey) {
                const saved = loadGdcoFields();
                this.gdcoFields = {
                    zone: saved.zone ?? '',
                    g2r: g.kind === 'transport' ? g.origin : (saved.g2r ?? ''),
                    pm: suggestPm(g),
                    stit: saved.stit ?? '',
                    technician: suggestTechnician(g) || saved.technician || '',
                    transportType: saved.transportType ?? 'FTTH',
                    totalFibers: '',
                };
                saveGdcoFields(this.gdcoFields);
            }
        }

        const d = s.dossier;
        const reportKey = d && d.fibers.length && this.treatment === 'standard' ? `${d.kind}|${d.origin}|${s.inputs.length}` : '';

        if (reportKey !== s.reportKey) {
            s.reportKey = reportKey;
            this.reportError = '';

            if (d && reportKey) {
                const saved = loadReportFields();
                this.reportValues = { name: suggestCableName(d), origin: d.origin, extremity: suggestExtremity(d), ...saved.fields };
                this.reportOptions = saved.options;
                this.saveReport();
            }
        }
    },

    get views(): Views {
        void this.revision;

        return session.views;
    },

    get hasDossier(): boolean {
        void this.revision;

        return session.dossier !== null && session.summary !== null;
    },

    get hasFibers(): boolean {
        void this.revision;

        return (session.dossier?.fibers.length ?? 0) > 0;
    },

    get noFibers(): boolean {
        return this.hasDossier && !this.hasFibers;
    },

    get isGdco(): boolean {
        return this.treatment === 'gdco';
    },

    get isStandard(): boolean {
        return this.treatment === 'standard';
    },

    get isDistribution(): boolean {
        void this.revision;

        return session.dossier?.kind === 'distribution';
    },

    get isBidirectional(): boolean {
        void this.revision;

        return session.dossier?.kind === 'transport';
    },

    // ------------------------------------------------------------------ treatment and files

    setTreatment(value: Treatment): void {
        this.treatment = value;
        saveTreatment(value);
        this.refresh();
    },

    chooseFiles(): void {
        this.$refs.files?.click();
    },

    chooseFolder(): void {
        this.$refs.folder?.click();
    },

    onFiles(event: Event): void {
        const input = inputOf(event);
        void this.load(Array.from(input.files ?? []));
        input.value = '';
    },

    onDragOver(event: DragEvent): void {
        event.preventDefault();
        this.dragOver = true;
    },

    onDragLeave(): void {
        this.dragOver = false;
    },

    onDrop(event: DragEvent): void {
        event.preventDefault();
        this.dragOver = false;
        void this.load(Array.from(event.dataTransfer?.files ?? []));
    },

    get reading(): boolean {
        return this.progress !== null;
    },

    paintProgress(element: HTMLElement): void {
        const p = this.progress;
        element.style.width = p && p.total ? `${(100 * p.done) / p.total}%` : '0%';
    },

    async load(files: File[]): Promise<void> {
        if (!files.length) {
            return;
        }

        const total = files.length;
        this.progress = { done: 0, total };
        const read = await readInputFiles(files, (done) => {
            this.progress = { done, total };
        });
        this.progress = null;
        this.closeTrace();
        const key = read.length ? dossierKey(read) : null;
        const saved = key ? loadCorrections(key) : null;
        this.overrides = saved?.overrides ?? [];
        this.failedRepairs = saved?.failedRepairs ?? [];

        // A new set of measurements: the OTDR setting is deduced from its files (a manual setting of another site does not apply).
        if (!this.acquisition.auto) {
            this.setAcquisition({ ...raw(this.acquisition), auto: true }, false);
        }

        this.correctionsNotice = saved
            ? t('corrections.restored', { overrides: saved.overrides.length, failed_repairs: saved.failedRepairs.length, date: translator.dateTime(saved.savedAt) })
            : '';
        session.inputs = read;
        session.inputsVersion++;
        session.storeKey = key;
        session.sors.clear();
        this.saveCorrectionsOnDevice();
        this.refresh();
    },

    // ------------------------------------------------------------------ step 2: operator dossier type

    setKind(value: KindChoice): void {
        this.kindChoice = value;
        this.refresh();
    },

    setNroPm(field: 'length' | 'attenuation', event: Event): void {
        if (field === 'length') {
            this.nroPmLength = inputOf(event).value;
        } else {
            this.nroPmAttenuation = inputOf(event).value;
        }

        this.refresh();
    },

    get showNroPm(): boolean {
        return this.isGdco && this.isDistribution;
    },

    // ------------------------------------------------------------------ step 2: standard setup

    setAcquisition(value: AcquisitionConfig, refresh = true): void {
        this.acquisition = value;
        saveAcquisition(value);

        if (refresh) {
            this.refresh();
        }
    },

    /** Setting shown: the one applied (deduced from the files) in automatic mode. */
    get shownAcquisition(): AcquisitionConfig {
        void this.revision;

        return this.acquisition.auto && session.dossier ? session.dossier.acquisition : this.acquisition;
    },

    drawSchema(svg: SVGSVGElement): void {
        drawAcquisitionDiagram(svg, this.shownAcquisition, {
            classic: t('schema.classic'), loop: t('schema.loop'), otdr: t('schema.otdr'), cable: t('schema.cable'), fiberEnd: t('schema.fiber_end'),
            direction: t('schema.direction'), fiberN: t('schema.fiber_n'), fiberNext: t('schema.fiber_next'), fiberPair: t('schema.fiber_pair'), end: t('schema.end'),
        });
    },

    paintIcon(svg: SVGSVGElement, kind: IconKind, value: string | number | boolean): void {
        drawIcon(svg, kind, value);
    },

    setAuto(event: Event): void {
        const checked = inputOf(event).checked;
        this.setAcquisition(checked ? { ...raw(this.acquisition), auto: true } : { ...raw(this.shownAcquisition), auto: false });
    },

    get appliedSummary(): string {
        void this.revision;

        return this.acquisition.auto && session.dossier ? t('config.applied', { summary: acquisitionSummary(session.dossier.acquisition) }) : '';
    },

    setConfigTab(tab: ConfigTab): void {
        this.configTab = tab;
        this.detectionDraft = {};
        this.thresholdDraft = {};
    },

    /** Any change of a setting switches to manual, starting from what is shown. */
    choose(field: 'startSpools' | 'endSpools' | 'loopSpools' | 'loop' | 'loopDirection' | 'sequence', value: string | number | boolean): void {
        this.setAcquisition({ ...raw(this.shownAcquisition), [field]: value, auto: false } as AcquisitionConfig);
    },

    spoolChoices(field: 'startSpools' | 'endSpools' | 'loopSpools'): { key: string; value: number; label: string; pressed: boolean; className: string; icon: IconKind }[] {
        const shown = this.shownAcquisition;
        const star = field === 'endSpools' ? 0 : 1;

        return SPOOLS.map((n) => ({
            key: `${field}-${n}`, value: n, label: `${n}${n === star ? ' *' : ''}`, pressed: shown[field] === n,
            className: shown[field] === n ? 'otdr__choice is-on' : 'otdr__choice', icon: field === 'loopSpools' ? 'loop' : 'spools',
        }));
    },

    get measureChoices(): { key: string; value: boolean; label: string; pressed: boolean; className: string }[] {
        const loop = this.shownAcquisition.loop;

        return [
            { key: 'classic', value: false, label: `${t('config.classic')} *`, pressed: !loop, className: !loop ? 'otdr__choice is-on' : 'otdr__choice' },
            { key: 'loop', value: true, label: t('config.loop'), pressed: loop, className: loop ? 'otdr__choice is-on' : 'otdr__choice' },
        ];
    },

    get directionChoices(): { key: string; value: string; label: string; pressed: boolean; className: string }[] {
        const current = this.shownAcquisition.loopDirection;

        return ([['OE', 'direction_oe', true], ['EO', 'direction_eo', false], ['OE+EO', 'direction_both', false]] as const).map(([value, label, star]) => ({
            key: value, value, label: `${t(`config.${label}`)}${star ? ' *' : ''}`, pressed: current === value, className: current === value ? 'otdr__choice is-on' : 'otdr__choice',
        }));
    },

    get sequenceChoices(): { key: string; value: string; label: string; pressed: boolean; className: string }[] {
        const current = this.shownAcquisition.sequence;

        return ([['n+1', 'sequence_next', true], ['n,n', 'sequence_pairs', false]] as const).map(([value, label, star]) => ({
            key: value, value, label: `${t(`config.${label}`)}${star ? ' *' : ''}`, pressed: current === value, className: current === value ? 'otdr__choice is-on' : 'otdr__choice',
        }));
    },

    get isLoop(): boolean {
        return this.shownAcquisition.loop;
    },

    get sequenceHint(): string {
        return t(this.shownAcquisition.sequence === 'n+1' ? 'config.sequence_next_hint' : 'config.sequence_pairs_hint');
    },

    // Trace analysis

    setCompleteAnalysis(event: Event): void {
        this.setAcquisition({ ...raw(this.acquisition), completeAnalysis: inputOf(event).checked });
    },

    get detectionRows(): { key: string; label: string; hint: string; value: string }[] {
        return DETECTION_ROWS.map(([key, label]) => ({
            key, label: t(`config.${label}`), hint: t(`config.${label}_hint`), value: this.detectionDraft[key] ?? translator.inputValue(this.acquisition.detection[key]),
        }));
    },

    setDetection(key: keyof DetectionSettings, event: Event): void {
        const text = inputOf(event).value;
        this.detectionDraft = { ...this.detectionDraft, [key]: text };
        const value = parseDecimal(text);

        if (value !== undefined) {
            const current = raw(this.acquisition);
            this.setAcquisition({ ...current, detection: { ...current.detection, [key]: value } });
        }
    },

    get detectionIsDefault(): boolean {
        return DETECTION_ROWS.every(([key]) => this.acquisition.detection[key] === DEFAULT_DETECTION[key]);
    },

    get detectionResetLabel(): string {
        return t('config.detection_reset', { values: DETECTION_ROWS.map(([key]) => translator.inputValue(DEFAULT_DETECTION[key])).join(' / ') });
    },

    resetDetection(): void {
        this.detectionDraft = {};
        this.setAcquisition({ ...raw(this.acquisition), detection: { ...DEFAULT_DETECTION } });
    },

    // Alarm thresholds

    setProfile(profile: Profile): void {
        this.profile = profile;
        saveProfileChoice(profile);
        this.refresh();
    },

    get profileOptions(): { value: string; label: string; selected: boolean }[] {
        return [
            ...PRESETS.map((p) => ({ value: p.id, label: p.name, selected: this.profile.id === p.id })),
            { value: CUSTOM_PROFILE_ID, label: t('config.custom_profile'), selected: this.profile.id === CUSTOM_PROFILE_ID },
        ];
    },

    selectProfile(event: Event): void {
        const id = (event.target as HTMLSelectElement).value;
        this.thresholdDraft = {};
        const preset = PRESETS.find((p) => p.id === id);
        this.setProfile(id === CUSTOM_PROFILE_ID || !preset ? loadCustomProfile() : cloneProfile(preset));
    },

    /** Any change of a threshold creates (or updates) the custom profile. */
    changeProfile(change: (p: Profile) => void): void {
        const p = cloneProfile(raw(this.profile), CUSTOM_PROFILE_ID, CUSTOM_PROFILE_NAME);
        change(p);
        saveCustomProfile(p);
        this.setProfile(p);
    },

    get thresholdRows(): { key: string; group: boolean; label: string; aria: string; active: boolean; v1310: string; v1550: string; className: string }[] {
        const p = this.profile;
        const value = (lambda: Lambda, key: ThresholdKey) => this.thresholdDraft[`${lambda}.${key}`] ?? translator.inputValue(p.thresholds[lambda][key]);

        return THRESHOLD_GROUPS.flatMap(({ key, rows }) => [
            { key, group: true, label: t(`config.${key}`), aria: '', active: true, v1310: '', v1550: '', className: '' },
            ...rows.map(([threshold, label]) => ({
                key: threshold, group: false, label: t(`config.${label}`), aria: t('config.threshold_active', { label: t(`config.${label}`) }),
                active: p.active[threshold], v1310: value(1310, threshold), v1550: value(1550, threshold), className: p.active[threshold] ? '' : 'is-inactive',
            })),
        ]);
    },

    setThresholdActive(key: ThresholdKey, event: Event): void {
        const checked = inputOf(event).checked;
        this.changeProfile((p) => {
            p.active[key] = checked;
        });
    },

    setThreshold(lambda: Lambda, key: ThresholdKey, event: Event): void {
        const text = inputOf(event).value;
        this.thresholdDraft = { ...this.thresholdDraft, [`${lambda}.${key}`]: text };
        const value = parseDecimal(text);

        if (value !== undefined) {
            this.changeProfile((p) => {
                p.thresholds[lambda][key] = value;
            });
        }
    },

    setNegativeAlarm(event: Event): void {
        const checked = inputOf(event).checked;
        this.changeProfile((p) => {
            p.negativeAlarm = checked;
        });
    },

    get constraintEnabled(): boolean {
        return this.profile.constraint !== null;
    },

    get constraintValue(): string {
        return this.thresholdDraft.constraint ?? translator.inputValue(this.profile.constraint);
    },

    setConstraintEnabled(event: Event): void {
        const checked = inputOf(event).checked;
        this.changeProfile((p) => {
            p.constraint = checked ? 0.2 : null;
        });
    },

    setConstraint(event: Event): void {
        const text = inputOf(event).value;
        this.thresholdDraft = { ...this.thresholdDraft, constraint: text };
        const value = parseDecimal(text);

        if (value !== undefined) {
            this.changeProfile((p) => {
                p.constraint = value;
            });
        }
    },

    // Miscellaneous

    setTotalLoss(field: 'totalLossInput' | 'totalLossOutput', event: Event): void {
        this.setAcquisition({ ...raw(this.acquisition), [field]: inputOf(event).checked });
    },

    // ------------------------------------------------------------------ corrections

    saveCorrectionsOnDevice(): void {
        if (session.storeKey) {
            saveCorrections(session.storeKey, raw(this.overrides), raw(this.failedRepairs));
        }
    },

    changeOverride(o: EventOverride, remove: boolean): void {
        this.overrides = upsertOverride(raw(this.overrides), o, remove);
        this.saveCorrectionsOnDevice();
        this.refresh();
    },

    setFailedRepairs(list: FailedRepair[]): void {
        this.failedRepairs = list;
        this.saveCorrectionsOnDevice();
        this.refresh();
    },

    get correctionsCount(): string {
        return t('corrections.count', { count: this.overrides.length });
    },

    get cannotExportCorrections(): boolean {
        return !this.overrides.length && !this.failedRepairs.length;
    },

    exportCorrections(): void {
        if (session.dossier) {
            saveFile(correctionsDeliverable(session.dossier, raw(this.overrides), raw(this.failedRepairs)));
        }
    },

    chooseCorrections(): void {
        this.$refs.corrections?.click();
    },

    async importCorrections(event: Event): Promise<void> {
        const input = inputOf(event);
        const file = input.files?.[0];
        input.value = '';

        if (!file) {
            return;
        }

        try {
            const result = parseCorrections(await file.text(), session.inputs.map((f) => f.name));
            this.overrides = result.overrides;
            this.failedRepairs = result.failedRepairs;
            this.correctionsNotice = correctionsImportMessages(result).map((m) => translator.message(m)).join(' ');
            this.saveCorrectionsOnDevice();
            this.refresh();
        } catch (error) {
            this.correctionsNotice = errorText(error);
        }
    },

    undoAllCorrections(): void {
        this.overrides = [];
        this.saveCorrectionsOnDevice();
        this.refresh();
    },

    // ------------------------------------------------------------------ failed repair report

    get showRepairs(): boolean {
        return this.isGdco && this.isDistribution && this.hasDossier;
    },

    get repairsHeading(): string {
        return t('failed_repairs.heading', { count: this.failedRepairs.length });
    },

    removeRepair(key: string): void {
        const row = raw(this.failedRepairs).find((p) => repairKey(p) === key);

        if (row) {
            this.setFailedRepairs(removeFailedRepair(raw(this.failedRepairs), row));
        }
    },

    async importRepairs(event: Event): Promise<void> {
        const input = inputOf(event);
        const file = input.files?.[0];
        input.value = '';

        if (!file) {
            return;
        }

        this.repairsBusy = true;

        try {
            await excelReady();
            const result = await importFailedRepairsFile(file.name, await file.arrayBuffer());
            this.setFailedRepairs(mergeFailedRepairs(raw(this.failedRepairs), result.failedRepairs));
            this.repairsMessages = [t('failed_repairs.imported', { count: result.failedRepairs.length, file: file.name }), ...result.errors.map((m: Message) => translator.message(m))];
        } catch (error) {
            this.repairsMessages = [errorText(error)];
        } finally {
            this.repairsBusy = false;
        }
    },

    async downloadRepairs(): Promise<void> {
        if (!session.summary) {
            return;
        }

        try {
            await excelReady();
            saveFile(await failedRepairsDeliverable(raw(this.failedRepairs), failedRepairsPm(raw(this.failedRepairs), session.summary)));
        } catch (error) {
            this.repairsMessages = [errorText(error)];
        }
    },

    // ------------------------------------------------------------------ results

    toggleGroup(key: string, event: Event): void {
        const open = (event.target as HTMLDetailsElement).open;

        if (this.openGroups[key] !== open) {
            this.openGroups = { ...this.openGroups, [key]: open };
        }
    },

    isGroupOpen(key: string): boolean {
        return this.openGroups[key] === true;
    },

    get todoHeading(): string {
        return t('results.todo', { count: this.views.todo.count });
    },

    get detailHeading(): string {
        return t(this.isBidirectional ? 'results.detail_cable' : 'results.detail_pbo');
    },

    viewAnomalyTrace(index: number): void {
        const a = session.summary?.anomalies[index];

        if (!a || !session.dossier) {
            return;
        }

        const target = anomalyTarget(session.dossier, a);

        if (target.fiber) {
            this.openTrace(fiberKey(target.fiber), target.eventNum);
        }
    },

    addAnomalyRepair(index: number): void {
        const a = session.summary?.anomalies[index];

        if (a) {
            this.setFailedRepairs(addFailedRepair(raw(this.failedRepairs), a));
        }
    },

    openRowTrace(row: DetailRow): void {
        this.openTrace(`${row.cableId}|${row.fiber}|${row.lambda}`);
    },

    // ------------------------------------------------------------------ trace viewer

    openTrace(key: string, focus?: number): void {
        session.traceRows = null;
        const first = this.traceFilesFor(key)[0];
        const event = focus !== undefined && first ? this.sorOf(first).events.find((e) => e.num === focus) : undefined;
        this.trace = {
            key, focus: focus ?? null, tab: 0, window: event && first ? focusWindow(this.sorOf(first), event.km) : null, selected: focus ?? null,
            adding: false, addAt: '', error: '',
        };
        void this.$nextTick(() => this.$refs.trace?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },

    closeTrace(): void {
        this.trace = null;
        session.traceRows = null;
        session.resizeObserver?.disconnect();
        session.resizeObserver = null;
    },

    traceFilesFor(key: string): InputFile[] {
        const fiber = session.dossier?.fibers.find((f) => fiberKey(f) === key);

        return (fiber?.files ?? []).map((name) => session.inputs.find((x) => x.name === name)).filter((x): x is InputFile => x !== undefined);
    },

    sorOf(file: InputFile): SorFile {
        let sor = session.sors.get(file.name);

        if (!sor) {
            sor = readSor(file.name, file.bytes);
            session.sors.set(file.name, sor);
        }

        return sor;
    },

    get traceVisible(): boolean {
        void this.revision;

        return this.trace !== null && session.dossier !== null && this.traceFilesFor(this.trace.key).length > 0;
    },

    get traceFiles(): InputFile[] {
        void this.revision;

        return this.trace ? this.traceFilesFor(this.trace.key) : [];
    },

    get traceFile(): InputFile | undefined {
        return this.traceFiles[this.trace?.tab ?? 0];
    },

    /** Events of the open trace (cached per computation, tab and fiber). */
    get traceEventRows(): TraceEventRow[] {
        const file = this.traceFile;
        const trace = this.trace;

        if (!file || !trace) {
            return [];
        }

        const cached = session.traceRows;

        if (cached && cached.revision === this.revision && cached.key === trace.key && cached.tab === trace.tab) {
            return cached.rows;
        }

        const rows = traceEvents(file, raw(this.overrides), session.dossier?.traces);
        session.traceRows = { revision: this.revision, key: trace.key, tab: trace.tab, rows };

        return rows;
    },

    get traceHeading(): string {
        void this.revision;
        const fiber = this.trace ? session.dossier?.fibers.find((f) => fiberKey(f) === this.trace!.key) : undefined;

        if (!fiber || !session.dossier) {
            return '';
        }

        const where = session.dossier.kind === 'distribution' ? pboOf(fiber.cableId) : fiber.cableId;

        return t('trace.heading', { title: t('trace.title', { where, fiber: fiber.fiber, wavelength: fiber.lambda }) });
    },

    get traceTabs(): { key: string; index: number; label: string; pressed: boolean }[] {
        const files = this.traceFiles;

        if (files.length < 2) {
            return [];
        }

        return files.map((f, i) => ({
            key: f.name, index: i, pressed: this.trace?.tab === i,
            label: i === 0 ? t('trace.file_oe') : session.dossier?.kind === 'transport' ? t('trace.file_return') : t('trace.file_number', { number: i + 1 }),
        }));
    },

    selectTraceTab(index: number): void {
        if (this.trace) {
            this.trace = { ...this.trace, tab: index, window: null, adding: false };
        }
    },

    get traceFileDetails(): string {
        const file = this.traceFile;

        if (!file) {
            return '';
        }

        const sor = this.sorOf(file);

        return t('trace.file_details', { file: sor.name, wavelength: sor.wavelengthNm, pulse: sor.pulseNs, otdr: sor.otdr, serial: sor.otdrSerial });
    },

    observePlot(box: HTMLElement): void {
        session.resizeObserver?.disconnect();
        const measure = () => {
            this.traceWidth = plotWidth(box.clientWidth);
        };
        measure();
        session.resizeObserver = new ResizeObserver(measure);
        session.resizeObserver.observe(box);
    },

    get plotBoxClass(): string {
        return this.trace?.adding ? 'otdr__plot is-adding' : 'otdr__plot';
    },

    get traceWindow(): PlotWindow | null {
        const file = this.traceFile;

        return file && this.trace ? (this.trace.window ?? defaultWindow(this.sorOf(file))) : null;
    },

    drawTracePlot(svg: SVGSVGElement): void {
        const file = this.traceFile;
        const trace = this.trace;
        const visible = this.traceWindow;

        if (!file || !trace || !visible || !session.dossier) {
            return;
        }

        const sor = this.sorOf(file);
        const width = this.traceWidth;
        const height = plotHeight(width);
        const geometry = plotGeometry(sor, visible, width, height);
        const rows = this.traceEventRows;
        const kind = session.dossier.kind;
        const selected = rows.find((r) => r.event.num === trace.selected && !r.ignored);
        drawTrace(svg, {
            width, height, window: visible, geometry,
            ticks: axisTicks(visible, width).map((km) => ({ km, label: t('trace.axis_distance', { value: (km * 1000).toFixed(0) }) })),
            levelTop: t('trace.axis_level', { value: translator.fixed(-geometry.lo, 2) }),
            levelBottom: t('trace.axis_level', { value: translator.fixed(-geometry.hi, 2) }),
            markers: selected && !selected.end ? eventMarkers(sor, selected.event) : null,
            events: rows.map((r, i) => ({
                km: r.event.km, num: r.event.num, label: translator.message(r.marker), tone: markerTone(r, i, kind), selected: r.event.num === trace.selected, ignored: r.ignored,
            })),
        }, (event, e) => {
            if (!this.trace?.adding) {
                e.stopPropagation();
                this.focusTraceEvent(event.km, event.num);
            }
        });
    },

    focusTraceEvent(km: number, num?: number): void {
        const file = this.traceFile;

        if (!file || !this.trace) {
            return;
        }

        this.trace = { ...this.trace, window: focusWindow(this.sorOf(file), km), selected: num ?? this.trace.selected };
    },

    zoomTrace(factor: number): void {
        const file = this.traceFile;
        const visible = this.traceWindow;

        if (file && visible && this.trace) {
            this.trace = { ...this.trace, window: zoomWindow(this.sorOf(file), visible, factor) };
        }
    },

    panTrace(factor: number): void {
        const file = this.traceFile;
        const visible = this.traceWindow;

        if (file && visible && this.trace) {
            this.trace = { ...this.trace, window: panWindow(this.sorOf(file), visible, factor) };
        }
    },

    showWholeTrace(): void {
        if (this.trace) {
            this.trace = { ...this.trace, window: null };
        }
    },

    toggleAdding(): void {
        if (this.trace) {
            this.trace = { ...this.trace, adding: !this.trace.adding, error: '' };
        }
    },

    get addingLabel(): string {
        return t(this.trace?.adding ? 'trace.adding' : 'trace.add');
    },

    get addingClass(): string {
        return this.trace?.adding ? 'otdr__btn otdr__btn--primary' : 'otdr__btn';
    },

    setAddAt(event: Event): void {
        if (this.trace) {
            this.trace = { ...this.trace, addAt: inputOf(event).value };
        }
    },

    get cannotAddAt(): boolean {
        return !this.trace?.addAt.trim();
    },

    addAtDistance(): void {
        const value = Number((this.trace?.addAt ?? '').replace(',', '.'));

        if (Number.isFinite(value)) {
            this.addTraceEvent(value / 1000);
        }
    },

    plotClick(event: MouseEvent): void {
        const visible = this.traceWindow;

        if (!this.trace?.adding || !visible) {
            return;
        }

        const rect = (event.currentTarget as SVGSVGElement).getBoundingClientRect();
        const km = plotKm(visible, this.traceWidth, ((event.clientX - rect.left) / rect.width) * this.traceWidth);

        if (km !== null) {
            this.addTraceEvent(km);
        }
    },

    addTraceEvent(km: number): void {
        const file = this.traceFile;

        if (!file || !this.trace || !session.dossier) {
            return;
        }

        const result = tryAddEvent(file, raw(this.overrides), km, session.dossier.kind);

        if (result.error) {
            // A refusal met while re-applying the corrections names the file: the trace title already does.
            const reason = result.error.key.startsWith('added_event_refused_') ? message(result.error.key.replace('added_event_', 'add_event_'), result.error.params) : result.error;
            this.trace = { ...this.trace, error: t('trace.add_error', { reason: translator.message(reason) }) };

            return;
        }

        this.trace = { ...this.trace, adding: false, addAt: '', error: '' };
        this.changeOverride(result.override, false);
    },

    get traceRows(): {
        key: string; index: number; label: string; trace: string; fromFirst: string; loss: string; reflectance: string; slope: string; slopeTag: string; slopeTitle: string;
        typeText: string; selectable: boolean; kind: EventKind; className: string; action: string; actionLabel: string;
    }[] {
        const rows = this.traceEventRows;
        const distribution = session.dossier?.kind === 'distribution';
        const origin = rows.find((r) => !r.ignored)?.event.km ?? 0;

        return rows.map((r, i) => {
            const e = r.event;
            const source = e.slopeSource;
            const action = r.added && r.override ? 'delete' : r.end ? '' : r.ignored ? 'restore' : 'ignore';

            return {
                key: `${e.num}-${r.added}-${r.ignored}`,
                index: i,
                label: r.end ? t('trace.end') : translator.message(r.marker),
                trace: String(Math.round(e.km * 1000)),
                fromFirst: String(Math.round((e.km - origin) * 1000)),
                loss: r.end ? '' : translator.fixed(e.loss, 3),
                reflectance: e.reflectance ? translator.fixed(e.reflectance, 2) : '',
                slope: e.slope ? translator.fixed(e.slope, 3) : '',
                slopeTag: e.slope && source && source !== 'otdr' ? t(source === 'lsa' ? 'trace.slope_lsa' : 'trace.slope_fiber') : '',
                slopeTitle: source ? translator.message(message(`slope_source_${source}`)) : '',
                typeText: r.end ? t('trace.end_type') : r.added ? t('trace.added') : r.detected ? t('trace.detected') : t('trace.auto'),
                selectable: !r.end && distribution,
                kind: rowKind(r, i),
                className: r.ignored ? 'is-ignored' : r.detected ? 'is-detected' : r.added ? 'is-added' : '',
                action,
                actionLabel: action ? t(`trace.${action}`) : '',
            };
        });
    },

    get traceLegendTail(): string {
        return t(this.isBidirectional ? 'trace.legend_transport' : 'trace.legend_distribution');
    },

    focusTraceRow(index: number): void {
        const row = this.traceEventRows[index];

        if (row) {
            this.focusTraceEvent(row.event.km, row.event.num);
        }
    },

    retypeTraceRow(index: number, event: Event): void {
        const row = this.traceEventRows[index];
        const file = this.traceFile;

        if (!row || !file) {
            return;
        }

        const kind = (event.target as HTMLSelectElement).value as EventKind;

        if (row.added && row.override) {
            this.changeOverride({ ...row.override, kind }, false);
        } else {
            this.changeOverride({ file: file.name, event: row.event.num, action: kind }, kind === automaticKind(row, index));
        }
    },

    traceRowAction(index: number): void {
        const row = this.traceEventRows[index];
        const file = this.traceFile;

        if (!row || !file) {
            return;
        }

        if (row.added && row.override) {
            this.changeOverride(row.override, true);
        } else if (!row.end) {
            this.changeOverride({ file: file.name, event: row.event.num, action: 'ignore' }, row.ignored);
        }
    },

    // ------------------------------------------------------------------ operator deliverables

    get showGdcoDeliverables(): boolean {
        return this.isGdco && this.hasFibers;
    },

    get showGdcoExport(): boolean {
        void this.revision;

        return this.isStandard && this.hasFibers && session.gdcoSummary !== null;
    },

    toggleGdcoExport(event: Event): void {
        this.gdcoExportOpen = (event.target as HTMLDetailsElement).open;
    },

    get gdcoExportNote(): string {
        return t(this.isBidirectional ? 'gdco_export.note_transport' : 'gdco_export.note_distribution');
    },

    get isGdcoTransport(): boolean {
        void this.revision;

        return session.gdcoDossier?.kind === 'transport';
    },

    get isGdcoDistribution(): boolean {
        void this.revision;

        return session.gdcoDossier?.kind === 'distribution';
    },

    setGdcoField(field: keyof GdcoFieldValues, event: Event): void {
        this.gdcoFields = { ...this.gdcoFields, [field]: (event.target as HTMLInputElement | HTMLSelectElement).value };
        saveGdcoFields(raw(this.gdcoFields));
    },

    get gdcoMissing(): string {
        const missing = GDCO_REQUIRED.filter(([field]) => !this.gdcoFields[field].trim()).map(([, label]) => t(`gdco_deliverables.${label}`));

        return missing.length ? t('gdco_deliverables.missing', { fields: missing.join(', ') }) : '';
    },

    get gdcoBlocked(): boolean {
        return this.gdcoBusy || this.gdcoMissing !== '';
    },

    get dcoLabel(): string {
        return t(this.gdcoBusy ? 'gdco_deliverables.generating' : 'gdco_deliverables.download_dco');
    },

    get gdcoRedLight(): boolean {
        void this.revision;

        return session.gdcoSummary?.light === 2;
    },

    get ropText(): string {
        const r = this.ropSummary;

        if (!r) {
            return '';
        }

        return r.pm ? t('gdco_deliverables.rop_summary_pm', { pbo: r.pbo, fibers: r.fibers, pm: r.pm }) : t('gdco_deliverables.rop_summary', { pbo: r.pbo, fibers: r.fibers });
    },

    get ropName(): string {
        return this.ropFileName || t('gdco_deliverables.no_file');
    },

    get showPreRepair(): boolean {
        return this.isGdcoDistribution && this.failedRepairs.length > 0;
    },

    get preRepairText(): string {
        return t('gdco_deliverables.pre_repair_count', { count: this.preRepairCount });
    },

    get gdcoFooter(): string {
        return t('gdco_deliverables.footer', { product: platform });
    },

    async onRop(event: Event): Promise<void> {
        const file = inputOf(event).files?.[0];

        if (!file) {
            return;
        }

        try {
            const summary = parseRopCsv(await file.text());
            this.ropSummary = summary;
            this.ropFileName = file.name;
            session.rop = { name: file.name, data: new Uint8Array(await file.arrayBuffer()) };
            this.gdcoError = '';
        } catch (error) {
            this.ropSummary = null;
            this.ropFileName = '';
            session.rop = null;
            this.gdcoError = errorText(error);
        }
    },

    async onPreRepair(event: Event): Promise<void> {
        const files = Array.from(inputOf(event).files ?? []);
        session.preRepair = await Promise.all(files.map(async (f) => ({ name: f.name, data: new Uint8Array(await f.arrayBuffer()) })));
        this.preRepairCount = session.preRepair.length;
    },

    dcoInfo(): DcoInfo {
        const f = this.gdcoFields;
        const total = Number(f.totalFibers);

        return {
            zone: f.zone.trim(), g2r: f.g2r.trim(), pm: f.pm.trim(), stit: f.stit.trim(), technician: f.technician.trim(), transportType: f.transportType,
            totalFibersNroPm: Number.isFinite(total) && total > 0 ? total : undefined, rop: this.ropSummary ? { ...raw(this.ropSummary) } : undefined,
            nroPmLengthM: parseDecimal(this.nroPmLength), nroPmAttenuation1310: parseDecimal(this.nroPmAttenuation), failedRepairs: raw(this.failedRepairs),
            generatedAt: new Date(),
        };
    },

    downloadGdcoTxt(): void {
        if (session.gdcoDossier) {
            saveFile(gdcoTxtDeliverable(session.gdcoDossier, this.gdcoFields.pm, this.gdcoFields.transportType));
        }
    },

    async runGdco(build: (d: Dossier, s: Summary) => Promise<FileOutput>): Promise<void> {
        const d = session.gdcoDossier;
        const s = session.gdcoSummary;

        if (!d || !s) {
            return;
        }

        this.gdcoBusy = true;
        this.gdcoError = '';

        try {
            await excelReady();
            saveFile(await build(d, s));
        } catch (error) {
            this.gdcoError = errorText(error);
        } finally {
            this.gdcoBusy = false;
        }
    },

    downloadDco(): void {
        void this.runGdco((d, s) => dcoDeliverable(d, s, this.dcoInfo()));
    },

    downloadGdcoArchive(): void {
        void this.runGdco((d, s) => gdcoArchiveDeliverable({
            dossier: d, summary: s, info: this.dcoInfo(), inputs: session.inputs, overrides: raw(this.overrides), failedRepairs: raw(this.failedRepairs),
            rop: session.rop ?? undefined, preRepairSor: session.preRepair,
        }));
    },

    // ------------------------------------------------------------------ standard deliverables (measurement dossier)

    get showStandardDeliverables(): boolean {
        return this.isStandard && this.hasFibers;
    },

    get reportFieldList(): { key: string; label: string; value: string }[] {
        return REPORT_FIELDS.map(([key, label]) => ({ key, label: t(`measurement_report.${label}`), value: this.reportValues[key] }));
    },

    get reportOptionList(): { key: string; label: string; checked: boolean }[] {
        return REPORT_OPTIONS.map(([key, label]) => ({ key, label: t(`measurement_report.${label}`), checked: this.reportOptions[key] }));
    },

    saveReport(): void {
        const { name: _name, origin: _origin, extremity: _extremity, ...fields } = raw(this.reportValues);
        saveReportFields(fields, raw(this.reportOptions));
    },

    setReportField(key: keyof ReportValues, event: Event): void {
        this.reportValues = { ...this.reportValues, [key]: inputOf(event).value };
        this.saveReport();
    },

    setReportOption(key: keyof ReportOptions, event: Event): void {
        this.reportOptions = { ...this.reportOptions, [key]: inputOf(event).checked };
        this.saveReport();
    },

    get reportLabel(): string {
        return t(this.reportBusy ? 'measurement_report.generating' : 'measurement_report.download_report');
    },

    get reportFooter(): string {
        const name = this.profile.id === CUSTOM_PROFILE_ID ? t('config.custom_profile_name') : this.profile.name;

        return t('measurement_report.footer', { profile: name, product: platform });
    },

    async runReport(build: (d: Dossier, s: Summary) => Promise<FileOutput>): Promise<void> {
        const d = session.dossier;
        const s = session.summary;

        if (!d || !s) {
            return;
        }

        this.reportBusy = true;
        this.reportError = '';

        try {
            await excelReady();
            saveFile(await build(d, s));
        } catch (error) {
            this.reportError = errorText(error);
        } finally {
            this.reportBusy = false;
        }
    },

    downloadReport(): void {
        void this.runReport((d, s) => measurementReportDeliverable(d, s, raw(this.profile), { ...raw(this.reportValues) }, { ...raw(this.reportOptions) }));
    },

    downloadStandardTxt(): void {
        if (session.dossier) {
            saveFile(standardTxtDeliverable(session.dossier, this.reportValues.name, raw(this.profile)));
        }
    },

    downloadStandardArchive(): void {
        void this.runReport((d, s) => standardArchiveDeliverable({
            dossier: d, summary: s, profile: raw(this.profile), info: { ...raw(this.reportValues) }, options: { ...raw(this.reportOptions) }, inputs: session.inputs,
            overrides: raw(this.overrides),
        }));
    },
});

let excelWarmedUp = false;

/**
 * Registers the screen with Alpine. When a service worker keeps the application for offline use, the spreadsheet library
 * is fetched once in the background so that the workbooks can be generated later without network.
 */
export function register(dataset: DOMStringMap): void {
    let texts: TextTree = {};

    try {
        texts = JSON.parse(dataset.texts ?? '{}') as TextTree;
    } catch {
        texts = {};
    }

    setup(texts, intlLocale(dataset.locale ?? 'en_GB'), dataset.product ?? '');
    window.Alpine.data('otdrAcceptance', otdrScreen);

    if (!excelWarmedUp && typeof navigator !== 'undefined' && navigator.serviceWorker?.controller) {
        excelWarmedUp = true;
        const warmUp = () => void loadExcelJS().catch(() => undefined);

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(warmUp);
        } else {
            setTimeout(warmUp, 2000);
        }
    }
}
