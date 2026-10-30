import { fetchWithTimeout } from './http';
import { showToast } from './toast';

/**
 * Viewing files without leaving the app (on a phone, a download link would leave the installed app for a preview
 * without a way back):
 *  - links marked data-file-view: on a phone (≤ 640 px) photos open in the full-screen photo viewer (with the other
 *    photos of the closest [data-file-group]), PDF and images in the file viewer, other files (KMZ, ZIP, Excel…) are
 *    downloaded and checked, then offered in an « open / save » sheet; on a computer the link works as usual;
 *  - buttons marked data-file-view-open (« Voir ») open the file viewer on every device;
 *  - images marked data-photo-view open the photo viewer on a phone.
 * The file viewer shows a PDF with the browser's own reader on a computer and draws it with pdf.js on phones and
 * tablets (pinch / double tap / − + zoom, only the visible pages are drawn).
 */
const VIEWABLE = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif' };
const DELIVERY_TYPES = {
    kmz: 'application/vnd.google-earth.kmz',
    kml: 'application/vnd.google-earth.kml+xml',
    zip: 'application/zip',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    xls: 'application/vnd.ms-excel',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    doc: 'application/msword',
    csv: 'text/csv',
    txt: 'text/plain',
};
const ZIP_BASED = /^(kmz|zip|xlsx|docx)$/;
const IMAGE = /^(jpe?g|png|webp|gif)$/;
const SWIPE_DISTANCE = 55;
const DOUBLE_TAP_MS = 320;
const ZOOM_STEP = 1.45;
const ZOOM_MIN = 1;
const ZOOM_MAX = 40;
const MAX_CANVAS_PIXELS = 5e6;

export const isMobileUI = () => window.matchMedia('(max-width: 640px)').matches;

const needsPdfJs = () => /Android|iPhone|iPad|iPod/i.test(navigator.userAgent ?? '')
    || (navigator.maxTouchPoints > 1 && /Macintosh/i.test(navigator.userAgent ?? ''))
    || isMobileUI();

/**
 * Lower-case extension of a file name or URL ('' when there is none).
 */
export function extensionOf(name) {
    return (String(name ?? '').split('?')[0].match(/\.([a-z0-9]{2,5})$/i)?.[1] ?? '').toLowerCase();
}

export const isViewable = (name) => Object.hasOwn(VIEWABLE, extensionOf(name));

export const isImage = (name) => IMAGE.test(extensionOf(name));

/**
 * A ZIP-based file (KMZ, XLSX…) is complete when it starts with « PK » and its end-of-archive record is there.
 *
 * @param {Uint8Array} bytes
 */
export function zipLooksComplete(bytes) {
    if (bytes.length < 22 || bytes[0] !== 0x50 || bytes[1] !== 0x4b) {
        return false;
    }

    for (let index = bytes.length - 22; index >= Math.max(0, bytes.length - 65557); index--) {
        if (bytes[index] === 0x50 && bytes[index + 1] === 0x4b && bytes[index + 2] === 5 && bytes[index + 3] === 6) {
            return true;
        }
    }

    return false;
}

/**
 * Double tap on a PDF page: 1 → 3 → 10 → back to 1.
 */
export const nextTapZoom = (zoom) => (zoom < 1.2 ? 3 : zoom < 6 ? 10 : 1);

export const clampZoom = (zoom) => Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom));

/**
 * Downloads a whole file and checks it (announced size, ZIP signature and end of archive; two attempts), with the
 * file's real type so the phone offers the right app (Google Earth for a KMZ).
 */
export async function fetchChecked(url, name) {
    const extension = extensionOf(name);
    let lastError = null;

    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const response = await fetchWithTimeout(url, { cache: 'no-store', credentials: 'same-origin' }, { kind: 'file' });

            if (!response.ok) {
                throw new Error(`http ${response.status}`);
            }

            const buffer = await response.arrayBuffer();
            const expected = Number(response.headers.get('Content-Length') || 0);

            if (expected && buffer.byteLength !== expected) {
                throw new Error('truncated');
            }

            if (ZIP_BASED.test(extension) && !zipLooksComplete(new Uint8Array(buffer))) {
                throw new Error('incomplete');
            }

            return new Blob([buffer], { type: DELIVERY_TYPES[extension] ?? VIEWABLE[extension] ?? 'application/octet-stream' });
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError;
}

