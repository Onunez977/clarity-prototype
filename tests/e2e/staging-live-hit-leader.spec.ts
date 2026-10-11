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
    cardSparklineLinePath,
    cardValueText,
    COMPANY_METRICS_CARD_HEADINGS,
    DATE_FILTERS,
    dashboardCardKeys,
    expandCard,
    EXPANDED_DETAIL_ID,
    expandedDetail,
    expectedStagingLiveHitLeaderWindowCounts,
    expectedStagingLiveSiblingAgreement,
    HISTORICAL_HITS_CARD_KEY,
    LIVE_HITS_CARD_KEY,
    LOCKED_VALUE,
    MISSED_OPP_CARD_KEY,
    openCompanyMetrics,
    parseCount,
    RECOVERY_COUNTS_CARD_KEY,
    RECOVERY_COUNTS_HEADING,
    RECOVERY_LEADER_CARD_KEY,
    RECOVERY_LEADER_HEADING,
    SCAN_COUNT_CARD_KEY,
    selectDateFilter,
    STAGING_HISTORICAL_HITS_CARD_KEY,
    STAGING_LIVE_HIT_LEADER_CARD_KEY,
    STAGING_LIVE_HIT_LEADER_HEADING,
    STAGING_LIVE_HITS_CARD_KEY,
    STAGING_LIVE_HITS_HEADING,
    stagingLiveHitLeaderCard,
    stagingLiveHitLeaderCount,
    UNIQUE_SCAN_COUNTS_CARD_KEY,
    UPSELL_COPY,
} from './helpers/company-metrics';

/**
 * DEV-2461 — Company Metrics "Staging Live Hit Leaderboard" card (Playwright).
 *
 * Product under test:
 * - Compact card key `staging-live-hit-leader`, heading "Staging Live Hit Leaderboard".
 * - Slot: mockup row 3 col 2, between recovery-counts ("Total Repossessions") and
 *   missed-opportunities; then recovery-leader ("LPR Recovery Leaderboard").
 * - Compact value: max kit total from the top-10 leaderboard for the selected window.
 * - Color hardcoded warning (amber `fi-color-warning`).
 * - Sparkline: shared Staging Live Hits org-total series (NOT the sum of LIMIT-10
 *   buckets). Sibling compact headline is that org total.
 * - Expanded view: stacked bar chart of kits, same heading.
 * - Pro gate: canViewCardData() + FeatureEnum::COMPANY_METRICS (or session
 *   admin_impersonator). Non-Pro keeps the slot, em dash, no chart,
 *   data-dashboard-locked="true", no wire:click. Bare lock — lockedStatDescription
 *   / lockedStatUrl are not overridden, so this card must NOT show "Upgrade to Pro"
 *   (intentional asymmetry vs Missed Opportunities).
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
 * Runner seed contract (scenario H):
 *   The runner seeds Last Month ClickHouse Staging Live hits to 0 (This Month
 *   and other windows may be non-zero). H ALWAYS selects Last Month
 *   (DATE_FILTERS last_month / label "Last Month") and requires the compact
 *   Staging Live Hit Leaderboard value to be 0. It must FAIL if Last Month
 *   still shows a This Month leftover (FAIL freeze: leader 4, sibling Hits 14).
 *   Do not scan Today / Last 7d / This Month and pass on a different zero.
 *   E2E_STAGING_LIVE_HIT_LEADER_ZERO_WINDOW=last_month may remain as an
 *   explicit override; other DATE_FILTERS keys are allowed only when that
 *   env is set. There is no fall-through window scan.
 *
 * Behind the scenes (not a UI test): AC-3 one batched ClickHouse call and AC-4
 * round-trip count are PHPUnit (StagingLiveHitLeaderQueryTest / widget tests).
 */
test.use({ video: 'on' });

