/**
 * Server-driven confirmation with a dynamic message, shown with the browser's native dialog (as the
 * legacy platform did). A Livewire component dispatches:
 *   $this->dispatch('confirm-action', message: '…', method: 'confirmDeparture', params: [...]);
 * and the method is called back on that same component only when the user accepts.
 */
export function initConfirmActions() {
    document.addEventListener('livewire:init', () => {
        window.Livewire.on('confirm-action', ({ message, method, params = [], componentId }) => {
            const component = window.Livewire.find(componentId);

            if (component && window.confirm(message)) {
                component.call(method, ...params);
            }
        });
    });
}