const canShareFile = (file) => typeof navigator.canShare === 'function' && navigator.canShare({ files: [file] });

const fill = (template, values) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), template ?? '');

/**
 * Clones one of the viewer templates of the layout into the page.
 */
function mount(selector) {
    const template = document.querySelector(selector);

    if (!template) {
        return null;
    }

    const element = template.content.firstElementChild.cloneNode(true);
    document.body.append(element);

    return element;
}

function failed() {
    showToast(document.querySelector('[data-file-viewer-template]')?.dataset.failed ?? '');
}

/* ---------------------------------------------------------------- PDF drawn with pdf.js (phones and tablets) */

let pdfJs = null;

const loadPdfJs = () => {
    pdfJs ??= Promise.all([
        import('pdfjs-dist/legacy/build/pdf.mjs'),
        import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url'),
    ]).then(([library, worker]) => {
        library.GlobalWorkerOptions.workerSrc = worker.default;

        return library;
    }).catch((error) => {
        pdfJs = null;

        throw error;
    });

    return pdfJs;
};

function pdfReader(box, pageInfo) {
    const state = { document: null, pages: [], zoom: 1, width: 0, active: 0, pinch: null, closed: false, wrap: null };
    const pixelRatio = () => Math.min(window.devicePixelRatio || 1, 2);
    const key = () => `${state.width}|${pixelRatio()}`;

    const layout = () => {
        state.width = Math.round(Math.max(200, box.clientWidth - 10) * state.zoom);
        state.wrap.style.setProperty('--pdf-width', `${state.width}px`);
        state.pages.forEach((page) => page.element.style.setProperty('--pdf-height', `${Math.round(state.width * page.ratio)}px`));
    };

    const setZoom = (zoom, x, y) => {
        const target = clampZoom(zoom);

        if (Math.abs(target - state.zoom) < 0.001) {
            return;
        }

        const { wrap } = state;
        const fx = (box.scrollLeft + x - wrap.offsetLeft) / (wrap.offsetWidth || 1);
        const fy = (box.scrollTop + y - wrap.offsetTop) / (wrap.offsetHeight || 1);
        state.zoom = target;
        layout();
        box.scrollLeft = fx * wrap.offsetWidth + wrap.offsetLeft - x;
        box.scrollTop = fy * wrap.offsetHeight + wrap.offsetTop - y;
    };

    const visibleRect = (page) => {
        const top = page.element.offsetTop;
        const height = page.element.offsetHeight;
        const left = state.wrap.offsetLeft;
        const x0 = Math.max(0, box.scrollLeft - left);
        const x1 = Math.min(state.width, box.scrollLeft + box.clientWidth - left);
        const y0 = Math.max(0, box.scrollTop - top);
        const y1 = Math.min(height, box.scrollTop + box.clientHeight - top);

        return x1 > x0 && y1 > y0 ? { x: x0, y: y0, w: x1 - x0, h: y1 - y0 } : null;
    };

    const regionMode = (page) => state.width * state.width * page.ratio * pixelRatio() ** 2 > MAX_CANVAS_PIXELS;

    const contains = (outer, inner) => outer && inner && outer.x <= inner.x + 1 && outer.y <= inner.y + 1
        && outer.x + outer.w >= inner.x + inner.w - 1 && outer.y + outer.h >= inner.y + inner.h - 1;

    const renderPage = async (page) => {
        page.busy = true;
        state.active++;
        const pageKey = key();

        try {
            const pdfPage = await state.document.getPage(page.number);

            if (state.closed) {
                return;
            }

            const base = pdfPage.getViewport({ scale: 1 });
            const ratio = pixelRatio();
            const pageHeight = state.width * page.ratio;
            const canvas = document.createElement('canvas');
            canvas.className = 'file-viewer__canvas';
            let region = null;

            if (regionMode(page)) {
                const visible = visibleRect(page);

                if (!visible) {
                    return;
                }

                const marginX = box.clientWidth * 0.6;
                const marginY = box.clientHeight * 0.6;
                const x = Math.max(0, visible.x - marginX);
                const y = Math.max(0, visible.y - marginY);
                region = { x, y, w: Math.min(state.width, visible.x + visible.w + marginX) - x, h: Math.min(pageHeight, visible.y + visible.h + marginY) - y };
                let scale = ratio;

                if (region.w * region.h * scale * scale > MAX_CANVAS_PIXELS) {
                    scale = Math.sqrt(MAX_CANVAS_PIXELS / (region.w * region.h));
                }

                canvas.width = Math.max(1, Math.floor(region.w * scale));
                canvas.height = Math.max(1, Math.floor(region.h * scale));
                await pdfPage.render({
                    canvas,
                    viewport: pdfPage.getViewport({ scale: (state.width / base.width) * scale }),
                    transform: [1, 0, 0, 1, -region.x * scale, -region.y * scale],
                }).promise;
                canvas.classList.add('file-viewer__canvas--region');
                canvas.style.setProperty('--region-left', `${(region.x / state.width) * 100}%`);
                canvas.style.setProperty('--region-top', `${(region.y / pageHeight) * 100}%`);
                canvas.style.setProperty('--region-width', `${(region.w / state.width) * 100}%`);
                canvas.style.setProperty('--region-height', `${(region.h / pageHeight) * 100}%`);
            } else {
                const viewport = pdfPage.getViewport({ scale: (state.width / base.width) * ratio });
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                await pdfPage.render({ canvas, viewport }).promise;
            }

            if (!state.closed && pageKey === key()) {
                page.canvas?.remove();
                page.element.append(canvas);
                Object.assign(page, { canvas, key: pageKey, region });
            }
        } catch {
            // An unreadable page stays blank.
        } finally {
            page.busy = false;
            state.active--;

            if (!state.closed) {
                renderVisible();
            }
        }
    };

    const renderVisible = () => {
        if (state.closed || state.pages.length === 0) {
            return;
        }

        const top = box.scrollTop;
        const height = box.clientHeight;
        const currentKey = key();
        let current = 1;
        let best = Infinity;

        state.pages.forEach((page) => {
            const pageTop = page.element.offsetTop;
            const pageHeight = page.element.offsetHeight;
            const distance = Math.abs(pageTop + pageHeight / 2 - (top + height / 2));

            if (distance < best) {
                best = distance;
                current = page.number;
            }

            if (pageTop < top + height * 1.6 && pageTop + pageHeight > top - height * 0.6) {
                if (page.busy || state.active >= 2) {
                    return;
                }

                if (regionMode(page)) {
                    const needed = visibleRect(page);

                    if (needed && (page.key !== currentKey || !contains(page.region, needed))) {
                        renderPage(page);
                    }
                } else if (page.key !== currentKey || page.region) {
                    renderPage(page);
                }
            } else if (page.canvas) {
                page.canvas.remove();
                Object.assign(page, { canvas: null, key: null, region: null });
            }
        });

        pageInfo.textContent = `${current} / ${state.pages.length}`;
    };

    const center = () => [box.clientWidth / 2, box.clientHeight / 2];
    const distance = (a, b) => Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    let frame = 0;
    let lastTap = { time: 0, x: 0, y: 0 };

    const onScroll = () => {
        if (state.pinch || frame) {
            return;
        }

        frame = requestAnimationFrame(() => {
            frame = 0;
            renderVisible();
        });
    };

    const onResize = () => {
        layout();
        renderVisible();
    };

    const onTouchStart = (event) => {
        if (event.touches.length === 2) {
            state.pinch = { distance: distance(event.touches[0], event.touches[1]), zoom: state.zoom };
        }
    };

    const onTouchMove = (event) => {
        if (!state.pinch || event.touches.length !== 2) {
            return;
        }

        event.preventDefault();
        const rect = box.getBoundingClientRect();
        const [a, b] = event.touches;
        setZoom(state.pinch.zoom * distance(a, b) / state.pinch.distance, (a.clientX + b.clientX) / 2 - rect.left, (a.clientY + b.clientY) / 2 - rect.top);
    };

    const onTouchEnd = (event) => {
        if (state.pinch && event.touches.length < 2) {
            state.pinch = null;
            renderVisible();
            lastTap.time = 0;

            return;
        }

        if (event.touches.length || event.changedTouches.length !== 1) {
            return;
        }

        const touch = event.changedTouches[0];
        const now = Date.now();
        const rect = box.getBoundingClientRect();

        if (now - lastTap.time < DOUBLE_TAP_MS && Math.hypot(touch.clientX - lastTap.x, touch.clientY - lastTap.y) < 30) {
            lastTap.time = 0;
            setZoom(nextTapZoom(state.zoom), touch.clientX - rect.left, touch.clientY - rect.top);
            renderVisible();
        } else {
            lastTap = { time: now, x: touch.clientX, y: touch.clientY };
        }
    };

    box.addEventListener('scroll', onScroll, { passive: true });
    box.addEventListener('touchstart', onTouchStart, { passive: true });
    box.addEventListener('touchmove', onTouchMove, { passive: false });
    box.addEventListener('touchend', onTouchEnd, { passive: true });
    window.addEventListener('resize', onResize);

    return {
        async open(buffer) {
            const library = await loadPdfJs();
            const pdf = await library.getDocument({ data: new Uint8Array(buffer), isEvalSupported: false }).promise;

            if (state.closed) {
                pdf.destroy();

                return;
            }

            state.document = pdf;
            const ratios = [];

            for (let number = 1; number <= pdf.numPages; number++) {
                const viewport = (await pdf.getPage(number)).getViewport({ scale: 1 });
                ratios.push(viewport.height / viewport.width);
            }

            state.wrap = document.createElement('div');
            state.wrap.className = 'file-viewer__pages-wrap';
            state.pages = ratios.map((ratio, index) => {
                const element = document.createElement('div');
                element.className = 'file-viewer__page';
                state.wrap.append(element);

                return { number: index + 1, ratio, element, canvas: null, key: null, region: null, busy: false };
            });
            box.replaceChildren(state.wrap);
            layout();
            box.scrollTop = 0;
            box.scrollLeft = 0;
            renderVisible();
        },

        zoom(direction) {
            if (!state.wrap) {
                return;
            }

            const [x, y] = center();
            setZoom(direction > 0 ? state.zoom * ZOOM_STEP : state.zoom / ZOOM_STEP, x, y);
            renderVisible();
        },

        close() {
            state.closed = true;
            window.removeEventListener('resize', onResize);
            state.document?.destroy();
        },
    };
}

