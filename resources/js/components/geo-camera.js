import { fetchWithTimeout } from './http';

/**
 * In-app camera whose photos carry a GPS stamp (who, where, when) printed on the image. Photos are only taken
 * with the rear camera of the app (never picked from the gallery), downscaled to 2560 px and saved as JPEG.
 *
 * Buttons with data-geo-camera open it; the photo is then uploaded to the Livewire component that holds the
 * button (data-camera-property = the upload property, data-camera-method = the method to call, data-camera-args
 * = JSON list of arguments, followed by { lat, lng, accuracy }).
 */
export const MAX_SIDE = 2560;
export const JPEG_QUALITY = 0.82;
const GEO_OPTIONS = { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 };

export const el = (tag, className, text) => {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
};

export const fill = (template, values) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), template ?? '');

export const position = () => new Promise((resolve) => {
    if (!navigator.geolocation) {
        resolve(null);

        return;
    }

    navigator.geolocation.getCurrentPosition(
        ({ coords }) => resolve({ lat: coords.latitude, lng: coords.longitude, accuracy: coords.accuracy }),
        () => resolve(null),
        GEO_OPTIONS,
    );
});

const gmtLabel = (date, timeZone) => {
    const parts = new Intl.DateTimeFormat('en', { timeZone, timeZoneName: 'longOffset' }).formatToParts(date);
    const offset = parts.find((part) => part.type === 'timeZoneName')?.value ?? 'GMT';

    return offset === 'GMT' ? 'GMT+0' : offset.replace(':00', '').replace(/([+-])0(\d)/, '$1$2');
};

/**
 * Prints the stamp in a dark band at the bottom of the image.
 */
const drawStamp = (context, width, height, lines) => {
    const size = Math.max(14, Math.round(width / 46));
    const padding = Math.round(size * 0.7);
    const bandHeight = padding * 2 + lines.length * size * 1.35;

    context.fillStyle = 'rgba(0, 0, 0, 0.6)';
    context.fillRect(0, height - bandHeight, width, bandHeight);
    context.textBaseline = 'top';

    lines.forEach((line, index) => {
        context.font = `${index === 0 ? '700 ' : ''}${size}px sans-serif`;
        context.fillStyle = '#ffffff';
        context.fillText(line, padding, height - bandHeight + padding + index * size * 1.35, width - padding * 2);
    });
};


/**
 * Draws the current frame of the video on the canvas, scaled down to 2560 px, with the stamp (name, place,
 * coordinates, date) printed in a band at the bottom.
 */
export function renderStamped(video, canvas, { labels, name, timeZone, located, place }) {
    const ratio = Math.min(1, MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
    const width = Math.round(video.videoWidth * ratio);
    const height = Math.round(video.videoHeight * ratio);
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    context.drawImage(video, 0, 0, width, height);

    const now = new Date();
    const when = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'medium', timeZone }).format(now);
    const lines = [name];

    if (located) {
        const where = [place?.city, place?.region, place?.country].filter(Boolean).join(', ');

        if (where) {
            lines.push(where);
        }

        if (place?.address) {
            lines.push(place.address);
        }

        lines.push(fill(labels.lat_long, { lat: located.lat.toFixed(6), lng: located.lng.toFixed(6) }));
    } else {
        lines.push(labels.location_unavailable);
    }

    lines.push(`${when} ${gmtLabel(now, timeZone)}`);
    drawStamp(canvas.getContext('2d'), width, height, lines);
}

/**
 * Looks up the address of a position (best effort: nothing is printed when the network or the service is missing).
 */