test.describe('DEV-2461 Staging Live Hit Leaderboard Company Metrics', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('Pro org (loginAsAdmin)', () => {
        const proStorageState = '/tmp/e2e-staging-live-hit-leader-pro.json';
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

        test('A. Pro — mockup slot between Total Repossessions and Missed Opportunities', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const compact = stagingLiveHitLeaderCard(page);
            await expect(card(page, RECOVERY_COUNTS_CARD_KEY)).toContainText(RECOVERY_COUNTS_HEADING);
            await expect(compact).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);
            await expect(card(page, MISSED_OPP_CARD_KEY)).toBeVisible();
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

        test('B. Pro — warning colour + amber sparkline when the compact value is greater than 0', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            let value = await stagingLiveHitLeaderCount(page, 'B warning colour');
            let windowLabel = 'active window';

            if (value <= 0) {
                for (const filter of DATE_FILTERS) {
                    await selectDateFilter(page, filter.label);
                    value = await stagingLiveHitLeaderCount(page, `B scan ${filter.label}`);

                    if (value > 0) {
                        windowLabel = filter.label;
                        break;
                    }
                }
            }

            if (value <= 0) {
                throw new Error(
                    'B requires a Pro org whose compact Staging Live Hit Leaderboard value is > 0 so the warning sparkline is drawn. ' +
                        'Zero / empty belongs in scenario H. Seed the window or set E2E_STAGING_LIVE_HIT_LEADER_THIS_MONTH.',
                );
            }

            const compact = stagingLiveHitLeaderCard(page);
            const chart = cardSparkline(page, STAGING_LIVE_HIT_LEADER_CARD_KEY);
            await expect(chart).toBeVisible();
            await expect(chart.locator('svg')).toBeVisible();

            const warning = compact.locator('[class*="fi-color-warning"]');

            if ((await warning.count()) > 0) {
                await expect(warning.first()).toBeVisible();
            } else {
                await expect(chart).toBeVisible();
            }

            expect(value, `B ${windowLabel} compact value`).toBeGreaterThan(0);
        });

        test('C. Pro — date filter cycle: Today / Last 7d / This Month / Last Month each resolve', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const expected = expectedStagingLiveHitLeaderWindowCounts();
            const observed: Partial<Record<(typeof DATE_FILTERS)[number]['key'], number>> = {};

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);

                const compact = stagingLiveHitLeaderCard(page);
                await expect(compact).toBeVisible();
                await expect(compact).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);
                await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                if (expected) {
                    await expect
                        .poll(async () => stagingLiveHitLeaderCount(page, `C ${filter.label}`), {
                            timeout: 10_000,
                        })
                        .toBe(expected[filter.key]);
                    observed[filter.key] = expected[filter.key];
                } else {
                    observed[filter.key] = await stagingLiveHitLeaderCount(page, `C ${filter.label}`);
                }
            }

            expect(observed.today, 'C Today produced no numeric total').toEqual(expect.any(Number));
            expect(observed.last_7d, 'C Last 7d produced no numeric total').toEqual(expect.any(Number));
            expect(observed.this_month, 'C This Month produced no numeric total').toEqual(expect.any(Number));
            expect(observed.last_month, 'C Last Month produced no numeric total').toEqual(expect.any(Number));
        });

        test('D. Pro — expand shows stacked-bar detail consistent with the compact heading', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const compact = stagingLiveHitLeaderCard(page);
            await expect(compact).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);

            const compactValue = await stagingLiveHitLeaderCount(page, 'D compact');
            const formatted = compactValue.toLocaleString('en-US');

            await expandCard(page, STAGING_LIVE_HIT_LEADER_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(detail.getByText(STAGING_LIVE_HIT_LEADER_HEADING, { exact: true }).first()).toBeVisible();
            await expect(detail.locator('.fi-wi-chart-canvas-ctn canvas')).toBeVisible();
            await expect(detail.getByText(/Leader for (?:Today|Last 7 Days|This Month|Last Month):/)).toBeVisible();
            await expect(detail).toContainText(new RegExp(`with\\s+${formatted}`));
            await expect(compact).toHaveAttribute('data-dashboard-open', 'true');
        });

        test('E. Pro — sibling agreement / no LIMIT-10 undercount (AC-7/AC-9)', async ({ page }) => {
            await openCompanyMetrics(page);

            const seeded = expectedStagingLiveSiblingAgreement();

            if (seeded === undefined) {
                throw new Error(
                    'E requires a seeded org that exposes both the Staging Live Hits org total and the ' +
                        'leaderboard top-10 sum so AC-9 (sparkline = org total, not LIMIT-10 buckets) is observable. ' +
                        'Set E2E_STAGING_LIVE_HITS_ORG_TOTAL and E2E_STAGING_LIVE_HIT_LEADER_TOP10_SUM. ' +
                        'Do not soft-skip this invariant.',
                );
            }

            expect(
                seeded.top10Sum,
                'E2E_STAGING_LIVE_HIT_LEADER_TOP10_SUM must be ≤ E2E_STAGING_LIVE_HITS_ORG_TOTAL',
            ).toBeLessThanOrEqual(seeded.orgTotal);

            const sibling = card(page, STAGING_LIVE_HITS_CARD_KEY);
            await expect(sibling).toContainText(STAGING_LIVE_HITS_HEADING);

            const siblingTotal = parseCount(
                await cardValueText(page, STAGING_LIVE_HITS_CARD_KEY),
                'E sibling Staging Live Hits',
            );
            expect(siblingTotal, 'Staging Live Hits headline must be the seeded org total').toBe(
                seeded.orgTotal,
            );

            const leaderMax = await stagingLiveHitLeaderCount(page, 'E compact max kit');
            expect(leaderMax, 'compact max-kit value must be ≤ the sibling org total').toBeLessThanOrEqual(
                seeded.orgTotal,
            );

            const leaderPath = await cardSparklineLinePath(page, STAGING_LIVE_HIT_LEADER_CARD_KEY);
            const siblingPath = await cardSparklineLinePath(page, STAGING_LIVE_HITS_CARD_KEY);
            expect(
                leaderPath,
                'leaderboard sparkline path must match the Staging Live Hits org-total series, not a LIMIT-10 sum',
            ).toBe(siblingPath);
        });

        test('G. Pro — existing Company Metrics cards still render without errors', async ({ page }) => {
            await openCompanyMetrics(page);

            await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

            const keys = await dashboardCardKeys(page);

            for (const { key, heading } of COMPANY_METRICS_CARD_HEADINGS) {
                const present = keys.includes(key);

                if (!present) {
                    continue;
                }

                const compact = card(page, key);
                await expect(compact).toBeVisible();
                await expect(compact).toContainText(heading);

                const raw = await cardValueText(page, key);
                expect(raw.length, `${key} rendered an empty value`).toBeGreaterThan(0);
            }

            for (const key of [
                SCAN_COUNT_CARD_KEY,
                RECOVERY_COUNTS_CARD_KEY,
                MISSED_OPP_CARD_KEY,
                RECOVERY_LEADER_CARD_KEY,
                LIVE_HITS_CARD_KEY,
                HISTORICAL_HITS_CARD_KEY,
                STAGING_LIVE_HITS_CARD_KEY,
                STAGING_HISTORICAL_HITS_CARD_KEY,
            ]) {
                expect(keys, `${key} missing from the Pro grid`).toContain(key);
            }
        });

        test('H. Pro — Last Month empty/zero window still occupies the slot and is not locked', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const lastMonth = DATE_FILTERS.find((filter) => filter.key === 'last_month');

            if (lastMonth === undefined) {
                throw new Error('DATE_FILTERS must include last_month (label "Last Month").');
            }

            const forcedWindow = process.env.E2E_STAGING_LIVE_HIT_LEADER_ZERO_WINDOW;
            const windowFromEnv =
                forcedWindow === undefined || forcedWindow === ''
                    ? undefined
                    : DATE_FILTERS.find((filter) => filter.key === forcedWindow);

            if (forcedWindow && windowFromEnv === undefined) {
                throw new Error(
                    `E2E_STAGING_LIVE_HIT_LEADER_ZERO_WINDOW=${forcedWindow} is not a DATE_FILTERS key ` +
                        '(today|last_7d|this_month|last_month). Default path is Last Month → 0.',
                );
            }

            const target = windowFromEnv ?? lastMonth;

            await selectDateFilter(page, target.label);

            const compact = stagingLiveHitLeaderCard(page);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);

            let leaderValue = Number.NaN;

            await expect
                .poll(
                    async () => {
                        leaderValue = await stagingLiveHitLeaderCount(page, `H ${target.label}`);

                        return leaderValue;
                    },
                    {
                        timeout: 10_000,
                        message:
                            `H requires Staging Live Hit Leaderboard = 0 for ${target.label} ` +
                            '(default Last Month; runner seeds last_month to 0). ' +
                            'Do not scan Today / Last 7d / This Month and pass on a different zero.',
                    },
                )
                .toBe(0);

            expect(
                leaderValue,
                `H ${target.label} Staging Live Hit Leaderboard compact must be 0 ` +
                    `(runner seed: Last Month empty). Got ${leaderValue}.`,
            ).toBe(0);
            await expect(await cardValueText(page, STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe('0');

            const keys = await dashboardCardKeys(page);
            const siblingInGrid = keys.includes(STAGING_LIVE_HITS_CARD_KEY);

            if (siblingInGrid) {
                const sibling = card(page, STAGING_LIVE_HITS_CARD_KEY);
                await expect(sibling).toBeVisible();
                await expect(sibling).toContainText(STAGING_LIVE_HITS_HEADING);

                let siblingValue = Number.NaN;

                await expect
                    .poll(
                        async () => {
                            siblingValue = parseCount(
                                await cardValueText(page, STAGING_LIVE_HITS_CARD_KEY),
                                `H sibling Staging Live Hits ${target.label}`,
                            );

                            return siblingValue;
                        },
                        {
                            timeout: 10_000,
                            message:
                                `H ${target.label} Staging Live Hits compact must be 0 ` +
                                '(FAIL freeze showed sibling 14 while Last Month CH was 0).',
                        },
                    )
                    .toBe(0);

                expect(
                    siblingValue,
                    `H ${target.label} staging-live-hits compact must be 0. Got ${siblingValue}.`,
                ).toBe(0);
            } else {
                test.info().annotations.push({
                    type: 'note',
                    description:
                        'H: staging-live-hits sibling card missing from the grid; ' +
                        `still requiring leader=0 for ${target.label}.`,
                });
            }

            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact.getByText(UPSELL_COPY)).toHaveCount(0);

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
        const nonProStorageState = '/tmp/e2e-staging-live-hit-leader-nonpro.json';
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

            const compact = stagingLiveHitLeaderCard(page);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);
            await expect(await cardValueText(page, STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(LOCKED_VALUE);
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

            await compact.click();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toHaveCount(0);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(await cardValueText(page, STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(LOCKED_VALUE);

            await expandCard(page, SCAN_COUNT_CARD_KEY);
            await expect(expandedDetail(page)).toBeVisible();
            await compact.click();
            await expect(expandedDetail(page)).toBeVisible();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).not.toContainText(
                new RegExp(`${STAGING_LIVE_HIT_LEADER_HEADING}[\\s\\S]*Leader for`),
            );
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
        });
    });
});
