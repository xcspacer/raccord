import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Boots the module with a fake page (meta texts) and a fake Livewire, and returns the error handler it registered.
 */
async function boot(texts) {
    vi.resetModules();
    const listeners = {};
    const confirm = vi.fn(() => false);
    const reload = vi.fn();
    let handler;

    vi.stubGlobal('document', {
        addEventListener: (name, callback) => { listeners[name] = callback; },
        querySelector: (selector) => {
            const name = /name="([^"]+)"/.exec(selector)?.[1];

            return name in texts ? { content: texts[name] } : null;
        },
    });
    vi.stubGlobal('window', {
        confirm,
        location: { reload },
        Livewire: { interceptRequest: (callback) => callback({ onError: (fn) => { handler = fn; } }) },
    });

    const { initLivewireErrors } = await import('../../resources/js/components/livewire-errors');
    initLivewireErrors();
    listeners['livewire:init']();

    return { handler, confirm, reload };
}

describe('Livewire error questions', () => {
    const texts = { 'expired-page-question': 'Cette page a expiré. Voulez-vous la recharger ?', 'empty-response-question': 'Réponse vide. Recharger ?' };

    beforeEach(() => vi.unstubAllGlobals());
    afterEach(() => vi.unstubAllGlobals());

    it('asks in the page language when the page has expired, once, and reloads on yes', async () => {
        const { handler, confirm, reload } = await boot(texts);
        const preventDefault = vi.fn();
        confirm.mockReturnValueOnce(true);

        handler({ response: { status: 419, ok: false }, body: '', preventDefault });
        handler({ response: { status: 419, ok: false }, body: '', preventDefault });

        expect(confirm).toHaveBeenCalledTimes(1);
        expect(confirm).toHaveBeenCalledWith(texts['expired-page-question']);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(preventDefault).toHaveBeenCalledTimes(2);
    });

    it('asks about an empty answer and leaves the other errors to Livewire', async () => {
        const { handler, confirm } = await boot(texts);
        const preventDefault = vi.fn();

        handler({ response: { status: 200, ok: true }, body: '  ', preventDefault });
        expect(confirm).toHaveBeenCalledWith(texts['empty-response-question']);

        handler({ response: { status: 500, ok: false }, body: '<html>', preventDefault });
        expect(preventDefault).toHaveBeenCalledTimes(1);
    });

    it('keeps the Livewire behaviour when the page carries no text', async () => {
        const { handler, confirm } = await boot({});
        const preventDefault = vi.fn();

        handler({ response: { status: 419, ok: false }, body: '', preventDefault });

        expect(preventDefault).not.toHaveBeenCalled();
        expect(confirm).not.toHaveBeenCalled();
    });
});
