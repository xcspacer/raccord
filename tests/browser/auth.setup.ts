import { expect, test as setup } from '@playwright/test';
import { password, stateFile } from './support/accounts';

for (const username of ['admin', 'tech1'] as const) {
    setup(`sign in as ${username}`, async ({ page }) => {
        await page.goto('/login');
        await page.locator('#username').fill(username);
        await page.locator('#password').fill(password);
        await page.locator('#password').press('Enter');
        await expect(page).not.toHaveURL(/\/login$/);
        await page.context().storageState({ path: stateFile(username) });
    });
}

setup('sign in to the client portal', async ({ page }) => {
    await page.goto('/portal/opale/login');
    await page.locator('#portal-username').fill('client');
    await page.locator('#portal-password').fill(password);
    await page.locator('#portal-password').press('Enter');
    await expect(page).not.toHaveURL(/\/login$/);
    await page.context().storageState({ path: stateFile('client') });
});