/* ---------------------------------------------------------------- File viewer */

/**
 * Opens a file (PDF or image) in the viewer. The file is fetched once: it feeds pdf.js and the « Partager » button.
 */
export async function openFileViewer(url, name) {
    const overlay = mount('[data-file-viewer-template]');

    if (!overlay) {
        return;
    }

    const find = (selector) => overlay.querySelector(selector);
    const frame = find('[data-file-viewer-frame]');
    const image = find('[data-file-viewer-image]');
    const pdfBox = find('[data-file-viewer-pdf]');
    const pageInfo = find('[data-file-viewer-pages]');
    const zoomButtons = overlay.querySelectorAll('[data-file-viewer-zoom]');
    const share = find('[data-file-viewer-share]');
    const loading = find('[data-file-viewer-loading]');
    let objectUrl = null;
    let reader = null;
    let file = null;

    find('[data-file-viewer-title]').textContent = name;

    const close = () => {
        reader?.close();

        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
        }

        overlay.remove();
    };

    overlay.addEventListener('click', async (event) => {
        if (event.target === overlay || event.target.closest('[data-overlay-close], [data-file-viewer-close]')) {
            close();
        } else if (event.target.closest('[data-file-viewer-zoom]')) {
            reader?.zoom(Number(event.target.closest('[data-file-viewer-zoom]').dataset.fileViewerZoom));
        } else if (event.target.closest('[data-file-viewer-share]') && file) {
            try {
                await navigator.share({ files: [file] });
            } catch {
                // Sharing dismissed.
            }
        }
    });

    try {
        const response = await fetchWithTimeout(url, { credentials: 'same-origin' }, { kind: 'file' });

        if (!response.ok) {
            throw new Error(`http ${response.status}`);
        }

        const extension = extensionOf(name);
        const blob = new Blob([await response.arrayBuffer()], { type: VIEWABLE[extension] ?? response.headers.get('Content-Type') ?? 'application/octet-stream' });

        if (!overlay.isConnected) {
            return;
        }

        file = new File([blob], name, { type: blob.type });
        share.hidden = !canShareFile(file);
        loading.hidden = true;

        if (extension === 'pdf' && needsPdfJs()) {
            try {
                pdfBox.hidden = false;
                pageInfo.hidden = false;
                zoomButtons.forEach((button) => { button.hidden = false; });
                reader = pdfReader(pdfBox, pageInfo);
                await reader.open(await blob.arrayBuffer());

                return;
            } catch {
                reader?.close();
                reader = null;
                pdfBox.hidden = true;
                pageInfo.hidden = true;
                zoomButtons.forEach((button) => { button.hidden = true; });
            }
        }

        if (isImage(name)) {
            objectUrl = URL.createObjectURL(blob);
            image.src = objectUrl;
            image.alt = name;
            image.hidden = false;
        } else {
            frame.src = url;
            frame.title = name;
            frame.hidden = false;
        }
    } catch {
        close();
        failed();
    }
}

