/**
 * Buttons with data-copy-target="<id>" copy that field's text to the clipboard and say so in a toast.
 */
export function initCopyButtons() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-target]');

        if (!button) {
            return;
        }

        const field = document.getElementById(button.dataset.copyTarget);

        try {
            await navigator.clipboard.writeText(field?.value ?? '');
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: button.dataset.copied } }));
        } catch {
            field?.select();
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: button.dataset.copyFailed } }));
        }
    });
}
