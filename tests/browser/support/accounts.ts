import type { Account } from './views';

export const password = process.env.BROWSER_PASSWORD ?? 'Visual-Parity-2026';

/** Saved sign-in of each account (written by auth.setup.ts, never committed). */
export const stateFile = (account: Account): string => `test-results/browser-auth/${account}.json`;
