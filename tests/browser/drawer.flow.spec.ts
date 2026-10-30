import { expect, test, type Page } from '@playwright/test';
import { stateFile } from './support/accounts';
import { clickAndAnswer, expectToast, settled, trackLivewire } from './support/flow';

/**
 * Acceptance walk through the drawer recheck procedure (« Recollé du plan de tiroir », guided validation) and its
 * warnings. Dataset: PM201 with a drawer plan of 2 drawers — drawer 1: PB0001–PB0004 on module A, PB0005 and « ??? »
 * on module B; drawer 2: PB0006 and PB0007 (names prefixed « 201- »).
 */
test.use({ storageState: stateFile('tech1') });

const current = (page: Page) => page.locator('.drawer__guide .drawer__big');

test('the drawer recheck procedure, step by step', async ({ page }) => {
    trackLivewire(page);
    const save = page.locator('.recheck__savebar button');

    await test.step('1. open: Audit › PM › « Recollé tiroir »: the guided mode starts on drawer 1', async () => {
        await page.goto('/audits/cabinet/PM201');
        await page.getByRole('link', { name: '🔧 Recollé tiroir' }).click();
        await expect(page).toHaveURL(/\/audits\/cabinet\/PM201\/drawer$/);
        await expect(page.locator('.drawer__guide')).toContainText(/Tiroir 1 · PBO 1 \/ \d+/);
        await expect(save).toBeDisabled();
    });

    await test.step('2. check the PBO: position (B2-T1-MA-…), cable and tube shown, « Ce PBO est-il conforme au plan ? »', async () => {
        await expect(page.getByText('Ce PBO est-il conforme au plan ?')).toBeVisible();
        await expect(page.locator('.drawer__guide .drawer__code').first()).toHaveText(/^B2-T1-MA-\d{2} à \d{2}$/);
        await expect(page.locator('.drawer__guide .drawer__info').first()).toContainText(/Fo → .*T\d{2} · .* · F\d{2}/);
    });

    await test.step('3. « Conforme »: one tap and the next PBO shows', async () => {
        const first = await current(page).textContent();
        await page.getByRole('button', { name: '✓ Conforme' }).click();
        await settled(page);
        await expect(page.locator('.drawer__guide')).toContainText(/PBO 2 \//);
        await expect(current(page)).not.toHaveText(first ?? '');
        await expect(save).toBeEnabled();
    });

    await test.step('4. « Passer » leaves a PBO for later, « Précédent » goes back', async () => {
        const skipped = (await current(page).textContent()) ?? '';
        await page.getByRole('button', { name: 'Passer ▶' }).click();
        await settled(page);
        await expect(current(page)).not.toHaveText(skipped);
        await page.getByRole('button', { name: '◀ Précédent' }).click();
        await settled(page);
        await expect(current(page)).toHaveText(skipped);
        await page.getByRole('button', { name: 'Passer ▶' }).click();
        await settled(page);
    });

    await test.step('4. not conform: « Corriger », change the tube colour (« Différent du plan »), add a position, « Appliquer »', async () => {
        const fixed = (await current(page).textContent()) ?? '';
        await page.getByRole('button', { name: '✎ Corriger' }).click();
        await settled(page);
        const sheet = page.locator('.drawer__sheet');
        await expect(sheet).toContainText(`✎ ${fixed}`);
        await expect(sheet.locator('.recheck__palette').first().locator('.recheck__swatch')).toHaveCount(12);

        await sheet.locator('.recheck__palette').first().locator('.recheck__swatch[title="Rose"]').click();
        await settled(page);
        await expect(sheet.locator('.drawer__diff').first()).toContainText('Différent du plan');

        await sheet.getByRole('button', { name: /Ajouter une position/ }).click();
        await settled(page);
        await expect(sheet.locator('.drawer__segment')).toHaveCount(2);
        await sheet.locator('.drawer__remove').last().click();
        await settled(page);
        await expect(sheet.locator('.drawer__segment')).toHaveCount(1);

        await sheet.getByRole('button', { name: '✓ Appliquer' }).click();
        await settled(page);
        await expect(sheet).toHaveCount(0);
        await expect(current(page)).not.toHaveText(fixed);
    });

    await test.step('3. « Tout le tiroir 1 est conforme »: the remaining PBO of the drawer in one tap', async () => {
        const whole = page.getByRole('button', { name: /Tout le tiroir 1 est conforme/ });
        await expect(whole).toBeVisible();
        const question = await clickAndAnswer(page, whole, true);
        expect(question).toMatch(/Confirmer comme conformes les \d+ PBO restants \?/);
    });

    await test.step('Attention: a PBO named « ??? » in the plan must be renamed, it cannot be « Conforme »', async () => {
        for (let i = 0; i < 10 && (await current(page).textContent()) !== '???'; i++) {
            await page.getByRole('button', { name: 'Passer ▶' }).click();
            await settled(page);
        }
        await expect(current(page)).toHaveText('???');
        await expect(page.getByText('Un PBO nommé « ??? » dans le plan : corrigez son nom.')).toBeVisible();
        await expect(page.getByRole('button', { name: '✓ Conforme' })).toHaveCount(0);

        await page.getByRole('button', { name: '✎ Corriger' }).click();
        await settled(page);
        const sheet = page.locator('.drawer__sheet');
        await sheet.locator('select.recheck__select').first().selectOption('__manual');
        await settled(page);
        await sheet.getByPlaceholder('Nom du PBO').fill('201-PB0099');
        await sheet.getByPlaceholder('Nom du PBO').blur();
        await settled(page);
        await sheet.getByRole('button', { name: '✓ Appliquer' }).click();
        await settled(page);
        await expect(sheet).toHaveCount(0);
    });

    await test.step('5. finish: confirm what is left until « Tout est vérifié », then « Enregistrer »', async () => {
        for (let i = 0; i < 10 && (await page.locator('.drawer__done').count()) === 0; i++) {
            const conform = page.getByRole('button', { name: '✓ Conforme' });
            await ((await conform.count()) > 0 ? conform : page.getByRole('button', { name: 'Passer ▶' })).click();
            await settled(page);
        }
        await expect(page.locator('.drawer__done')).toContainText('✓ Tout est vérifié');
        await expect(page.locator('.drawer__done')).toContainText(/\d+ conformes, \d+ modifiés\./);

        await save.click();
        await settled(page);
        await expectToast(page, 'Recollé du tiroir enregistré (PM201 - Plan de tiroir après travaux.xlsx)');
        await expect(save).toBeDisabled();
        await expect(page.locator('.recheck__plan')).toContainText('PM201 - Plan de tiroir après travaux.xlsx');
    });

    await test.step('overview: drawer tabs, legend, search, statuses Conforme / Modifié / À vérifier, modified cells', async () => {
        await page.locator('.drawer__done').getByRole('button', { name: '☰ Vue d’ensemble' }).click();
        await settled(page);
        await expect(page.getByRole('button', { name: 'Tiroir 1' })).toHaveClass(/is-active/);
        await expect(page.getByRole('button', { name: 'Tiroir 2' })).toBeVisible();
        await expect(page.getByText(/Chaque case est une position/)).toBeVisible();
        await expect(page.locator('.drawer__cell.is-changed').first()).toBeVisible();
        await expect(page.locator('.drawer__badge', { hasText: 'Modifié' }).first()).toBeVisible();
        await expect(page.locator('.drawer__badge', { hasText: 'Conforme' }).first()).toBeVisible();

        await page.getByPlaceholder('Rechercher un PBO').fill('PB0099');
        await settled(page);
        const pboCards = page.locator('.drawer__card.is-conform, .drawer__card.is-modified, .drawer__card.is-unchecked');
        await expect(pboCards).toHaveCount(1);
        await expect(pboCards).toContainText('201-PB0099');
        await page.getByPlaceholder('Rechercher un PBO').fill('');
        await settled(page);

        await page.getByRole('button', { name: 'Tiroir 2' }).click();
        await settled(page);
        await expect(page.getByRole('button', { name: 'Tiroir 2' })).toHaveClass(/is-active/);
    });

    await test.step('Attention: a position already taken by another PBO asks to confirm the replacement', async () => {
        await page.getByRole('button', { name: 'Tiroir 1' }).click();
        await settled(page);
        const card = page.locator('.drawer__card.is-conform, .drawer__card.is-modified').first();
        await card.getByRole('button', { name: '✎ Modifier' }).click();
        await settled(page);
        const sheet = page.locator('.drawer__sheet');
        const to = sheet.locator('.drawer__fields label', { hasText: 'À' }).locator('select').first();
        await to.selectOption('24');
        await settled(page);
        await sheet.getByRole('button', { name: '✓ Appliquer' }).click();
        await settled(page);
        await expect(sheet.locator('.field__error')).toContainText('Ces positions sont déjà occupées par :');
        await expect(sheet.getByRole('button', { name: 'Remplacer' })).toBeVisible();
        await sheet.getByRole('button', { name: 'Annuler' }).click();
        await settled(page);
        await expect(sheet).toHaveCount(0);
    });
});
