import { writeFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import {
    assertNotProduction,
    loginAsAdmin,
    loginAsNonProOrgAdmin,
    QA_LOGIN_PRO_GATE_BYPASS,
} from './helpers/auth';
import {
    card,
    cardSparkline,
    cardValueText,
    DATE_FILTERS,
    dashboardCardKeys,
    expandCard,
    EXPANDED_DETAIL_ID,
    expandedDetail,
    LOCKED_VALUE,
    openCompanyMetrics,
    parseCount,
    RECOVERY_COUNTS_CARD_KEY,
    RECOVERY_COUNTS_HEADING,
    SCAN_COUNT_CARD_KEY,
    selectDateFilter,
    STAGING_LIVE_HIT_LEADER_CARD_KEY,
    STAGING_LIVE_HIT_LEADER_HEADING,
    UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
    UNIQUE_LPR_CASE_CLAIMS_HEADING,
    UPSELL_COPY,
} from './helpers/company-metrics';

/**
 * DEV-2463 — Company Metrics "Unique LPR Case Claims" card (Playwright).
 *
 * Product under test:
 * - Compact card key `unique-lpr-case-claims`, heading "Unique LPR Case Claims".
 * - Slot: mockup row 1 col 3, between recovery-counts ("Total Repossessions") and
 *   staging-live-hit-leader.
 * - Value shown is the `deduped` (billed) funnel stage from UniqueLprCaseClaimsQuery
 *   -- not the raw or successful-only counts.
 * - Date toggles on the dashboard page: Today / Last 7d / This Month / Last Month.
 * - Pro gate: canViewCardData() is guest-total-true, then role check ANDed with
 *   (Feature::active(COMPANY_METRICS) OR session admin_impersonator). Non-Pro keeps
 *   the grid slot, shows LOCKED_VALUE em dash, no sparkline, and is absent from
 *   expandableCards(). Bare lock -- no lockedStatDescription/lockedStatUrl override,
 *   so this card must NOT show "Upgrade to Pro" (same asymmetry as
 *   staging-live-hit-leader).
 * - Monthly buckets are UTC-anchored, deliberately diverging from every other
 *   card's user-timezone month boundary -- not independently observable from the
 *   UI on a fixed clock, so it is pinned in PHPUnit instead (see
 *   UniqueLprCaseClaimsTest::test_month_buckets_align_on_utc_not_the_viewers_timezone).
 *
 * CRITICAL login trap:
 *   GET /__qa/login/{id} sets admin_impersonator unconditionally and BYPASSES
 *   the Pro gate. Pro scenarios may use loginAsAdmin (QA login or OTP).
 *   Non-Pro locked-card scenarios MUST authenticate without that bypass:
 *   OTP at /app/login with E2E_NON_PRO_EMAIL (+ E2E_OTP only in production;
 *   outside it the panel skips the OTP step per DEV-2521) (loginAsNonProOrgAdmin).
 *   If only the QA login is available, those tests FAIL with the trap message
 *   -- they do not skip and they do not use the bypass.
 *
 * Isolated cloud / local only (127.0.0.1 / *.test). assertNotProduction runs
 * in beforeAll. Never target *.insightlpr.io.
 *
 * Behind the scenes (not a UI test): the one-round-trip AC (getMonthlyData() /
 * getModelQueryForDates() each exactly one ClickHouse call) and the UTC bucket
 * boundary are PHPUnit (UniqueLprCaseClaimsQueryTest / UniqueLprCaseClaimsTest).
 */
test.use({ video: 'on' });

test.describe('DEV-2463 Unique LPR Case Claims Company Metrics', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('Pro org (loginAsAdmin)', () => {
        const proStorageState = '/tmp/e2e-unique-lpr-case-claims-pro.json';
        writeFileSync(proStorageState, JSON.stringify({ cookies: [], origins: [] }));

        test.beforeAll(async ({ browser }) => {
            const context = await browser.newContext({
                ignoreHTTPSErrors: true,
                baseURL: process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000',
                storageState: { cookies: [], origins: [] },
            });
            const page = await context.newPage();

            await loginAsAdmin(page);
            await context.storageState({ path: proStorageState });
            await context.close();
        });

        test.use({ storageState: proStorageState });

        test.beforeEach(async ({ page }) => {
            await loginAsAdmin(page);
        });

        test('A. Pro — mockup slot between Total Repossessions and Staging Live Hit Leaderboard', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            await expect(card(page, RECOVERY_COUNTS_CARD_KEY)).toContainText(RECOVERY_COUNTS_HEADING);
            await expect(compact).toContainText(UNIQUE_LPR_CASE_CLAIMS_HEADING);
            await expect(card(page, STAGING_LIVE_HIT_LEADER_CARD_KEY)).toContainText(
                STAGING_LIVE_HIT_LEADER_HEADING,
            );

            const keys = await dashboardCardKeys(page);
            const recoveryCountsAt = keys.indexOf(RECOVERY_COUNTS_CARD_KEY);
            const claimsAt = keys.indexOf(UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            const leaderAt = keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY);

            expect(recoveryCountsAt, 'recovery-counts card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(claimsAt, 'unique-lpr-case-claims card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(leaderAt, 'staging-live-hit-leader card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(claimsAt, 'Unique LPR Case Claims occupies mockup row 1 col 3').toBe(recoveryCountsAt + 1);
            expect(leaderAt, 'Staging Live Hit Leaderboard must sit after Unique LPR Case Claims').toBe(
                claimsAt + 1,
            );
        });

        test('B. Pro — sparkline renders when the compact value is greater than 0', async ({ page }) => {
            await openCompanyMetrics(page);

            let value = parseCount(
                await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY),
                'B compact value',
            );
            let windowLabel = 'active window';

            if (value <= 0) {
                for (const filter of DATE_FILTERS) {
                    await selectDateFilter(page, filter.label);
                    value = parseCount(
                        await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY),
                        `B scan ${filter.label}`,
                    );

                    if (value > 0) {
                        windowLabel = filter.label;
                        break;
                    }
                }
            }

            if (value <= 0) {
                throw new Error(
                    'B requires a Pro org whose compact Unique LPR Case Claims value is > 0 so the sparkline is drawn. ' +
                        'Zero / empty belongs in scenario H. Seed the window or set E2E_UNIQUE_LPR_CASE_CLAIMS_THIS_MONTH.',
                );
            }

            const chart = cardSparkline(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            await expect(chart).toBeVisible();
            await expect(chart.locator('svg')).toBeVisible();

            expect(value, `B ${windowLabel} compact value`).toBeGreaterThan(0);
        });

        test('C. Pro — date filter cycle: Today / Last 7d / This Month / Last Month each resolve', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const observed: Partial<Record<(typeof DATE_FILTERS)[number]['key'], number>> = {};

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);

                const compact = card(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
                await expect(compact).toBeVisible();
                await expect(compact).toContainText(UNIQUE_LPR_CASE_CLAIMS_HEADING);
                await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                observed[filter.key] = parseCount(
                    await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY),
                    `C ${filter.label}`,
                );
            }

            expect(observed.today, 'C Today produced no numeric total').toEqual(expect.any(Number));
            expect(observed.last_7d, 'C Last 7d produced no numeric total').toEqual(expect.any(Number));
            expect(observed.this_month, 'C This Month produced no numeric total').toEqual(expect.any(Number));
            expect(observed.last_month, 'C Last Month produced no numeric total').toEqual(expect.any(Number));
        });

        test('D. Pro — expanded detail agrees with the compact total', async ({ page }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toContainText(UNIQUE_LPR_CASE_CLAIMS_HEADING);

            const compactValue = parseCount(
                await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY),
                'D compact',
            );
            const formatted = compactValue.toLocaleString('en-US');

            await expandCard(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(detail.getByText(UNIQUE_LPR_CASE_CLAIMS_HEADING, { exact: true }).first()).toBeVisible();
            await expect(detail).toContainText(
                // This card overrides getDescription() to UTC month names ("Sep '26: 1,234 | Aug '26: 567"),
                // so the base class's "Current Month:"/"Last Month:" literals never appear here.
                new RegExp(`(?:Today:|Last 7 Days:|[A-Z][a-z]{2} '\\d{2}:)\\s*${formatted}`),
            );
            await expect(compact).toHaveAttribute('data-dashboard-open', 'true');
        });

        test('H. Pro — empty / zero state still occupies the slot and is not locked', async ({ page }) => {
            await openCompanyMetrics(page);

            const forcedWindow = process.env.E2E_UNIQUE_LPR_CASE_CLAIMS_ZERO_WINDOW;
            const windowFromEnv = DATE_FILTERS.find((filter) => filter.key === forcedWindow);

            let zeroLabel: string | undefined;

            if (windowFromEnv) {
                await selectDateFilter(page, windowFromEnv.label);
                const value = parseCount(
                    await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY),
                    `H ${windowFromEnv.label}`,
                );
                expect(value, `E2E_UNIQUE_LPR_CASE_CLAIMS_ZERO_WINDOW=${windowFromEnv.key} must render 0`).toBe(0);
                zeroLabel = windowFromEnv.label;
            } else {
                for (const filter of DATE_FILTERS) {
                    await selectDateFilter(page, filter.label);

                    try {
                        await expect
                            .poll(
                                async () =>
                                    parseCount(
                                        await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY),
                                        `H scan ${filter.label}`,
                                    ),
                                { timeout: 8_000 },
                            )
                            .toBe(0);
                        zeroLabel = filter.label;
                        break;
                    } catch {
                        continue;
                    }
                }
            }

            if (zeroLabel === undefined) {
                throw new Error(
                    'H needs a Pro org / window with zero Unique LPR Case Claims. ' +
                        'Set E2E_UNIQUE_LPR_CASE_CLAIMS_ZERO_WINDOW=today|last_7d|this_month|last_month.',
                );
            }

            const compact = card(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_LPR_CASE_CLAIMS_HEADING);
            await expect(await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toBe('0');
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact.getByText(UPSELL_COPY)).toHaveCount(0);

            const keys = await dashboardCardKeys(page);
            expect(keys.indexOf(UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toBe(
                keys.indexOf(RECOVERY_COUNTS_CARD_KEY) + 1,
            );
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(UNIQUE_LPR_CASE_CLAIMS_CARD_KEY) + 1,
            );
        });
    });

    test.describe('Non-Pro org (OTP only — no QA login bypass)', () => {
        const nonProStorageState = '/tmp/e2e-unique-lpr-case-claims-nonpro.json';
        writeFileSync(nonProStorageState, JSON.stringify({ cookies: [], origins: [] }));

        test.beforeAll(async ({ browser }) => {
            const context = await browser.newContext({
                ignoreHTTPSErrors: true,
                baseURL: process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000',
                storageState: { cookies: [], origins: [] },
            });
            const page = await context.newPage();

            await loginAsNonProOrgAdmin(page);
            await context.storageState({ path: nonProStorageState });
            await context.close();
        });

        test.use({ storageState: nonProStorageState });

        test('F. Non-Pro — locked bare (OTP only): slot kept, em dash, no chart, no Upgrade to Pro', async ({
            page,
        }) => {
            if (!process.env.E2E_NON_PRO_EMAIL) {
                throw new Error(QA_LOGIN_PRO_GATE_BYPASS);
            }

            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_LPR_CASE_CLAIMS_HEADING);
            await expect(await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toBe(LOCKED_VALUE);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact.getByText(UPSELL_COPY)).toHaveCount(0);
            await expect(compact.locator('.fi-wi-stats-overview-stat-chart')).toHaveCount(0);
            await expect(compact.locator('canvas')).toHaveCount(0);
            await expect(compact).not.toHaveAttribute('data-description', 'Click for more details');

            const markup = await compact.evaluate((node) => node.outerHTML);
            expect(markup, 'locked card must not emit wire:click / toggleCard').not.toMatch(
                /toggleCard|wire:click/i,
            );

            const keys = await dashboardCardKeys(page);
            expect(keys.indexOf(UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toBe(
                keys.indexOf(RECOVERY_COUNTS_CARD_KEY) + 1,
            );
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(UNIQUE_LPR_CASE_CLAIMS_CARD_KEY) + 1,
            );

            await compact.click();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toHaveCount(0);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(await cardValueText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toBe(LOCKED_VALUE);

            await expandCard(page, SCAN_COUNT_CARD_KEY);
            await expect(expandedDetail(page)).toBeVisible();
            await compact.click();
            await expect(expandedDetail(page)).toBeVisible();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).not.toContainText(
                new RegExp(`${UNIQUE_LPR_CASE_CLAIMS_HEADING}[\\s\\S]*(?:Today:|[A-Z][a-z]{2} '\\d{2}:)`),
            );
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
        });
    });
});