/* ---------------------------------------------------------------- Photo viewer */

/**
 * @param {Array<{url: string, name: string}>} photos
 */
export function openPhotoViewer(photos, start = 0) {
    const overlay = mount('[data-photo-viewer-template]');

    if (!overlay || photos.length === 0) {
        overlay?.remove();

        return;
    }

    const stage = overlay.querySelector('[data-photo-viewer-stage]');
    const image = overlay.querySelector('[data-photo-viewer-image]');
    const count = overlay.querySelector('[data-photo-viewer-count]');
    const label = overlay.querySelector('[data-photo-viewer-name]');
    let index = Math.max(0, Math.min(start, photos.length - 1));
    let touchX = null;

    const show = () => {
        const photo = photos[index];
        stage.classList.remove('is-zoomed');
        stage.scrollTo(0, 0);
        image.src = photo.url;
        image.alt = photo.name;
        label.textContent = photo.name;
        count.textContent = photos.length > 1 ? `${index + 1} / ${photos.length}` : '';
        overlay.classList.toggle('photo-viewer--single', photos.length < 2);
        [index - 1, index + 1].forEach((neighbour) => {
            if (photos[neighbour]) {
                new Image().src = photos[neighbour].url;
            }
        });
    };

    const move = (step) => {
        const next = index + step;

        if (next >= 0 && next < photos.length) {
            index = next;
            show();
        }
    };

    const onKey = (event) => {
        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowLeft') {
            move(-1);
        } else if (event.key === 'ArrowRight') {
            move(1);
        }
    };

    function close() {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
    }

    overlay.addEventListener('click', (event) => {
        if (event.target.closest('[data-overlay-close], [data-photo-viewer-close]')) {
            close();
        } else if (event.target.closest('[data-photo-viewer-move]')) {
            move(Number(event.target.closest('[data-photo-viewer-move]').dataset.photoViewerMove));
        } else if (event.target === image) {
            stage.classList.toggle('is-zoomed');
        }
    });

    stage.addEventListener('touchstart', (event) => {
        touchX = event.touches.length === 1 ? event.touches[0].clientX : null;
    }, { passive: true });

    stage.addEventListener('touchend', (event) => {
        if (touchX === null || stage.classList.contains('is-zoomed')) {
            return;
        }

        const distance = event.changedTouches[0].clientX - touchX;
        touchX = null;

        if (Math.abs(distance) > SWIPE_DISTANCE) {
            move(distance < 0 ? 1 : -1);
        }
    }, { passive: true });

    document.addEventListener('keydown', onKey);
    show();
}

