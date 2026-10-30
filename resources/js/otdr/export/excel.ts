// ExcelJS (≈ 1 MB) is only needed for the workbooks: it is imported on demand so that the bundler puts it in its own
// chunk, fetched the first time a workbook is generated or read.
import type ExcelJSNamespace from 'exceljs';

export type ExcelJS = typeof ExcelJSNamespace;

let loading: Promise<ExcelJS> | null = null;

export function loadExcelJS(): Promise<ExcelJS> {
    loading ??= import('exceljs')
        .then((module) => ((module as unknown as { default?: ExcelJS }).default ?? (module as unknown as ExcelJS)))
        .catch((error: unknown) => {
            loading = null;

            throw error;
        });

    return loading;
}

export const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/** Serialised workbook. */
export async function workbookBytes(workbook: ExcelJSNamespace.Workbook): Promise<Uint8Array> {
    const buffer = await workbook.xlsx.writeBuffer();

    return buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer as ArrayBuffer);
}
