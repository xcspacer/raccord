import { JPEG_QUALITY, MAX_SIDE } from './geo-camera';

/**
 * A photo picked on the device, downscaled to 2560 px and re-encoded as JPEG before upload (which also drops its
 * EXIF). Null when the browser cannot read the image.
 *
 * @returns {Promise<Blob|null>}
 */
export async function downscaleImage(file) {
    try {
        const bitmap = await createImageBitmap(file);
        const ratio = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * ratio);
        canvas.height = Math.round(bitmap.height * ratio);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        return await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));
    } catch {
        return null;
    }
}