/* ---------------------------------------------------------------- Open / save sheet */

export function openDeliverySheet(blob, name) {
    const overlay = mount('[data-file-sheet-template]');

    if (!overlay) {
        return;
    }

    const file = new File([blob], name, { type: blob.type });
    const size = document.querySelector('[data-file-sheet-template]')?.dataset.size ?? ':size';
    const share = overlay.querySelector('[data-file-sheet-share]');
    const save = overlay.querySelector('[data-file-sheet-save]');

    overlay.querySelector('[data-file-sheet-name]').textContent = name;
    overlay.querySelector('[data-file-sheet-meta]').textContent = fill(size, { size: Math.max(1, Math.round(blob.size / 1024)) });
    share.hidden = !canShareFile(file);

    if (share.hidden) {
        save.classList.replace('btn--ghost', 'btn--primary');
    }

    overlay.addEventListener('click', async (event) => {
        if (event.target === overlay || event.target.closest('[data-overlay-close]')) {
            overlay.remove();
        } else if (event.target.closest('[data-file-sheet-share]')) {
            try {
                await navigator.share({ files: [file] });
                overlay.remove();
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    failed();
                }
            }
        } else if (event.target.closest('[data-file-sheet-save]')) {
            const link = document.createElement('a');
            const objectUrl = URL.createObjectURL(blob);
            link.href = objectUrl;
            link.download = name;
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
            overlay.remove();
        }
    });
}

