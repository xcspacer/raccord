import { execSync } from 'node:child_process';

/** Refills the browser-test database first when BROWSER_SEED names the command that does it. */
export default function globalSetup(): void {
    const seed = process.env.BROWSER_SEED;

    if (seed) {
        execSync(seed, { stdio: 'inherit' });
    }
}
