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
    cardValueText,
    DATE_FILTERS,
    dashboardCardKeys,
    expandCard,
    expectedDistinctCount,
    expectedWindowCounts,
    EXPANDED_DETAIL_ID,
    expandedDetail,
    LOCKED_VALUE,
    MISSED_OPP_CARD_KEY,
    MISSED_OPP_HEADING,
    missedOppCard,
    missedOppCount,
    openCompanyMetrics,
    RECOVERY_COUNTS_CARD_KEY,
    RECOVERY_COUNTS_HEADING,
    RECOVERY_LEADER_CARD_KEY,
    RECOVERY_LEADER_HEADING,
    SCAN_COUNT_CARD_KEY,
    selectDateFilter,
    STAGING_LIVE_HIT_LEADER_CARD_KEY,
    UNIQUE_SCAN_COUNTS_CARD_KEY,
    UPSELL_COPY,
} from './helpers/company-metrics';

/**
 * DEV-2462 — Company Metrics "Missed Opportunities" card (Playwright).
 *
 * Product under test:
 * - Compact card key `missed-opportunities`, heading "Missed Opportunities".
 * - Slot: mockup row 3 col 3, after staging-live-hit-leader and before
 *   recovery-leader ("LPR Recovery Leaderboard").
 * - Grain: countDistinct(hotlist_id) via MissedOpportunity scopes
 *   (forOrg / notNicb / between / selectTotal). Not raw row count.
 * - Date toggles on the dashboard page: Today / Last 7d / This Month / Last Month.
 * - Pro gate: canViewCardData() is auth + role + (Feature::active(MISSED_OPPORTUNITY)
 *   OR session admin_impersonator). Non-Pro keeps the grid slot, shows LOCKED_VALUE
 *   em dash, no sparkline, and is absent from expandableCards().
 * - Upsell: lockedStatDescription "Upgrade to Pro" + lockedStatUrl via upgrade.from
 *   → /app/subscription naming the feature; padlock visible.
 * - Stat color primary; sparkline rgb [255,102,102]. Compact uses fi-color-primary.
 * - Expanded detail is the same MissedOpportunities widget at larger size.
 *
 * CRITICAL login trap:
 *   GET /__qa/login/{id} sets admin_impersonator unconditionally and BYPASSES
 *   the Pro gate. Pro scenarios may use loginAsAdmin (QA login or OTP).
 *   Non-Pro locked-card scenarios MUST authenticate without that bypass:
 *   OTP at /app/login with E2E_NON_PRO_EMAIL (+ E2E_OTP only in production;
 *   outside it the panel skips the OTP step per DEV-2521) (loginAsNonProOrgAdmin).
 *   If only the QA login is available, those tests FAIL with the trap message
 *   — they do not skip and they do not use the bypass.
 *
 * Isolated cloud / local only (127.0.0.1 / *.test). assertNotProduction runs
 * in beforeAll. Never target *.insightlpr.io.
 *
 * Behind the scenes (not a UI test): MissedOpportunityClientGraph countDistinct
 * grain is covered by PHPUnit MissedOpportunityClientGraphTest. That graph is
 * not wired to any page — do not invent a UI case for an unreachable widget.
 */
test.use({ video: 'on' });

