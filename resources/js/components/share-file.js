import { fetchWithTimeout } from './http';

/**
 * Buttons with data-share-file share the file behind data-share-url (title and text from data-share-title and
 * data-share-text) with the device's share sheet, so it can be attached to a message. Where sharing files is not
 * supported the file is downloaded instead.
 */
export function initShareFile() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-share-file]');

        if (!button) {
            return;
        }

        const { shareUrl, shareTitle, shareText } = button.dataset;

        try {
            const response = await fetchWithTimeout(shareUrl, { credentials: 'same-origin' }, { kind: 'file' });
            const blob = await response.blob();
            const file = new File([blob], `${shareTitle}.pdf`, { type: 'application/pdf' });

            if (navigator.canShare?.({ files: [file] })) {
                await navigator.share({ files: [file], title: shareTitle, text: shareText });

                return;
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
        }

        window.location.assign(shareUrl);
    });
}
