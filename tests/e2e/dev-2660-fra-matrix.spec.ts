import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { assertNotProduction, loginViaOtp } from './helpers/auth';
import {
    card,
    cardValueText,
    LPR_REPOSSESSIONS_CARD_KEY,
    LPR_REPOSSESSIONS_HEADING,
    openCompanyMetrics,
    selectDateFilter,
    waitForCardValueStable,
} from './helpers/company-metrics';

/**
 * DEV-2660 — Company Metrics LPR Repossessions recoveries from
 * clarity.hotlist.first_repossessed_at. FREEZE matrix A–G.
 *
 * Isolated loopback only. Seed with tests/e2e/helpers/seed-dev-2660-fra.php
 * before this spec. The logged-in agent org is UTC.
 *
 * A This Month = 1 (FRA in window).
 * B Last Month = 0 (FRA null, status-3 log present — must not fall through).
 * C and D sit in This Month and must not raise that 1.
 * E Last 7d = 0 (status-log only, no clarity.hotlist row).
 * F Today = 0 with the card present; This Month stays the populated 1.
 * G A ClickHouse connect failure must not render a calm 0.
 */

test.use({ video: 'on' });

test.describe('DEV-2660 FRA recoveries matrix', () => {
    test.describe.configure({ timeout: 120_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000');
    });

    test('A–F card counts follow first_repossessed_at and ignore status-log-only rows', async ({ page }) => {
        await loginViaOtp(page, process.env.E2E_ADMIN_EMAIL ?? 'test@tester.com');
        await openCompanyMetrics(page);

        const compact = card(page, LPR_REPOSSESSIONS_CARD_KEY);
        await expect(compact).toContainText(LPR_REPOSSESSIONS_HEADING);
        await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

        await selectDateFilter(page, 'This Month');
        await expect.poll(async () => waitForCardValueStable(page, LPR_REPOSSESSIONS_CARD_KEY), {
            timeout: 20_000,
        }).toBe('1');
        await compact.screenshot({ path: '/opt/cursor/artifacts/qa-2660-A-fra-in-window.png' });
        await page.screenshot({ path: '/opt/cursor/artifacts/qa-2660-F-populated-this-month.png', fullPage: false });

        await selectDateFilter(page, 'Last Month');
        await expect.poll(async () => waitForCardValueStable(page, LPR_REPOSSESSIONS_CARD_KEY), {
            timeout: 20_000,
        }).toBe('0');
        await expect(compact).toBeVisible();
        await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);
        await compact.screenshot({ path: '/opt/cursor/artifacts/qa-2660-B-fra-null-miss.png' });

        await selectDateFilter(page, 'Last 7d');
        await expect.poll(async () => waitForCardValueStable(page, LPR_REPOSSESSIONS_CARD_KEY), {
            timeout: 20_000,
        }).toBe('0');
        await compact.screenshot({ path: '/opt/cursor/artifacts/qa-2660-E-status-log-only-zero.png' });

        await selectDateFilter(page, 'Today');
        await expect.poll(async () => waitForCardValueStable(page, LPR_REPOSSESSIONS_CARD_KEY), {
            timeout: 20_000,
        }).toBe('0');
        await expect(compact).toContainText(LPR_REPOSSESSIONS_HEADING);
        await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);
        await compact.screenshot({ path: '/opt/cursor/artifacts/qa-2660-F-empty-today.png' });

        await selectDateFilter(page, 'This Month');
        await expect.poll(async () => waitForCardValueStable(page, LPR_REPOSSESSIONS_CARD_KEY), {
            timeout: 20_000,
        }).toBe('1');
    });

    test('G ClickHouse failure does not render a fabricated zero', async ({ page }) => {
        await loginViaOtp(page, process.env.E2E_ADMIN_EMAIL ?? 'test@tester.com');

        execSync('php artisan cache:clear --no-interaction', { stdio: 'inherit' });
        execSync('docker stop clarity-cloud-clickhouse-1', { stdio: 'inherit' });

        try {
            const response = await page.goto('/app', { waitUntil: 'domcontentloaded', timeout: 45_000 });
            const status = response?.status() ?? 0;
            const bodyText = await page.locator('body').innerText({ timeout: 15_000 }).catch(() => '');
            const looksLikeError =
                status >= 500 ||
                /Server Error|ErrorException|ClickHouse|Connection refused|cURL error|SQLSTATE|failed to respond/i.test(
                    bodyText,
                );
            const cardCount = await card(page, LPR_REPOSSESSIONS_CARD_KEY).count();
            let value = '';

            if (cardCount > 0) {
                value = (await cardValueText(page, LPR_REPOSSESSIONS_CARD_KEY)).trim();
            }

            const fabricatedZero = value === '0' && !looksLikeError;

            await page.screenshot({ path: '/opt/cursor/artifacts/qa-2660-G-fail-empty.png', fullPage: true });

            expect(fabricatedZero, `status=${status} value=${value} body=${bodyText.slice(0, 400)}`).toBe(false);
            expect(looksLikeError || cardCount === 0, `status=${status} body=${bodyText.slice(0, 400)}`).toBe(true);
        } finally {
            execSync('docker start clarity-cloud-clickhouse-1', { stdio: 'inherit' });
            execSync(
                'for i in 1 2 3 4 5 6 7 8 9 10; do docker inspect -f "{{.State.Health.Status}}" clarity-cloud-clickhouse-1 | grep -q healthy && exit 0; sleep 2; done; exit 1',
                { stdio: 'inherit' },
            );
        }
    });
});
