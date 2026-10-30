import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import type { Browser, Page, TestInfo } from '@playwright/test';
import type { View } from './views';

/**
 * Optional comparison with a reference platform (LEGACY_URL + LEGACY_ADAPTER): the same screen is captured there and
 * compared with this one. The adapter module exports `login(page, baseUrl, username)`, `views` (key → { user, open(page,
 * baseUrl), mask }), and optionally `textMap` ([from, to] pairs applied to the reference texts) and `elements`
 * (role → selector on the reference when it differs from ours).
 */
interface ReferenceView {
    user: string | null;
    open: (page: Page, baseUrl: string) => Promise<void>;
    mask?: string[];
}

interface Adapter {
    login: (page: Page, baseUrl: string, username: string) => Promise<void>;
    views: Record<string, ReferenceView>;
    textMap?: [string, string][];
    elements?: Record<string, string>;
}

export interface Comparison {
    key: string;
    width: number;
    diffRatio: number;
    missingTexts: string[];
    extraTexts: string[];
    textCoverage: number;
    styles: { role: string; property: string; reference: string; ours: string }[];
}

export const referenceUrl = process.env.LEGACY_URL ?? '';
const referenceDir = resolve(process.env.LEGACY_REFERENCE_DIR ?? 'docs/visual-parity/reference');
const reportDir = resolve('test-results/reference');

/** Elements whose look must match, by role: our selector (the reference uses the same unless its adapter says otherwise). */
const elements: Record<string, string> = {
    page: 'body',
    header: '.app-header',
    title: '.title-block h1',
    menu: '.side-nav',
    content: '#content',
    primaryButton: '.btn--primary',
    rolePill: '.role-pill',
};

const styleProperties = ['color', 'background-color', 'font-family', 'font-size', 'font-weight'];

let adapter: Promise<Adapter | null> | null = null;

export function loadAdapter(): Promise<Adapter | null> {
    adapter ??= (async () => {
        if (!referenceUrl || !process.env.LEGACY_ADAPTER) {
            return null;
        }

        return (await import(pathToFileURL(resolve(process.env.LEGACY_ADAPTER)).href)) as Adapter;
    })();

    return adapter;
}

/** Visible texts of the page, one entry per text block, whitespace collapsed. */
function visibleTexts(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const texts = new Set<string>();
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);

        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const parent = node.parentElement;
            const text = (node.textContent ?? '').replace(/\s+/g, ' ').trim();

            if (!parent || text === '' || ['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT'].includes(parent.tagName)) {
                continue;
            }

            const style = getComputedStyle(parent);
            const box = parent.getBoundingClientRect();

            if (style.visibility === 'hidden' || style.display === 'none' || box.width === 0 || box.height === 0 || parent.closest('[hidden], [aria-hidden="true"]')) {
                continue;
            }

            texts.add(text);
        }

        return [...texts];
    });
}

function computedStyles(page: Page, selectors: Record<string, string>, properties: string[]): Promise<Record<string, Record<string, string>>> {
    return page.evaluate(
        ([map, props]) => {
            const result: Record<string, Record<string, string>> = {};

            for (const [role, selector] of Object.entries(map)) {
                const element = [...document.querySelectorAll(selector)].find((candidate) => (candidate as HTMLElement).offsetParent !== null || candidate === document.body);

                if (element) {
                    const style = getComputedStyle(element);
                    result[role] = Object.fromEntries(props.map((property) => [property, style.getPropertyValue(property)]));
                }
            }

            return result;
        },
        [selectors, properties] as const,
    );
}

/** Share of differing pixels (any channel off by more than 40) and a picture of them, computed in the browser. */
async function pixelDiff(page: Page, ours: Buffer, reference: Buffer): Promise<{ ratio: number; image: Buffer }> {
    const result = await page.evaluate(
        async ([a, b]) => {
            const load = (data: string) =>
                new Promise<HTMLImageElement>((done, fail) => {
                    const image = new Image();
                    image.onload = () => done(image);
                    image.onerror = fail;
                    image.src = 'data:image/png;base64,' + data;
                });
            const [first, second] = await Promise.all([load(a), load(b)]);
            const width = Math.max(first.width, second.width);
            const height = Math.max(first.height, second.height);
            const pixels = (image: HTMLImageElement) => {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const context = canvas.getContext('2d') as CanvasRenderingContext2D;
                context.drawImage(image, 0, 0);

                return context.getImageData(0, 0, width, height);
            };
            const left = pixels(first);
            const right = pixels(second);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d') as CanvasRenderingContext2D;
            const out = context.createImageData(width, height);
            let different = 0;

            for (let i = 0; i < left.data.length; i += 4) {
                const delta = Math.max(Math.abs(left.data[i] - right.data[i]), Math.abs(left.data[i + 1] - right.data[i + 1]), Math.abs(left.data[i + 2] - right.data[i + 2]));
                const grey = (left.data[i] + left.data[i + 1] + left.data[i + 2]) / 12;

                if (delta > 40) {
                    different++;
                    out.data.set([255, 0, 64, 255], i);
                } else {
                    out.data.set([grey, grey, grey, 255], i);
                }
            }

            context.putImageData(out, 0, 0);

            return { ratio: different / (width * height), image: canvas.toDataURL('image/png').split(',')[1] };
        },
        [ours.toString('base64'), reference.toString('base64')] as const,
    );

    return { ratio: result.ratio, image: Buffer.from(result.image, 'base64') };
}

