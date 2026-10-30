/*
 * Browser tests: screenshots of every main screen (desktop 1440, tablet 1024, phone 390) and the acceptance flows of
 * the box and drawer rechecks. Everything runs inside the application container.
 *
 * 1. Fill the browser-test database (default `visual`) with the invented dataset, its files in BROWSER_STORAGE_PATH.
 *    The dataset tooling is local and not versioned: docs/visual-parity/seed-rebuild.sh (run on the host).
 * 2. vendor/bin/sail npm run build
 * 3. vendor/bin/sail npm run test:browser                 (compare with the committed screenshots)
 *    vendor/bin/sail npm run test:browser -- --update-snapshots   (accept a deliberate visual change)
 *
 * The acceptance flows change the data: refill the database before running them again, or set BROWSER_SEED to a
 * command that does it (run once before the tests).
 *
 * Optional reference comparison: set LEGACY_URL (a local copy of the previous platform, same invented data) and
 * LEGACY_ADAPTER (module that opens each screen there). Each screen is then also captured there, stored in
 * LEGACY_REFERENCE_DIR and compared (pixel difference, visible texts, colours of key elements); the report lands in
 * test-results/.
 *
 * Environment: BROWSER_URL (default http://127.0.0.1:8081, started here when nothing answers), BROWSER_DB,
 * BROWSER_STORAGE_PATH, BROWSER_PASSWORD, BROWSER_NOW (the day every screen shows).
 */
import { defineConfig, devices } from '@playwright/test';

const port = 8081;
const baseURL = process.env.BROWSER_URL ?? `http://127.0.0.1:${port}`;
const now = process.env.BROWSER_NOW ?? '2026-10-14T09:30:00+02:00';
const storagePath = process.env.BROWSER_STORAGE_PATH ?? `${process.cwd()}/docs/visual-parity/storage`;

const sizes = {
    desktop: { width: 1440, height: 900 },
    tablet: { width: 1024, height: 768 },
    phone: { width: 390, height: 844 },
};

const common = {
    baseURL,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    deviceScaleFactor: 1,
};

export default defineConfig({
    testDir: 'tests/browser',
    snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{arg}{ext}',
    outputDir: 'test-results/browser',
    globalSetup: './tests/browser/support/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 90_000,
    reporter: [['list'], ['html', { outputFolder: 'test-results/browser-report', open: 'never' }]],
    expect: {
        timeout: 15_000,
        toHaveScreenshot: { maxDiffPixelRatio: 0.01, animations: 'disabled', caret: 'hide' },
    },
    use: {
        ...common,
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        { name: 'desktop', dependencies: ['setup'], testMatch: /screens\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...common, viewport: sizes.desktop } },
        { name: 'tablet', dependencies: ['setup'], testMatch: /screens\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...common, viewport: sizes.tablet } },
        { name: 'phone', dependencies: ['setup'], testMatch: /screens\.spec\.ts/, use: { ...common, viewport: sizes.phone, isMobile: true, hasTouch: true } },
        { name: 'acceptance', dependencies: ['setup'], testMatch: /\.flow\.spec\.ts/, use: { ...common, viewport: sizes.phone, isMobile: true, hasTouch: true } },
    ],
    webServer: {
        command: `php ../artisan cache:clear --quiet && BROWSER_TEST_EPOCH=$(date +%s) php -S 0.0.0.0:${port} ../tests/browser/support/server.php`,
        cwd: 'public',
        url: `${baseURL}/up`,
        reuseExistingServer: true,
        timeout: 30_000,
        stdout: 'ignore',
        env: {
            DB_DATABASE: process.env.BROWSER_DB ?? 'visual',
            LARAVEL_STORAGE_PATH: storagePath,
            SESSION_EXPIRE_ON_CLOSE: 'true',
            BROWSER_TEST_NOW: now,
            PHP_CLI_SERVER_WORKERS: '4',
        },
    },
});
