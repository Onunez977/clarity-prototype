import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * DEV-2596 — Force-login tab credential isolation (H2).
 *
 * Loads the real scoutCredentials module into the page (not an inline reimplementation)
 * and asserts:
 * 1. markTabScoped + setCredential write sessionStorage only
 * 2. clearCredentials on a tab-scoped tab does NOT delete localStorage driver bearer
 * 3. a second tab still sees the driver's localStorage bearer
 *
 * Do not use about:blank — Chromium treats it as an opaque origin and throws
 * SecurityError on localStorage/sessionStorage access (Playwright 1.62 / QA case Q).
 */
test.use({
    storageState: { cookies: [], origins: [] },
});

const MODULE_SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/clarity-scout/lib/scoutCredentials.js'),
    'utf8',
);

const BEARER_KEY = 'scanner_' + 'token';
const USER_KEY = 'scanner_' + 'user';

declare global {
    interface Window {
        __scoutCreds?: {
            markTabScoped: () => boolean;
            clearTabScopeMarker: () => void;
            isTabScoped: () => boolean;
            getCredential: (key: string) => string | null;
            setCredential: (key: string, value: string) => void;
            removeCredential: (key: string) => void;
            clearCredentials: () => void;
        };
    }
}

async function installModule(page: Page): Promise<void> {
    // Same-origin document required for Web Storage APIs.
    await page.goto('/scout/login', { waitUntil: 'domcontentloaded' });
    await page.addScriptTag({
        content: MODULE_SOURCE
            .replace(/export function/g, 'function')
            .replace(/export \{[^}]+\}/g, '')
            + '\nwindow.__scoutCreds = { markTabScoped, clearTabScopeMarker, isTabScoped, getCredential, setCredential, removeCredential, clearCredentials };\n',
    });
}

test('scoutCredentials isolates force-login from the driver localStorage session', async ({ context, page }) => {
    const driverTab = page;
    const forceTab = await context.newPage();

    await installModule(driverTab);
    await installModule(forceTab);

    await driverTab.evaluate(({ bearerKey, userKey }) => {
        localStorage.setItem(bearerKey, 'driver-live-token');
        localStorage.setItem(userKey, JSON.stringify({ id: 1, name: 'Driver' }));
    }, { bearerKey: BEARER_KEY, userKey: USER_KEY });

    const forceWroteLocal = await forceTab.evaluate(({ bearerKey, userKey }) => {
        const creds = window.__scoutCreds;
        if (!creds) {
            return 'module-missing';
        }
        const ok = creds.markTabScoped();
        if (!ok) {
            return 'mark-failed';
        }
        creds.setCredential(bearerKey, 'force-token-b');
        creds.setCredential(userKey, JSON.stringify({ id: 2, name: 'Force B' }));
        return localStorage.getItem(bearerKey);
    }, { bearerKey: BEARER_KEY, userKey: USER_KEY });

    expect(forceWroteLocal).toBe('driver-live-token');

    const forceSessionBearer = await forceTab.evaluate(
        ({ bearerKey }) => sessionStorage.getItem(bearerKey),
        { bearerKey: BEARER_KEY },
    );
    expect(forceSessionBearer).toBe('force-token-b');

    await forceTab.evaluate(() => window.__scoutCreds?.clearCredentials());

    const afterClear = await forceTab.evaluate(({ bearerKey }) => ({
        sessionBearer: sessionStorage.getItem(bearerKey),
        localBearer: localStorage.getItem(bearerKey),
        scoped: sessionStorage.getItem('scout_credential_scope'),
    }), { bearerKey: BEARER_KEY });

    expect(afterClear.sessionBearer).toBeNull();
    expect(afterClear.localBearer).toBe('driver-live-token');
    expect(afterClear.scoped).toBeNull();

    const driverStillHasBearer = await driverTab.evaluate(
        ({ bearerKey }) => localStorage.getItem(bearerKey),
        { bearerKey: BEARER_KEY },
    );
    expect(driverStillHasBearer).toBe('driver-live-token');
});
