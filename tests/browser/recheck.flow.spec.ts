import { expect, test } from '@playwright/test';
import { stateFile } from './support/accounts';
import { clickAndAnswer, expectToast, settled, trackLivewire } from './support/flow';

/**
 * Acceptance walk through the box recheck procedure (« Recollé du plan de boîte », optical mode), chapter by chapter,
 * plus each row of its « Problèmes fréquents » table. Dataset: PM201, box BPE-0011 with a two-cassette Excel plan
 * (2 × 12 fibres, F11–F12 of the first cassette waiting), box BPE-0012 without a plan.
 */
test.use({ storageState: stateFile('tech1') });
test.describe.configure({ mode: 'serial' });

const manualCable = 'BPE-0011 → 201-PB0009 · 120 m - 12Fo';

test('the box recheck procedure, step by step', async ({ page }) => {
    trackLivewire(page);
    const verdictOk = page.locator('.recheck__verdict-button.is-ok');
    const verdictBad = page.locator('.recheck__verdict-button.is-bad');
    const counts = page.locator('.recheck__count');
    const save = page.locator('.recheck__savebar button');
    const cassettes = page.locator('.recheck__cassette');
    const line = (index: number) => page.locator(`.recheck__line[wire\\:key="line-${index}"]`);

    await test.step('3. open: Audit (optical) › PM › box › « Recollé » (only on a box with an Excel plan)', async () => {
        await page.goto('/audits/optical');
        await page.locator('a.audit__card', { hasText: 'PM201' }).click();
        await expect(page.locator('a.audit__box', { hasText: 'BPE-0012' })).toBeVisible();

        await page.locator('a.audit__box', { hasText: 'BPE-0012' }).click();
        await expect(page.locator('.site-screen__name')).toHaveText('BPE-0012');
        await expect(page.getByRole('link', { name: /Recollé/ })).toHaveCount(0);

        await page.goto('/audits/cabinet/PM201/box?name=BPE-0011');
        await expect(page.locator('.site-screen__name')).toHaveText('BPE-0011');
        await page.getByRole('link', { name: /Recollé/ }).click();
        await expect(page).toHaveURL(/\/audits\/cabinet\/PM201\/recheck\/\d+$/);
        await expect(page.locator('.recheck__plan')).toContainText('Plan : BPE-0011-80201.xlsx');
    });

    await test.step('4. read the screen: back, verdict, counters, « Tout » / « À faire (n) » filters', async () => {
        await expect(page.getByRole('button', { name: '← Retour' })).toBeVisible();
        await expect(verdictOk).toHaveText('✓ Conforme au plan de boîte');
        await expect(verdictBad).toHaveText('✗ Non conforme');
        await expect(page.getByText('Aucune modification ? Un clic : l’onglet RECNOK sera une copie exacte de l’onglet BOITE.')).toBeVisible();
        await expect(counts).toHaveText(/2\s*ATTENTE\s*·\s*22\s*EPISSURE\s*·\s*0\s*PASSAGE/);
        await expect(cassettes).toHaveCount(2);

        await page.getByRole('button', { name: 'À faire (2)' }).click();
        await settled(page);
        await expect(cassettes).toHaveCount(1);
        await expect(page.locator('.recheck__line')).toHaveCount(2);

        await page.getByRole('button', { name: 'Tout', exact: true }).click();
        await settled(page);
        await expect(page.locator('.recheck__line')).toHaveCount(24);
    });

    await test.step('5. the box is conform: confirmation, RECNOK = exact copy, green verdict', async () => {
        await expect(save).toBeDisabled();
        const question = await clickAndAnswer(page, verdictOk, true);
        expect(question).toContain('Le plan de soudure de cette boîte est-il conforme au plan de boîte original ?');

        await expectToast(page, 'Conforme au plan : copie enregistrée (BPE-0011_RECNOK.xlsx)');
        await expect(verdictOk).toHaveClass(/is-on/);
        await expect(verdictBad).toHaveClass(/is-dim/);
        await expect(page.getByText('Conforme : RECNOK = copie exacte de BOITE.')).toBeVisible();
        await expect(page.locator('.recheck__plan')).toContainText('BPE-0011_RECNOK.xlsx');
    });

    await test.step('13. « Enregistrer » is greyed while nothing changed since the last save', async () => {
        await expect(save).toBeDisabled();
    });

    await test.step('6.1 a line changed: counters follow, the box-wide « Conforme » disappears, saving is possible', async () => {
        await line(0).getByRole('button', { name: 'PASSAGE' }).click();
        await settled(page);
        await expect(counts).toHaveText(/2\s*ATTENTE\s*·\s*21\s*EPISSURE\s*·\s*1\s*PASSAGE/);
        await expect(verdictOk).toHaveCount(0);
        await expect(save).toBeEnabled();
    });

    await test.step('6.2 – 6.3 a waiting fibre spliced: colour palette (Fnn / Tnn), « Changer », manual cable B', async () => {
        const waiting = line(10);
        await expect(waiting.getByText('Fibre B')).toHaveCount(0);

        await waiting.getByRole('button', { name: 'EPISSURE' }).click();
        await settled(page);
        await expect(waiting.locator('.recheck__palette')).toHaveCount(2);
        await expect(waiting.locator('.recheck__palette').first().locator('.recheck__swatch')).toHaveCount(12);
        await expect(waiting.locator('.recheck__swatch[title="Violet"]').first()).toHaveText('5');

        await waiting.locator('.recheck__palette').first().locator('.recheck__swatch[title="Violet"]').click();
        await settled(page);
        await expect(waiting.locator('.recheck__row')).toContainText('F05');
        await expect(waiting.getByRole('button', { name: 'Changer' })).toBeVisible();

        await waiting.locator('.recheck__palette').first().locator('.recheck__swatch[title="Rouge"]').click();
        await settled(page);
        await expect(waiting.locator('.recheck__row')).toContainText('T01');

        await waiting.locator('select.recheck__select').last().selectOption('__manual');
        await settled(page);
        await expect(page.getByText('Nom du câble (ex. BPE-0047-FT → 106-PB0090 · 120 m - 12Fo) :')).toBeVisible();
        await page.locator('#manual-cable').fill(manualCable);
        await page.getByRole('button', { name: 'Appliquer' }).click();
        await settled(page);
        await expect(waiting.locator('select.recheck__select').last()).toHaveValue(manualCable);
        await expect(waiting.locator('optgroup[label="Câbles saisis à la main"] option', { hasText: manualCable })).toHaveCount(1);
    });

    await test.step('13. back to ATTENTE: the Fibre B / Tube B / Câble B settings disappear', async () => {
        const waiting = line(10);
        await waiting.getByRole('button', { name: 'ATTENTE' }).click();
        await settled(page);
        await expect(waiting.getByText('Fibre B')).toHaveCount(0);
        await expect(waiting.locator('select.recheck__select')).toHaveCount(0);
    });

    await test.step('6.4 « EN DROIT »: the whole cassette spliced colour on colour', async () => {
        const second = cassettes.nth(1);
        await second.getByRole('button', { name: 'PASSAGE' }).first().click();
        await settled(page);
        await expect(second.locator('.recheck__line.is-pass')).toHaveCount(12);

        await second.getByRole('button', { name: 'EN DROIT' }).click();
        await settled(page);
        await expect(second.getByRole('button', { name: 'EN DROIT' })).toHaveClass(/is-on/);
        await expect(second.locator('.recheck__line.is-splice')).toHaveCount(12);
        await expect(second.locator('.recheck__line').nth(6).locator('.recheck__row')).toContainText(/T02\s*F07\s*→\s*F07\s*T02/);
    });

    await test.step('7. change the input of one fibre (pencil) and of a whole cassette', async () => {
        const fibre = line(1);
        await fibre.getByTitle('Modifier l’entrée (câble A, tube A, fibre A)').click();
        await settled(page);
        await expect(fibre.locator('.recheck__input')).toBeVisible();
        await fibre.locator('.recheck__input').getByRole('button', { name: 'Changer' }).first().click();
        await settled(page);
        await fibre.locator('.recheck__input .recheck__swatch[title="Vert"]').click();
        await settled(page);
        await expect(fibre.locator('.recheck__row')).toContainText('F03');
        await expect(fibre.locator('.recheck__input select')).toBeVisible();

        await fibre.getByTitle('Modifier l’entrée (câble A, tube A, fibre A)').click();
        await settled(page);
        await expect(fibre.locator('.recheck__input')).toHaveCount(0);
        await expect(cassettes.first().getByLabel('Câble A (entrée)')).toBeVisible();
    });

    await test.step('8. a second cable B for part of a cassette (« + Câble B »)', async () => {
        const first = cassettes.first();
        await first.getByRole('button', { name: '＋ Câble B' }).click();
        await settled(page);
        const panel = first.locator('.recheck__extra');
        await expect(panel).toContainText('Câble B supplémentaire');

        await panel.locator('select').selectOption(manualCable);
        await settled(page);
        for (const fibre of ['F07', 'F08', 'F09', 'F10']) {
            await panel.locator('.recheck__extra-line', { hasText: fibre }).click();
            await settled(page);
        }
        await panel.getByRole('button', { name: '✓ Appliquer' }).click();
        await settled(page);
        await expect(panel).toBeVisible();
        await expect(line(7).locator('select.recheck__select').last()).toHaveValue(manualCable);

        await panel.getByRole('button', { name: 'Toutes' }).click();
        await settled(page);
        await expect(panel.locator('.recheck__extra-line.is-on')).toHaveCount(12);
        await first.getByRole('button', { name: '＋ Câble B' }).click();
        await settled(page);
        await expect(first.locator('.recheck__extra')).toHaveCount(0);
    });

    await test.step('5. conform cassette by cassette: the lines go back to the plan', async () => {
        const second = cassettes.nth(1);
        const question = await clickAndAnswer(page, second.getByRole('button', { name: '✓ Conforme' }), true);
        expect(question).toContain('Rétablir cette cassette telle qu’au plan de boîte original ?');
        await expect(second.getByRole('button', { name: '✓ Conforme' })).toHaveClass(/is-on/);
    });

    await test.step('9. not conform: red verdict, paper recheck reminder, tapping again goes back to neutral', async () => {
        await verdictBad.click();
        await settled(page);
        await expect(verdictBad).toHaveClass(/is-on/);
        await expect(page.getByText('Non conforme : corrigez les lignes et / ou ajoutez la photo du recollé papier.')).toBeVisible();
        await expect(page.getByText(/Recollé papier \(photo\)/)).toBeVisible();

        await verdictBad.click();
        await settled(page);
        await expect(verdictBad).not.toHaveClass(/is-on/);

        await verdictBad.click();
        await settled(page);
        await expect(verdictBad).toHaveClass(/is-on/);
    });

    await test.step('10. save: « Recollé enregistré (…_RECNOK.xlsx) », nothing left to save', async () => {
        await save.click();
        await settled(page);
        await expectToast(page, 'Recollé enregistré (BPE-0011_RECNOK.xlsx)');
        await expect(save).toBeDisabled();
    });

    await test.step('13. long plans: a cassette folds and unfolds from its name, « À faire » unfolds them all', async () => {
        const toggle = cassettes.first().locator('.recheck__toggle');
        await toggle.click();
        await settled(page);
        await expect(toggle).toContainText('▸');
        await expect(cassettes.first().locator('.recheck__line')).toHaveCount(0);

        await page.getByRole('button', { name: /^À faire/ }).click();
        await settled(page);
        await expect(cassettes.first().locator('.recheck__line').first()).toBeVisible();
        await page.getByRole('button', { name: 'Tout', exact: true }).click();
        await settled(page);

        if ((await toggle.textContent())?.includes('▸')) {
            await toggle.click();
            await settled(page);
        }

        await expect(toggle).toContainText('▾');
        await expect(line(2)).toBeVisible();
    });

    await test.step('11. leaving with unsaved changes asks first; the draft is kept on the server', async () => {
        await line(2).getByRole('button', { name: 'PASSAGE' }).click();
        await settled(page);
        const url = page.url();

        const question = await clickAndAnswer(page, page.getByRole('button', { name: '← Retour' }), false);
        expect(question).toBe('Modifications non enregistrées. Quitter quand même ?');
        await expect(page).toHaveURL(url);

        await page.reload();
        await expect(line(2)).toHaveClass(/is-pass/);
        await expect(save).toBeEnabled();

        await clickAndAnswer(page, page.getByRole('button', { name: '← Retour' }), true);
        await expect(page.locator('.site-screen__name')).toHaveText('BPE-0011');
    });

    await test.step('9. the box screen offers the paper recheck photo once the box is not conform', async () => {
        await expect(page.getByText('Recollé papier (photo)', { exact: false }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: /Recollé/ })).toBeVisible();
    });
});
