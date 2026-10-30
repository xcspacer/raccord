import { expect, type Locator, type Page, type Request } from '@playwright/test';

const pending = new WeakMap<Page, { count: number }>();
const isLivewireUpdate = (request: Request): boolean => /\/livewire[^/]*\/update/.test(request.url());

/** Counts the Livewire round trips in flight on the page (call once, before the first action). */
export function trackLivewire(page: Page): void {
    const state = { count: 0 };
    pending.set(page, state);
    page.on('request', (request) => {
        if (isLivewireUpdate(request)) {
            state.count++;
        }
    });

    const done = (request: Request): void => {
        if (isLivewireUpdate(request)) {
            state.count--;
        }
    };
    page.on('requestfinished', done);
    page.on('requestfailed', done);
}

/** Waits until the Livewire round trips started by the last action are answered and applied. */
export async function settled(page: Page): Promise<void> {
    await page.waitForTimeout(120);
    await expect.poll(() => pending.get(page)?.count ?? 0, { timeout: 15_000 }).toBe(0);
    await page.waitForTimeout(80);
}

/** Clicks and answers the browser confirmation it opens (accept or dismiss), returning its message. */
export async function clickAndAnswer(page: Page, target: Locator, accept: boolean): Promise<string> {
    let message = '';
    const answered = page.waitForEvent('dialog').then(async (dialog) => {
        message = dialog.message();
        await (accept ? dialog.accept() : dialog.dismiss());
    });
    await Promise.all([answered, target.click()]);
    await settled(page);

    return message;
}

/** The last message shown in the toast. */
export async function expectToast(page: Page, text: string | RegExp): Promise<void> {
    await expect(page.locator('[data-toast]')).toContainText(text);
}
