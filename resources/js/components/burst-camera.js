import { el, fill, JPEG_QUALITY, lookUpPlace, position, renderStamped } from './geo-camera';

/**
 * Burst camera for the audits: the shutter stays ready, every shot is stamped (name, place, coordinates, date) and
 * kept in a strip of thumbnails that can be corrected, and all the shots are handed over at once. The person can
 * also have the photos saved in the phone's gallery through the share sheet.
 *
 * @returns {Promise<{shots: Array<{blob: Blob, lat: number|null, lng: number|null, accuracy: number|null}>, saveToGallery: boolean}|null>} null when cancelled.
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
        let locationDone = false;
        let placeTime = 0;

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

        const finish = (result) => {
            stream?.getTracks().forEach((track) => track.stop());
            overlay.remove();
            resolve(result);
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

        const locate = async () => {
            const found = await position();

            if (found) {
                located = found;
                message.textContent = fill(labels.located, { accuracy: Math.round(found.accuracy) });
                updatePlace();
            } else if (!allowWithoutLocation) {
                located = null;
                message.textContent = labels.location_required;
            } else {
                located = null;
                message.textContent = labels.location_unavailable;
            }

            locationDone = true;
            shutter.disabled = stream === null || (!located && !allowWithoutLocation);
        };

        locate();

        navigator.mediaDevices?.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 4096 }, height: { ideal: 3072 } }, audio: false })
            .then((media) => {
                stream = media;
                video.srcObject = media;
                video.setAttribute('playsinline', '');
                video.play();
                shutter.disabled = !locationDone || (!located && !allowWithoutLocation);
            })
            .catch(() => { message.textContent = labels.camera_error; });

        shutter.addEventListener('click', async () => {
            if (!located && !allowWithoutLocation) {
                locate();

                return;
            }

            flash.classList.remove('is-flashing');
            void flash.offsetWidth;
            flash.classList.add('is-flashing');

            const canvas = document.createElement('canvas');
            renderStamped(video, canvas, { labels, name, timeZone, located, place });
            const blob = await new Promise((done) => canvas.toBlob(done, 'image/jpeg', JPEG_QUALITY));
            shots.push({ blob, lat: located?.lat ?? null, lng: located?.lng ?? null, accuracy: located?.accuracy ?? null, url: URL.createObjectURL(blob) });
            refresh();
            locate();
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
            const result = shots.map(({ blob, lat, lng, accuracy }) => ({ blob, lat, lng, accuracy }));

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
