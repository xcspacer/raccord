import type { Page } from '@playwright/test';

/** Accounts of the browser-test dataset (every one uses BROWSER_PASSWORD). */
export type Account = 'admin' | 'tech1' | 'client';

export interface View {
    /** Name of the screenshot and key of the same screen in the optional reference adapter. */
    key: string;
    /** Signed-in account, or null for a guest page. */
    user: Account | null;
    path: string;
    /** Agency to work in before opening the page (managers working for every agency). */
    agency?: 'nord' | 'sud';
    /** Element that must be visible before the screenshot. */
    ready: string;
    /** Steps after the page has loaded (opening a tab…). */
    prepare?: (page: Page) => Promise<void>;
    /** Areas that change on every run (live maps, animated chips). */
    mask?: string[];
}

export const views: View[] = [
    { key: 'login', user: null, path: '/login', ready: '#username' },
    { key: 'home-admin', user: 'admin', path: '/', agency: 'nord', ready: '#content', mask: ['.map__stage canvas'] },
    { key: 'home-technician', user: 'tech1', path: '/', ready: '.app-header' },
    { key: 'planning-timeline', user: 'admin', path: '/planning', agency: 'nord', ready: '#content' },
    { key: 'planning-tasks', user: 'admin', path: '/planning/tasks', agency: 'sud', ready: 'main' },
    { key: 'work-sites', user: 'admin', path: '/work-sites', agency: 'nord', ready: 'main' },
    { key: 'absences', user: 'admin', path: '/absences', agency: 'sud', ready: 'main' },
    { key: 'my-space', user: 'tech1', path: '/my-space', ready: 'main' },
    { key: 'my-space-vehicle', user: 'tech1', path: '/my-space?tab=vehicle', ready: 'main' },
    { key: 'expense-reports', user: 'tech1', path: '/expense-reports', ready: 'main' },
    { key: 'equipment', user: 'admin', path: '/equipment', agency: 'nord', ready: 'main' },
    { key: 'equipment-person', user: 'admin', path: '/equipment/users/4', agency: 'nord', ready: 'main' },
    { key: 'audits-optical', user: 'admin', path: '/audits/optical', agency: 'sud', ready: 'main' },
    { key: 'audits-full', user: 'admin', path: '/audits/full', agency: 'sud', ready: 'main' },
    { key: 'audit-pm', user: 'admin', path: '/audits/cabinet/PM101', agency: 'sud', ready: 'main' },
    { key: 'audit-box', user: 'tech1', path: '/audits/cabinet/PM101/box?name=BPE-0001', ready: '.site-screen__name' },
    { key: 'audit-recheck', user: 'tech1', path: '/audits/cabinet/PM101/recheck/1', ready: 'main' },
    { key: 'audit-drawer', user: 'tech1', path: '/audits/cabinet/PM101/drawer', ready: 'main' },
    { key: 'audit-weekly-timesheet', user: 'admin', path: '/audits/weekly-timesheet', agency: 'sud', ready: 'main' },
    { key: 'client-control', user: 'admin', path: '/client-control', agency: 'sud', ready: 'main' },
    { key: 'portal-login', user: null, path: '/portal/opale/login', ready: '#portal-username' },
    { key: 'portal-home', user: 'client', path: '/portal/opale', ready: 'main' },
    { key: 'media-library', user: 'admin', path: '/media-library', agency: 'sud', ready: 'main' },
    { key: 'billing', user: 'admin', path: '/billing', agency: 'nord', ready: 'main' },
    { key: 'otdr', user: 'admin', path: '/otdr', agency: 'nord', ready: 'main' },
    { key: 'fibre-code', user: 'admin', path: '/fibre-code', agency: 'nord', ready: 'main' },
    { key: 'admin-users', user: 'admin', path: '/admin/users', agency: 'nord', ready: 'main' },
    { key: 'admin-access', user: 'admin', path: '/admin/access', agency: 'nord', ready: 'main' },
    { key: 'admin-logs', user: 'admin', path: '/admin/logs', agency: 'nord', ready: 'main' },
    { key: 'admin-settings', user: 'admin', path: '/admin/settings', agency: 'nord', ready: 'main' },
    { key: 'admin-agencies', user: 'admin', path: '/admin/agencies', agency: 'nord', ready: 'main' },
    { key: 'admin-companies', user: 'admin', path: '/admin/companies', agency: 'nord', ready: 'main' },
    { key: 'admin-holidays', user: 'admin', path: '/admin/holidays', agency: 'nord', ready: 'main' },
];
