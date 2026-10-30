import { expect, test, type Page } from '@playwright/test';
import { stateFile } from './support/accounts';
import { compareWithReference, referenceUrl } from './support/reference';
import { views, type View } from './support/views';

const now = new Date(process.env.BROWSER_NOW ?? '2026-10-14T09:30:00+02:00');

/** Largest share of differing pixels accepted against the reference platform, and smallest share of its texts found here. */
const referenceTolerance = { diffRatio: 0.2, textCoverage: 0.6 };

/**
 * Switches the agency of the session from a quiet page: a request still running when the switch lands would write the
 * session back with the previous agency.
 */
async function chooseAgency(page: Page, agency: NonNullable<View['agency']>): Promise<void> {
    await page.goto('/admin/holidays');
    await page.waitForLoadState('networkidle');
    await page.evaluate(async (id) => {
        const form = document.querySelector<HTMLFormElement>(`form[action$="/agency/${id}"]`);

        if (form) {
            await fetch(form.action, { method: 'POST', body: new FormData(form), redirect: 'manual' });
        }
    }, agency === 'nord' ? 1 : 2);
}

/** Waits until the page is still: network idle, fonts and images loaded. */
async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
    await page.evaluate(async () => {
        await document.fonts.ready;
        const pending = [...document.images].filter((image) => !image.complete);
        await Promise.all(
            pending.map(
                (image) =>
                    new Promise((done) => {
                        image.addEventListener('load', done, { once: true });
                        image.addEventListener('error', done, { once: true });
                    }),
            ),
        );
    });
}

for (const view of views) {
    test.describe(view.key, () => {
        if (view.user) {
            test.use({ storageState: stateFile(view.user) });
        }

        test(`${view.key} looks as agreed`, async ({ page, browser }, testInfo) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.clock.setFixedTime(now);

            if (view.agency) {
                await chooseAgency(page, view.agency);
            }

            const response = await page.goto(view.path);
            expect(response?.status(), `${view.path} answers`).toBeLessThan(400);
            await expect(page.locator(view.ready).first()).toBeVisible();
            await view.prepare?.(page);
            await settle(page);

            await expect(page).toHaveScreenshot(`${view.key}.png`, { mask: (view.mask ?? []).map((selector) => page.locator(selector)) });
            expect(errors, 'no script error').toEqual([]);

            if (referenceUrl) {
                const comparison = await compareWithReference(browser, page, view, testInfo);

                if (comparison) {
                    expect.soft(comparison.diffRatio, 'share of pixels different from the reference').toBeLessThanOrEqual(referenceTolerance.diffRatio);
                    expect.soft(comparison.textCoverage, `reference texts missing here: ${comparison.missingTexts.slice(0, 15).join(' | ')}`).toBeGreaterThanOrEqual(referenceTolerance.textCoverage);
                    expect.soft(comparison.styles, 'colours and fonts of the key elements').toEqual([]);
                }
            }
        });
    });
}