/** Text without icons (emoji, symbols), typographic apostrophes and spacing differences; empty when nothing but icons is left. */
const normalise = (text: string): string =>
    text
        .normalize('NFC')
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[\p{Extended_Pictographic}\u2190-\u23FF\u25A0-\u27BF\uFE0F]/gu, '')
        .replace(/\s+/g, ' ')
        .trim();

function applyTextMap(text: string, map: [string, string][]): string {
    return map.reduce((value, [from, to]) => value.replace(new RegExp(`(?<![\\p{L}\\p{N}])${from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\p{L}\\p{N}])`, 'gu'), to), text);
}

/**
 * Captures the same screen on the reference platform (stored in LEGACY_REFERENCE_DIR) and compares it with the page
 * shown here. Returns null when the reference has no such screen.
 */
export async function compareWithReference(browser: Browser, page: Page, view: View, testInfo: TestInfo): Promise<Comparison | null> {
    const loaded = await loadAdapter();
    const reference = loaded?.views[view.key];

    if (!loaded || !reference) {
        return null;
    }

    const viewport = page.viewportSize() ?? { width: 1440, height: 900 };
    const context = await browser.newContext({
        viewport,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        deviceScaleFactor: 1,
        isMobile: viewport.width < 600,
        hasTouch: viewport.width < 600,
    });
    const other = await context.newPage();

    try {
        await other.clock.setFixedTime(new Date(process.env.BROWSER_NOW ?? '2026-10-14T09:30:00+02:00'));

        if (reference.user) {
            await loaded.login(other, referenceUrl, reference.user);
        }

        await reference.open(other, referenceUrl);
        await other.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });

        const referenceShot = await other.screenshot({ mask: (reference.mask ?? []).map((selector) => other.locator(selector)) });
        const ourShot = await page.screenshot({ animations: 'disabled', mask: (view.mask ?? []).map((selector) => page.locator(selector)) });
        const name = `${view.key}-${viewport.width}`;

        mkdirSync(referenceDir, { recursive: true });
        mkdirSync(reportDir, { recursive: true });
        writeFileSync(`${referenceDir}/${name}.png`, referenceShot);

        const diff = await pixelDiff(other, ourShot, referenceShot);
        const map = loaded.textMap ?? [];
        const referenceTexts = new Set((await visibleTexts(other)).map((text) => normalise(applyTextMap(text, map))).filter((text) => /[\p{L}\p{N}]/u.test(text)));
        const ourTexts = new Set((await visibleTexts(page)).map(normalise).filter((text) => /[\p{L}\p{N}]/u.test(text)));
        const missingTexts = [...referenceTexts].filter((text) => !ourTexts.has(text));
        const extraTexts = [...ourTexts].filter((text) => !referenceTexts.has(text));
        const referenceElements = { ...elements, ...(loaded.elements ?? {}) };
        const referenceStyles = await computedStyles(other, referenceElements, styleProperties);
        const ourStyles = await computedStyles(page, elements, styleProperties);
        const styles: Comparison['styles'] = [];

        for (const role of Object.keys(elements)) {
            for (const property of styleProperties) {
                const theirs = referenceStyles[role]?.[property];
                const mine = ourStyles[role]?.[property];

                if (theirs !== undefined && mine !== undefined && theirs.replace(/["']/g, '') !== mine.replace(/["']/g, '')) {
                    styles.push({ role, property, reference: theirs, ours: mine });
                }
            }
        }

        const comparison: Comparison = {
            key: view.key,
            width: viewport.width,
            diffRatio: Math.round(diff.ratio * 1000) / 1000,
            missingTexts,
            extraTexts,
            textCoverage: referenceTexts.size === 0 ? 1 : Math.round(((referenceTexts.size - missingTexts.length) / referenceTexts.size) * 100) / 100,
            styles,
        };

        writeFileSync(`${reportDir}/${name}-diff.png`, diff.image);
        writeFileSync(`${reportDir}/${name}.json`, JSON.stringify(comparison, null, 2));
        await testInfo.attach('reference', { body: referenceShot, contentType: 'image/png' });
        await testInfo.attach('difference', { body: diff.image, contentType: 'image/png' });
        await testInfo.attach('comparison', { body: JSON.stringify(comparison, null, 2), contentType: 'application/json' });

        return comparison;
    } finally {
        await context.close();
    }
}
