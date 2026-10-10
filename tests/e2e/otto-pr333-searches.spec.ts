import { expect, test } from '@playwright/test';
import { assertNotProduction } from './helpers/auth';
import {
    acceptOttoDisclaimerIfPresent,
    askOtto,
    bedrockConfigured,
    openAppWithOtto,
    openOttoAssistant,
    openOttoHistory,
} from './helpers/otto';

/**
 * PR #333 Otto E2E — hit/scan/hotlist/zone/Scout surfaces.
 *
 * Always covers:
 * - Otto Vue island mounts on /app for an Otto-enabled admin
 * - Disclaimer gate
 * - History hydration of OttoSeeder demo threads (no Bedrock)
 *
 * Live Bedrock prompts (today's scans / hits / hotlists / zones / drivers)
 * run only when cloud secrets are present.
 */
test.use({
    video: 'on',
    storageState: { cookies: [], origins: [] },
});

test.describe('Otto PR #333', () => {
    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test('mounts Otto on the admin app and accepts the disclaimer', async ({ page }) => {
        await openAppWithOtto(page);
        await openOttoAssistant(page);
        await acceptOttoDisclaimerIfPresent(page);

        await expect(page.getByPlaceholder('Ask Otto something...')).toBeVisible();
        await expect(page.getByRole('dialog', { name: 'Otto assistant' })).toContainText(/Otto/i);
    });

    test('hydrates seeded Otto history threads with search widgets', async ({ page }) => {
        test.setTimeout(60_000);

        await openAppWithOtto(page);
        await openOttoAssistant(page);
        await acceptOttoDisclaimerIfPresent(page);
        await openOttoHistory(page);

        const dialog = page.getByRole('dialog', { name: 'Otto assistant' });
        // Compact history is an absolute overlay (z-30). Scope to it so the
        // toolbar "History" button under the overlay is not matched/clicked.
        const historyOverlay = dialog.locator('div.absolute.inset-0.z-30');
        await expect(historyOverlay.getByPlaceholder('Search history...')).toBeVisible({
            timeout: 15_000,
        });

        const thread = historyOverlay
            .locator('button')
            .filter({ hasText: /\S/ })
            .first();

        await expect(thread).toBeVisible({ timeout: 15_000 });
        await thread.click();

        // Seeded threads include zone / search widgets stamped on assistant rows.
        await expect
            .poll(async () => page.locator('[data-otto-body], [data-otto-widget]').count(), {
                timeout: 30_000,
            })
            .toBeGreaterThan(0);
    });

    test('live Bedrock searches for scans, hits, hotlists, zones, and drivers', async ({
        page,
    }) => {
        test.skip(
            !bedrockConfigured(),
            'Missing AWS_BEDROCK_ACCESS_KEY_ID / AWS_BEDROCK_SECRET_ACCESS_KEY (or BEDROCK_KEY / BEDROCK_SECRET).',
        );

        test.setTimeout(300_000);

        await openAppWithOtto(page);
        await openOttoAssistant(page);
        await acceptOttoDisclaimerIfPresent(page);

        const prompts = [
            "Show me today's scans",
            'Show me recent hits',
            'What hotlist assignments do we have?',
            'What Scout zones do we have?',
            'Where are my drivers?',
        ];

        for (const prompt of prompts) {
            await askOtto(page, prompt);

            // Hang-net / Echo: wait until thinking ends and an assistant body appears.
            await expect
                .poll(
                    async () => {
                        const thinking = await page.getByText(/thinking/i).count();
                        const bodies = await page.locator('[data-otto-body]').count();
                        const widgets = await page.locator('[data-otto-widget]').count();

                        return thinking === 0 && (bodies > 0 || widgets > 0);
                    },
                    { timeout: 180_000 },
                )
                .toBe(true);

            // Soft assertion path: results table or prose reply must exist.
            const hasWidget = (await page.locator('[data-otto-widget]').count()) > 0;
            const hasBody = (await page.locator('[data-otto-body]').count()) > 0;

            expect(hasWidget || hasBody).toBe(true);
        }
    });
});
