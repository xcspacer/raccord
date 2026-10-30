/**
 * Client portal « live » chip: turns red with a « connection lost » text when a refresh fails; the next successful
 * refresh redraws it.
 */
export function initPortalLiveStatus() {
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('request', ({ fail }) => {
            fail(() => {
                const chip = document.querySelector('[data-portal-live]');

                if (!chip) {
                    return;
                }

                chip.classList.add('is-off');
                chip.querySelector('.portal-live__text').textContent = chip.dataset.lost;
            });
        });
    });
}
