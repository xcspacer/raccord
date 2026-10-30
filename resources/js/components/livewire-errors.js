/**
 * Livewire asks with English texts when a page has expired (419) or the server answered nothing; the same questions
 * are asked here in the user's language, read from the page (<meta name="expired-page-question"> and
 * <meta name="empty-response-question">, see the head-meta partial).
 */
const text = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? '';

let expired = false;

export function initLivewireErrors() {
    document.addEventListener('livewire:init', () => {
        window.Livewire.interceptRequest(({ onError }) => {
            onError(({ response, body, preventDefault }) => {
                const question = response.status === 419
                    ? text('expired-page-question')
                    : (response.ok && String(body ?? '').trim() === '' ? text('empty-response-question') : '');

                if (question === '') {
                    return;
                }

                preventDefault();

                if (response.status === 419) {
                    if (expired) {
                        return;
                    }

                    expired = true;
                }

                if (window.confirm(question)) {
                    window.location.reload();
                }
            });
        });
    });
}