test.describe('DEV-2462 Missed Opportunities Company Metrics', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('Pro org (loginAsAdmin)', () => {
        const proStorageState = '/tmp/e2e-missed-opp-pro.json';
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

        test('A. Pro — card occupies the mockup slot between Total Repossessions and LPR Recovery Leaderboard', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            await expect(card(page, RECOVERY_COUNTS_CARD_KEY)).toContainText(RECOVERY_COUNTS_HEADING);
            await expect(missedOppCard(page)).toContainText(MISSED_OPP_HEADING);
            await expect(card(page, RECOVERY_LEADER_CARD_KEY)).toContainText(RECOVERY_LEADER_HEADING);

            const keys = await dashboardCardKeys(page);
            const recoveryCountsAt = keys.indexOf(RECOVERY_COUNTS_CARD_KEY);
            const uniqueScanCountsAt = keys.indexOf(UNIQUE_SCAN_COUNTS_CARD_KEY);
            const leaderAt = keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY);
            const missedAt = keys.indexOf(MISSED_OPP_CARD_KEY);
            const recoveryLeaderAt = keys.indexOf(RECOVERY_LEADER_CARD_KEY);

            expect(recoveryCountsAt, 'recovery-counts card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(uniqueScanCountsAt, 'unique-scan-counts card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(leaderAt, 'staging-live-hit-leader card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(missedAt, 'missed-opportunities card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(recoveryLeaderAt, 'recovery-leader card missing from the grid').toBeGreaterThanOrEqual(0);
            // +2, not +1: DEV-2463's unique-lpr-case-claims (mockup row 1 col 3) now sits
            // between recovery-counts and this card, and the leaderboard follows at +3.
            expect(leaderAt, 'Staging Live Hit Leaderboard occupies mockup row 3 col 2').toBe(
                recoveryCountsAt + 3,
            );
            expect(uniqueScanCountsAt, 'Unique Scan Counts occupies mockup row 2 col 1').toBe(
                recoveryCountsAt + 2,
            );
            expect(leaderAt, 'Staging Live Hit Leaderboard occupies the slot immediately after Unique Scan Counts').toBe(
                uniqueScanCountsAt + 1,
            );
            expect(missedAt, 'Missed Opportunities occupies mockup row 3 col 3').toBe(leaderAt + 1);
            expect(recoveryLeaderAt, 'LPR Recovery Leaderboard must sit after Missed Opportunities').toBe(
                missedAt + 1,
            );
        });

        test('B. Pro — compact card shows distinct hotlist_id count, not raw row count', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const shown = await missedOppCount(page, 'B distinct grain');
            const seeded = expectedDistinctCount();

            if (seeded === undefined) {
                throw new Error(
                    `B requires a seeded gap: raw missed-opportunity rows > distinct hotlist_id. ` +
                        `Set E2E_MISSED_OPP_DISTINCT_COUNT and E2E_MISSED_OPP_RAW_COUNT (raw > distinct). ` +
                        `Card currently shows ${shown}. Do not treat an ambiguous 0 as a pass.`,
                );
            }

            expect(
                seeded.raw,
                'E2E_MISSED_OPP_RAW_COUNT must be greater than E2E_MISSED_OPP_DISTINCT_COUNT so the grain is observable',
            ).toBeGreaterThan(seeded.distinct);
            expect(shown, 'compact card must show the distinct hotlist count').toBe(seeded.distinct);
            expect(shown, 'compact card must not show the raw row count').not.toBe(seeded.raw);
        });

        test('C. Pro — date filter cycle: Today / Last 7d / This Month / Last Month each resolve their own total', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const expected = expectedWindowCounts();
            const observed: Partial<Record<(typeof DATE_FILTERS)[number]['key'], number>> = {};

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);

                if (expected) {
                    await expect
                        .poll(async () => missedOppCount(page, `C ${filter.label}`), { timeout: 10_000 })
                        .toBe(expected[filter.key]);
                    observed[filter.key] = expected[filter.key];
                } else {
                    observed[filter.key] = await missedOppCount(page, `C ${filter.label}`);
                }
            }

            const today = observed.today;
            const last7d = observed.last_7d;
            const thisMonth = observed.this_month;
            const lastMonth = observed.last_month;

            expect(today, 'C Today produced no numeric total').toEqual(expect.any(Number));
            expect(last7d, 'C Last 7d produced no numeric total').toEqual(expect.any(Number));
            expect(thisMonth, 'C This Month produced no numeric total').toEqual(expect.any(Number));
            expect(lastMonth, 'C Last Month produced no numeric total').toEqual(expect.any(Number));

            if (!expected) {
                const allZero = [today, last7d, thisMonth, lastMonth].every((value) => value === 0);

                if (allZero) {
                    throw new Error(
                        'C saw 0/0/0/0 across Today / Last 7d / This Month / Last Month. ' +
                            'That is an unseeded window, not a date-filter proof. Seed the org ' +
                            '(Build Record observed 3/3/3/1 with Last Month diverging) or set ' +
                            'E2E_MISSED_OPP_TODAY / E2E_MISSED_OPP_LAST_7D / E2E_MISSED_OPP_THIS_MONTH / ' +
                            'E2E_MISSED_OPP_LAST_MONTH.',
                    );
                }

                expect(
                    lastMonth !== today || lastMonth !== last7d || lastMonth !== thisMonth,
                    'Last Month must diverge from at least one other window (or provide the E2E_MISSED_OPP_* map)',
                ).toBeTruthy();
            }
        });

        test('D. Pro — expanded Missed Opportunities widget agrees with the compact total', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const compact = await missedOppCount(page, 'D compact');
            const formatted = compact.toLocaleString('en-US');

            await expandCard(page, MISSED_OPP_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(detail.getByText(MISSED_OPP_HEADING, { exact: true }).first()).toBeVisible();
            await expect(detail).toContainText(new RegExp(`(?:Today:|Last 7 Days:|Current Month:|Last Month:)\\s*${formatted}`));

            await expect(missedOppCard(page)).toHaveAttribute('data-dashboard-open', 'true');
        });

        test('E. Pro — primary / red sparkline is present when the compact value is greater than 0', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const value = await missedOppCount(page, 'E sparkline');

            if (value <= 0) {
                throw new Error(
                    'E requires a Pro org whose compact Missed Opportunities value is > 0 so the sparkline is drawn. ' +
                        'Zero / empty belongs in scenario H. Seed the window or set E2E_MISSED_OPP_THIS_MONTH.',
                );
            }

            const compact = missedOppCard(page);
            const chart = compact.locator('.fi-wi-stats-overview-stat-chart');
            await expect(chart).toBeVisible();
            await expect(chart.locator('svg')).toBeVisible();

            const primary = compact.locator('[class*="fi-color-primary"]');

            if ((await primary.count()) > 0) {
                await expect(primary.first()).toBeVisible();
            } else {
                // Filament 5 paints Stat::color('primary') via CSS variables on the
                // sparkline, not always as fi-color-primary on the compact root.
                await expect(chart).toBeVisible();
            }
        });

        test('H. Pro — empty / zero state still occupies the slot and is not locked', async ({ page }) => {
            await openCompanyMetrics(page);

            const forcedWindow = process.env.E2E_MISSED_OPP_ZERO_WINDOW;
            const requireDefaultZero = process.env.E2E_MISSED_OPP_ZERO === '1';
            const windowFromEnv = DATE_FILTERS.find((filter) => filter.key === forcedWindow);

            let zeroLabel: string | undefined;

            if (windowFromEnv) {
                await selectDateFilter(page, windowFromEnv.label);
                const value = await missedOppCount(page, `H ${windowFromEnv.label}`);
                expect(value, `E2E_MISSED_OPP_ZERO_WINDOW=${windowFromEnv.key} must render 0`).toBe(0);
                zeroLabel = windowFromEnv.label;
            } else if (requireDefaultZero) {
                const value = await missedOppCount(page, 'H default window');
                expect(value, 'E2E_MISSED_OPP_ZERO=1 requires the active window to show 0').toBe(0);
                zeroLabel = 'active window';
            } else {
                for (const filter of DATE_FILTERS) {
                    await selectDateFilter(page, filter.label);

                    try {
                        await expect
                            .poll(async () => missedOppCount(page, `H scan ${filter.label}`), {
                                timeout: 8_000,
                            })
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
                    'H needs a Pro org / window with zero missed opportunities. ' +
                        'Set E2E_MISSED_OPP_ZERO=1 on a zero seed, or E2E_MISSED_OPP_ZERO_WINDOW=today|last_7d|this_month|last_month.',
                );
            }

            const compact = missedOppCard(page);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(MISSED_OPP_HEADING);
            await expect(await cardValueText(page)).toBe('0');
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact.getByText(UPSELL_COPY)).toHaveCount(0);

            const keys = await dashboardCardKeys(page);
            // +2, not +1: DEV-2463's unique-lpr-case-claims (mockup row 1 col 3) now sits
            // between recovery-counts and this card, and the leaderboard follows at +3.
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(RECOVERY_COUNTS_CARD_KEY) + 3,
            );
            expect(keys.indexOf(UNIQUE_SCAN_COUNTS_CARD_KEY)).toBe(
                keys.indexOf(RECOVERY_COUNTS_CARD_KEY) + 2,
            );
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(UNIQUE_SCAN_COUNTS_CARD_KEY) + 1,
            );
            expect(keys.indexOf(MISSED_OPP_CARD_KEY)).toBe(
                keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY) + 1,
            );
        });
    });

    test.describe('Non-Pro org (OTP only — no QA login bypass)', () => {
        const nonProStorageState = '/tmp/e2e-missed-opp-nonpro.json';
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

        test('F. Non-Pro — locked slot, em dash, Upgrade to Pro + padlock, no sparkline, not expandable', async ({
            page,
        }) => {
            if (!process.env.E2E_NON_PRO_EMAIL) {
                throw new Error(QA_LOGIN_PRO_GATE_BYPASS);
            }

            await openCompanyMetrics(page);

            const compact = missedOppCard(page);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(MISSED_OPP_HEADING);
            await expect(await cardValueText(page)).toBe(LOCKED_VALUE);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact.getByText(UPSELL_COPY)).toBeVisible();
            await expect(compact.locator('.fi-wi-stats-overview-stat-description svg')).toBeVisible();
            await expect(compact.locator('.fi-wi-stats-overview-stat-chart')).toHaveCount(0);
            await expect(compact).not.toHaveAttribute('data-description', 'Click for more details');

            const keys = await dashboardCardKeys(page);
            // +2, not +1: DEV-2463's unique-lpr-case-claims (mockup row 1 col 3) now sits
            // between recovery-counts and this card, and the leaderboard follows at +3.
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(RECOVERY_COUNTS_CARD_KEY) + 3,
            );
            expect(keys.indexOf(UNIQUE_SCAN_COUNTS_CARD_KEY)).toBe(
                keys.indexOf(RECOVERY_COUNTS_CARD_KEY) + 2,
            );
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(UNIQUE_SCAN_COUNTS_CARD_KEY) + 1,
            );
            expect(keys.indexOf(MISSED_OPP_CARD_KEY)).toBe(
                keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY) + 1,
            );
            expect(keys.indexOf(RECOVERY_LEADER_CARD_KEY)).toBe(keys.indexOf(MISSED_OPP_CARD_KEY) + 1);

            await expandCard(page, SCAN_COUNT_CARD_KEY);
            await expect(expandedDetail(page)).toBeVisible();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).not.toContainText(
                new RegExp(`${MISSED_OPP_HEADING}[\\s\\S]*Today:`),
            );
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(await cardValueText(page)).toBe(LOCKED_VALUE);
        });

        test('G. Non-Pro — Upgrade to Pro lands on Subscription naming Missed Opportunities', async ({
            page,
        }) => {
            if (!process.env.E2E_NON_PRO_EMAIL) {
                throw new Error(QA_LOGIN_PRO_GATE_BYPASS);
            }

            await openCompanyMetrics(page);

            await missedOppCard(page).getByText(UPSELL_COPY).click();
            await expect(page).toHaveURL(/\/app\/subscription\/?/);
            await expect(page.getByText(/Missed Opportunity/)).toBeVisible();
        });
    });
});
