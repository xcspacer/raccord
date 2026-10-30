/**
 * OTDR acceptance library: reading of the SR-4731 .sor files, measurement dossier (unidirectional, bidirectional,
 * loop), complete trace analysis, standard control (profile thresholds) and operator control (GDCO macro), and the
 * deliverables (DCO, FiberCable 2 .txt, measurement dossier, failed repair report, corrections, archives).
 *
 * Pure library: no DOM, no download. Generators return bytes + file name + media type; ExcelJS is loaded on demand
 * (own chunk). Screen texts are `Message` objects (`key` + `params`) to translate with `lang/{locale}/otdr.php`
 * (`otdr.messages.{key}`, Laravel `:param` placeholders; numeric params are raw numbers to format for the locale).
 * Texts inside the delivered files are the operator's French format (`export/format.ts`).
 *
 * Typical flow (mirrors the previous screen):
 *   configureProduct({ name, version })                 platform identity written into the files
 *   inputs = await readInputFiles(fileList)
 *   standard: dossier = analyzeStandard(inputs, overrides, acquisition); summary = evaluateStandard(dossier, profile)
 *   operator: dossier = analyzeGdco(inputs, kindChoice, overrides, acquisition); summary = evaluateGdco(dossier, { nroPmLengthM, nroPmAttenuation1310, failedRepairs })
 *   corrections: upsertOverride / tryAddEvent / traceEvents; device persistence: dossierKey + storedCorrectionsPayload / parseStoredCorrections
 *   deliverables: gdcoTxtDeliverable, dcoDeliverable, gdcoArchiveDeliverable, standardTxtDeliverable, measurementReportDeliverable, standardArchiveDeliverable…
 */

// Identity and messages
export { configureProduct, product, type ProductInfo } from './engine/product';
export { message, OtdrError, type Message } from './engine/messages';

// Reading
export { readSor, SorFormatError, unreadableFileMessage, isEndEvent, timeToIndex, type SorEvent, type SorFile } from './engine/sor';
export { parseSorName, guessKind, type ParsedName } from './engine/naming';

// Dossier
export { analyze, applyOverrides, dropOrigin, suggestAcquisition, type InputFile } from './engine/dossier';
export { ADDED_EVENT_BASE } from './engine/types';
export type {
    Dossier, DossierKind, EventKind, EventOverride, EventResult, FiberResult, FileMeta, Lambda, MeasureMode, SectionResult, SlopeSource,
} from './engine/types';
export { addEvent, AddEventError, mean3, round2, round3, totalLoss, type AddEventRefusal } from './engine/trace';
export { completeTrace, DEFAULT_DETECTION, DETECTED_EVENT_BASE, geometry, isDetected, Lsa, type DetectionSettings } from './engine/analysis';

// Profiles and controls
export {
    activeThreshold, cloneProfile, CUSTOM_PROFILE_ID, DEFAULT_ACQUISITION, GDCO_ACQUISITION, PRESETS,
    type AcquisitionConfig, type LambdaThresholds, type Profile, type Spools, type ThresholdKey,
} from './engine/profile';
export { evaluateStandard } from './engine/standard';
export {
    evaluateGdco, GDCO_THRESHOLDS, pboOf,
    type Anomaly, type AnomalyType, type Criterion, type DistributionInputs, type FailedRepair, type Light, type Summary, type Thresholds,
} from './engine/rules';

// Screen workflow
export {
    addFailedRepair, analyzeGdco, analyzeStandard, anomalyTypeMessage, canAddFailedRepair, correctionsImportMessages, failedRepairStatus,
    gdcoDossierForStandard, lightMessages, mergeFailedRepairs, readInputFiles, removeFailedRepair, traceEvents, tryAddEvent, upsertOverride,
    type KindChoice, type TraceEventRow, type Treatment,
} from './workflow';

// Imports (failed repair report, Base ROP extract, corrections)
export {
    buildFailedRepairsWorkbook, failedRepairsFileName, failedRepairsFromRows, importFailedRepairsFile, readFailedRepairsFile, type FailedRepairImport,
} from './export/failedRepairs';
export { parseRopCsv, type RopSummary } from './export/ropExtract';
export {
    buildCorrections, correctionsLog, CORRECTIONS_FORMAT, dossierKey, hasDetected, isCorrectionsFormat, parseCorrections, parseStoredCorrections,
    storedCorrectionsPayload, type Corrections, type CorrectionsFile,
} from './export/corrections';

// Deliverables
export {
    correctionsDeliverable, correctionsLogDeliverable, dcoDeliverable, failedRepairsDeliverable, failedRepairsPm, gdcoArchiveDeliverable, gdcoTxtDeliverable,
    measurementReportDeliverable, standardArchiveDeliverable, standardTxtDeliverable, suggestCableName, suggestExtremity, suggestPm, suggestTechnician,
    type FileOutput, type GdcoArchiveRequest, type StandardArchiveRequest,
} from './export/deliverables';
export { buildDco, dcoFileName, type DcoInfo } from './export/dco';
export { buildFiberCableTxt, encodeUtf16le, referenceProfile, REFERENCE_TXT_THRESHOLDS, txtFileName } from './export/fiberCableTxt';
export { buildMeasurementReport, DEFAULT_REPORT_OPTIONS, measurementReportFileName, type CableInfo, type ReportOptions } from './export/measurementReport';
export { buildGdcoArchive, buildStandardArchive, gdcoArchiveFileName, gdcoArchiveLayout, safeFileName, type GdcoArchiveInput, type NamedBytes, type StandardArchiveInput } from './export/archives';
export { loadExcelJS, workbookBytes, XLSX_MIME, type ExcelJS } from './export/excel';

