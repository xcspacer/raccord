/**
 * Green notification at the bottom of the screen. Livewire components trigger it with
 * $this->dispatch('toast', message: ...).
 */
let hideTimer;

export function showToast(message) {
    const toast = document.querySelector('[data-toast]');

    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => toast.classList.remove('is-visible'), 3500);
}

export function initToasts() {
    window.addEventListener('toast', (event) => showToast(event.detail?.message ?? ''));
}