export function lookUpPlace(labels, found) {
    return fetchWithTimeout(`${labels.reverse_url}?lat=${found.lat}&lng=${found.lng}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((response) => response.json())
        .then((answer) => answer.place ?? null)
        .catch(() => null);
}

/**
 * @returns {Promise<{blob: Blob, lat: number|null, lng: number|null, accuracy: number|null}|null>} null when cancelled.
 */
export function openGeoCamera({ labels, name, timeZone, allowWithoutLocation = false }) {
    return new Promise((resolve) => {
        const overlay = el('div', 'camera');
        const video = el('video', 'camera__video');
        const preview = el('canvas', 'camera__preview');
        const message = el('p', 'camera__message', labels.locating);
        const cancel = el('button', 'btn btn--ghost', labels.cancel);
        const capture = el('button', 'btn btn--primary', `📸 ${labels.capture}`);
        const retake = el('button', 'btn btn--ghost', `↺ ${labels.retake}`);
        const use = el('button', 'btn btn--primary', `✓ ${labels.use}`);
        const controls = el('div', 'camera__controls');
        let stream = null;
        let place = null;
        let located = null;
        let locationDone = false;

        [video, preview, message, controls].forEach((node) => overlay.append(node));
        controls.append(cancel, capture, retake, use);
        preview.hidden = true;
        retake.hidden = true;
        use.hidden = true;
        capture.disabled = true;
        document.body.append(overlay);

        const finish = (result) => {
            stream?.getTracks().forEach((track) => track.stop());
            overlay.remove();
            resolve(result);
        };

        cancel.addEventListener('click', () => finish(null));

        position().then(async (found) => {
            located = found;
            locationDone = true;

            if (found) {
                message.textContent = fill(labels.located, { accuracy: Math.round(found.accuracy) });
                fetchWithTimeout(`${labels.reverse_url}?lat=${found.lat}&lng=${found.lng}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then((response) => response.json())
                    .then((answer) => { place = answer.place ?? null; })
                    .catch(() => {});
            } else if (!allowWithoutLocation) {
                message.textContent = labels.location_required;

                return;
            } else {
                message.textContent = labels.location_unavailable;
            }

            capture.disabled = stream === null;
        });

        navigator.mediaDevices?.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 4096 }, height: { ideal: 3072 } }, audio: false })
            .then((media) => {
                stream = media;
                video.srcObject = media;
                video.setAttribute('playsinline', '');
                video.play();
                capture.disabled = !locationDone || (!located && !allowWithoutLocation);
            })
            .catch(() => { message.textContent = labels.camera_error; });

        capture.addEventListener('click', () => {
            renderStamped(video, preview, { labels, name, timeZone, located, place });

            video.hidden = true;
            preview.hidden = false;
            capture.hidden = true;
            retake.hidden = false;
            use.hidden = false;
        });

        retake.addEventListener('click', () => {
            video.hidden = false;
            preview.hidden = true;
            capture.hidden = false;
            retake.hidden = true;
            use.hidden = true;
        });

        use.addEventListener('click', () => {
            preview.toBlob((blob) => finish({ blob, lat: located?.lat ?? null, lng: located?.lng ?? null, accuracy: located?.accuracy ?? null }), 'image/jpeg', JPEG_QUALITY);
        });
    });
}

export function initGeoCamera() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-geo-camera]');

        if (!button) {
            return;
        }

        const host = button.closest('[wire\\:id]');
        const component = host ? window.Livewire?.find(host.getAttribute('wire:id')) : null;
        const config = JSON.parse(button.closest('[data-camera-config]')?.dataset.cameraConfig ?? '{}');

        if (!component) {
            return;
        }

        const result = await openGeoCamera({ labels: config.labels, name: config.name, timeZone: config.timeZone, allowWithoutLocation: Boolean(config.allowWithoutLocation) });

        if (!result) {
            return;
        }

        const file = new File([result.blob], `photo-${Date.now()}.jpg`, { type: 'image/jpeg' });
        const args = JSON.parse(button.dataset.cameraArgs ?? '[]');

        component.upload(button.dataset.cameraProperty, file, () => {
            component.call(button.dataset.cameraMethod, ...args, { lat: result.lat, lng: result.lng, accuracy: result.accuracy });
        });
    });
}
