/**
 * Phones: the planning pages have a select to move between views; choosing one navigates there.
 */
export function initViewSelect() {
    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-view-select]');

        if (select && select.value) {
            window.Livewire?.navigate(select.value);
        }
    });
}
