import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchWithTimeout, TIMEOUTS, timeoutFor } from '../../resources/js/components/http';
import { clampZoom, extensionOf, isImage, isViewable, nextTapZoom, zipLooksComplete } from '../../resources/js/components/file-viewer';

describe('request time limits', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('gives data 12 s, files 30 s and sending 2 min', () => {
        expect(TIMEOUTS).toEqual({ data: 12000, file: 30000, upload: 120000 });
        expect(timeoutFor(undefined)).toBe(12000);
        expect(timeoutFor({ method: 'get' }, 'file')).toBe(30000);
        expect(timeoutFor({ method: 'POST' }, 'data')).toBe(120000);
        expect(timeoutFor({ method: 'PUT' }, 'file')).toBe(120000);
    });

    it('aborts a request that takes longer than its limit', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn((input, init) => new Promise((resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(init.signal.reason));
        }));
        vi.stubGlobal('fetch', fetch);

        const request = fetchWithTimeout('/data');
        const outcome = expect(request).rejects.toMatchObject({ name: 'AbortError' });
        await vi.advanceTimersByTimeAsync(11999);
        expect(fetch.mock.calls[0][1].signal.aborted).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        await outcome;
    });

    it('keeps the caller signal working and clears the timer once answered', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn((input, init) => new Promise((resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(new DOMException('stop', 'AbortError')));
        })));

        const caller = new AbortController();
        const request = fetchWithTimeout('/file', { signal: caller.signal }, { kind: 'file' });
        caller.abort();
        await expect(request).rejects.toMatchObject({ name: 'AbortError' });

        vi.stubGlobal('fetch', vi.fn(async () => new Response('ok')));
        const answered = await fetchWithTimeout('/data');
        expect(await answered.text()).toBe('ok');
        expect(vi.getTimerCount()).toBe(0);
    });
});

describe('file viewer', () => {
    it('reads the extension of a name or a URL', () => {
        expect(extensionOf('Plan PM12.PDF')).toBe('pdf');
        expect(extensionOf('/files/abc.kmz?download=1')).toBe('kmz');
        expect(extensionOf('no-extension')).toBe('');
        expect(extensionOf(null)).toBe('');
    });

    it('shows PDF and images, sends the other files to the open / save sheet', () => {
        expect(isViewable('doc.pdf')).toBe(true);
        expect(isViewable('photo.JPEG')).toBe(true);
        expect(isViewable('positions.kmz')).toBe(false);
        expect(isViewable('plan.xlsx')).toBe(false);
        expect(isImage('photo.webp')).toBe(true);
        expect(isImage('doc.pdf')).toBe(false);
    });

    it('zooms a PDF page 1 → 3 → 10 → 1 on double tap, between 1 and 40', () => {
        expect(nextTapZoom(1)).toBe(3);
        expect(nextTapZoom(3)).toBe(10);
        expect(nextTapZoom(10)).toBe(1);
        expect(clampZoom(0.5)).toBe(1);
        expect(clampZoom(80)).toBe(40);
    });

    it('accepts a ZIP-based file only when it is complete', () => {
        const complete = new Uint8Array(40);
        complete.set([0x50, 0x4b, 3, 4]);
        complete.set([0x50, 0x4b, 5, 6], 18);

        expect(zipLooksComplete(complete)).toBe(true);
        expect(zipLooksComplete(complete.slice(0, 17))).toBe(false);
        expect(zipLooksComplete(new Uint8Array(40))).toBe(false);
    });
});
