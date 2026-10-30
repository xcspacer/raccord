import type { FileOutput } from '../export/deliverables';

const REVOKE_AFTER_MS = 10000;

/** Hands a generated file to the browser (nothing leaves the device). */
export function saveFile(file: FileOutput): void {
    const url = URL.createObjectURL(new Blob([file.data as BlobPart], { type: file.mime }));
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), REVOKE_AFTER_MS);
}