/* ---------------------------------------------------------------- Wiring */

const nameOf = (element) => element.dataset.fileName || element.getAttribute('download') || element.getAttribute('title') || element.textContent.trim() || 'file';

const urlOf = (element) => element.dataset.fileUrl || element.getAttribute('href');

async function openLink(link) {
    const url = urlOf(link);
    const name = nameOf(link);

    if (isImage(name)) {
        const group = link.closest('[data-file-group]');
        const links = group ? [...group.querySelectorAll('[data-file-view]')].filter((candidate) => isImage(nameOf(candidate))) : [link];
        openPhotoViewer(links.map((candidate) => ({ url: urlOf(candidate), name: nameOf(candidate) })), Math.max(0, links.indexOf(link)));

        return;
    }

    if (isViewable(name)) {
        openFileViewer(url, name);

        return;
    }

    try {
        openDeliverySheet(await fetchChecked(url, name), name);
    } catch {
        failed();
    }
}

export function initFileViewer() {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest?.('[data-file-view-open]');

        if (opener) {
            event.preventDefault();
            openFileViewer(urlOf(opener), nameOf(opener));

            return;
        }

        if (!isMobileUI()) {
            return;
        }

        const photo = event.target.closest?.('img[data-photo-view]');

        if (photo) {
            event.preventDefault();
            const photos = [...document.querySelectorAll('img[data-photo-view]')];
            openPhotoViewer(photos.map((image) => ({ url: image.dataset.photoView || image.src, name: image.alt })), Math.max(0, photos.indexOf(photo)));

            return;
        }

        const link = event.target.closest?.('a[data-file-view]');

        if (link && !link.closest('.file-viewer')) {
            event.preventDefault();
            event.stopPropagation();
            openLink(link);
        }
    }, true);
}
