import { expect, test } from '@playwright/test';
import { assertNotProduction, loginAsAdmin, rewriteForcedHttpsForLocalHttp } from './helpers/auth';

/**
 * Baseline smoke for the Filament /app panel on the Otto + main merge.
 * Confirms login, dashboard, and core search routes still render.
 */
test.use({
    video: 'on',
    storageState: { cookies: [], origins: [] },
});

const routes = [
    { path: '/app', name: 'dashboard' },
    { path: '/app/scans', name: 'scans' },
    { path: '/app/hits', name: 'hits' },
    { path: '/app/hotlists', name: 'hotlists' },
];

test.describe('App smoke', () => {
    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    for (const route of routes) {
        test(`loads ${route.name} (${route.path})`, async ({ page }) => {
            await rewriteForcedHttpsForLocalHttp(page);
            await loginAsAdmin(page);
            const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });

            expect(response, `GET ${route.path}`).not.toBeNull();
            expect(response!.status(), `GET ${route.path} status`).toBeLessThan(400);
            await expect(page).not.toHaveURL(/\/login/);
            await expect(page.locator('body')).toBeVisible();
        });
    }
});
