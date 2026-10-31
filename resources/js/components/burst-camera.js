import { el, fill, JPEG_QUALITY, lookUpPlace, renderStamped } from './geo-camera';

/** A position the phone already had this recently is still taken (it was found next to the box). */
export const RECENT_POSITION_MS = 5 * 60 * 1000;

/** Without any position after this long (no signal, not a refused permission), the photo may be taken without one. */
export const NO_SIGNAL_AFTER_MS = 30 * 1000;

const WATCH_OPTIONS = { enableHighAccuracy: true, maximumAge: RECENT_POSITION_MS, timeout: 20000 };

/**
 * Why a photo has no position, as the server records it: no network on the phone, no fix in time, or none at all.
 *
 * @param {number|null} errorCode last GeolocationPositionError code (2 unavailable, 3 timeout)
 */
export const noSignalReason = (online, errorCode) => {
    if (!online) {
        return 'network';
    }

    return errorCode === 2 ? 'unavailable' : 'timeout';
};

/**
 * Burst camera for the audits: the shutter stays ready, every shot is stamped (name, place, coordinates, date) and
 * kept in a strip of thumbnails that can be corrected, and all the shots are handed over at once. The person can
 * also have the photos saved in the phone's gallery through the share sheet.
 *
 * The position is followed for as long as the camera is open (a fix found next to the box a few minutes ago counts).
 * Without a network the first fix can take minutes: after a while without any, the photos may be taken all the same,
 * stamped and recorded « no GPS » with the reason. A refused location permission still blocks the technicians.
 *
 * @returns {Promise<{shots: Array<{blob: Blob, lat: number|null, lng: number|null, accuracy: number|null, reason: string|null}>, saveToGallery: boolean}|null>} null when cancelled.
 */
export function openBurstCamera({ labels, name, timeZone, allowWithoutLocation = false, confirmDiscard }) {
    return new Promise((resolve) => {
        const overlay = el('div', 'camera camera--burst');
        const stage = el('div', 'camera__stage');
        const video = el('video', 'camera__video');
        const flash = el('div', 'camera__flash');
        const message = el('p', 'camera__message', labels.locating);
        const strip = el('div', 'camera__strip');
        const controls = el('div', 'camera__controls');
        const cancel = el('button', 'btn btn--ghost', labels.cancel);
        const shutter = el('button', 'camera__shutter');
        const use = el('button', 'btn btn--primary');
        const gallery = el('label', 'camera__gallery');
        const galleryBox = el('input');
        const canShare = typeof navigator.canShare === 'function' && navigator.canShare({ files: [new File([''], 'a.jpg', { type: 'image/jpeg' })] });
        const shots = [];
        let stream = null;
        let located = null;
        let place = null;
        let placeTime = 0;
        let denied = false;
        let noSignal = false;
        let lastError = null;
        let watchId = null;

        shutter.setAttribute('aria-label', labels.capture);
        galleryBox.type = 'checkbox';
        galleryBox.checked = canShare;
        gallery.append(galleryBox, el('span', undefined, labels.save_to_gallery));
        gallery.hidden = !canShare;
        use.hidden = true;
        shutter.disabled = true;
        controls.append(cancel, shutter, use);
        stage.append(video, flash);
        overlay.append(stage, message, strip, gallery, controls);
        document.body.append(overlay);

        const noSignalTimer = setTimeout(() => {
            noSignal = !located && !denied;
            update();
        }, NO_SIGNAL_AFTER_MS);

        const finish = (result) => {
            stream?.getTracks().forEach((track) => track.stop());

            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
            }

            clearTimeout(noSignalTimer);
            overlay.remove();
            resolve(result);
        };

        const canShoot = () => stream !== null && (located !== null || allowWithoutLocation || noSignal);

        const update = () => {
            if (located) {
                message.textContent = fill(labels.located, { accuracy: Math.round(located.accuracy) });
            } else if (denied && !allowWithoutLocation) {
                message.textContent = labels.location_required;
            } else if (noSignal) {
                message.textContent = labels.no_signal;
            } else if (allowWithoutLocation && lastError !== null) {
                message.textContent = labels.location_unavailable;
            }

            shutter.disabled = !canShoot();
        };

        const refresh = () => {
            use.hidden = shots.length === 0;
            use.textContent = fill(labels.use_count, { count: shots.length });
            strip.replaceChildren(...shots.map((shot, index) => {
                const thumb = el('div', 'camera__thumb');
                const image = el('img');
                const remove = el('button', 'camera__thumb-remove', '✕');
                image.src = shot.url;
                image.alt = '';
                remove.addEventListener('click', () => {
                    URL.revokeObjectURL(shot.url);
                    shots.splice(index, 1);
                    refresh();
                });
                thumb.append(image, remove);

                return thumb;
            }));
        };

        const updatePlace = async () => {
            if (!located || Date.now() - placeTime < 40000) {
                return;
            }

            placeTime = Date.now();
            place = (await lookUpPlace(labels, located)) ?? place;
        };

        if (navigator.geolocation) {
            watchId = navigator.geolocation.watchPosition(
                ({ coords }) => {
                    located = { lat: coords.latitude, lng: coords.longitude, accuracy: coords.accuracy };
                    noSignal = false;
                    update();
                    updatePlace();
                },
                (error) => {
                    lastError = error.code;
                    denied = error.code === 1;
                    noSignal = noSignal && !denied;
                    update();
                },
                WATCH_OPTIONS,
            );
        } else {
            lastError = 2;
        }

        navigator.mediaDevices?.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 4096 }, height: { ideal: 3072 } }, audio: false })
            .then((media) => {
                stream = media;
                video.srcObject = media;
                video.setAttribute('playsinline', '');
                video.play();
                update();
            })
            .catch(() => { message.textContent = labels.camera_error; });

        shutter.addEventListener('click', async () => {
            if (!canShoot()) {
                return;
            }

            flash.classList.remove('is-flashing');
            void flash.offsetWidth;
            flash.classList.add('is-flashing');

            const reason = located ? null : noSignalReason(navigator.onLine, lastError);
            const canvas = document.createElement('canvas');
            renderStamped(video, canvas, { labels: reason ? { ...labels, location_unavailable: labels.no_signal_stamp } : labels, name, timeZone, located, place });
            const blob = await new Promise((done) => canvas.toBlob(done, 'image/jpeg', JPEG_QUALITY));
            shots.push({ blob, lat: located?.lat ?? null, lng: located?.lng ?? null, accuracy: located?.accuracy ?? null, reason, url: URL.createObjectURL(blob) });
            refresh();
        });

        cancel.addEventListener('click', async () => {
            if (shots.length > 0 && !(await confirmDiscard(fill(labels.discard_shots, { count: shots.length })))) {
                return;
            }

            shots.forEach((shot) => URL.revokeObjectURL(shot.url));
            finish(null);
        });

        use.addEventListener('click', async () => {
            const saveToGallery = canShare && galleryBox.checked;
            const result = shots.map(({ blob, lat, lng, accuracy, reason }) => ({ blob, lat, lng, accuracy, reason }));

            if (saveToGallery) {
                try {
                    await navigator.share({ files: result.map((shot, index) => new File([shot.blob], `${name}-${Date.now()}-${index + 1}.jpg`, { type: 'image/jpeg' })) });
                } catch {
                    // Sharing was dismissed: the photos are kept for the audit all the same.
                }
            }

            shots.forEach((shot) => URL.revokeObjectURL(shot.url));
            finish({ shots: result, saveToGallery });
        });
    });
}
