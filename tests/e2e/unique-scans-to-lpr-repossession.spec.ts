import { writeFileSync } from 'node:fs';
import { expect, type Page, test } from '@playwright/test';
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
    EXPANDED_DETAIL_ID,
    expandedDetail,
    LOCKED_VALUE,
    openCompanyMetrics,
    selectDateFilter,
    UNIQUE_SCANS_LEADERBOARD_CARD_KEY,
    UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING,
    UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY,
    UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING,
    UPSELL_COPY,
    waitForCardValueStable,
} from './helpers/company-metrics';

/**
 * DEV-2469 — Company Metrics "Unique Scans to LPR Repossession" card (Playwright).
 *
 * Product under test (corrected spec; Jira description ACs are obsolete):
 * - Compact card key `unique-scans-to-lpr-repossession`, heading
 *   "Unique Scans to LPR Repossession".
 * - Slot: after unique-scans-to-case-claims, before unique-scans-leaderboard
 *   (CompanyMetricsOverview::cards() on this tip).
 * - Value is a RATIO — unique_scans / db_repos — rendered
 *   `number_format(..., 1)` e.g. `5.0`. Not a percentage; no `%` suffix.
 *   Numerator: ScanPlateStatsBatchQuery uniqExact(ocr), org-wide, timezone UTC.
 *   Denominator: LprRepossessionsQuery db_repos (case-claim funnel recovered),
 *   not deduped, not recovery_scans. Zero denominator → 0.0 (valid).
 * - Date toggles: Today / Last 7d / This Month / Last Month.
 * - Pro gate: canViewCardData() like siblings. Non-Pro keeps the slot,
 *   LOCKED_VALUE em dash, no sparkline. Bare lock — no "Upgrade to Pro".
 * - Expanded chart: 13-point UTC monthly ratio series + 13 UTC labels.
 *
 * Isolated-cloud seed (Case B This Month = 5.0; Case H Last Month = 0.0):
 *   docs/investigations/DEV-2469/qa-ch-seed.sql
 *   docs/investigations/DEV-2469/qa/RUNNER.md
 *
 * CRITICAL login trap:
 *   GET /__qa/login/{id} sets admin_impersonator unconditionally and BYPASSES
 *   the Pro gate. Pro scenarios may use loginAsAdmin (QA login or OTP).
 *   Non-Pro locked-card scenarios MUST authenticate without that bypass:
 *   OTP at /app/login with E2E_NON_PRO_EMAIL + E2E_OTP (loginAsNonProOrgAdmin).
 *   If only the QA login is available, those tests FAIL with the trap message
 *   -- they do not skip and they do not use the bypass.
 *
 * deferLoading is ON outside the `testing` env (FilamentServiceProvider), so a
 * card's first paint can carry no value yet -- every compact-value read below
 * polls for the settled render (waitForCardValueStable + expect.poll).
 *
 * Isolated cloud / local only (127.0.0.1 / *.test). assertNotProduction runs
 * in beforeAll. Never target *.insightlpr.io.
 */
test.use({ video: 'on' });

const RATIO_PATTERN = /^\d+\.\d$/;

const SEED_HINT =
    'Apply docs/investigations/DEV-2469/qa-ch-seed.sql to the isolated ClickHouse ' +
    '(literal clarity/zod databases) before this Playwright file-scope. ' +
    'See docs/investigations/DEV-2469/qa/RUNNER.md. Do not invent E2E_* env for the ratio.';

function parseRatio(raw: string, context: string): number {
    if (raw.includes('%')) {
        throw new Error(`${context}: compact value "${raw}" has a % suffix; this card is a ratio, not a percentage.`);
    }

    const match = raw.match(RATIO_PATTERN);

    if (match === null) {
        throw new Error(`${context}: could not parse card value "${raw}" as a one-decimal ratio (e.g. "5.0").`);
    }

    return Number(match[0]);
}

async function pollCompactValue(page: Page, context: string): Promise<string> {
    await waitForCardValueStable(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);

    let raw = '';

    await expect
        .poll(
            async () => {
                raw = await cardValueText(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);

                return raw;
            },
            { timeout: 15_000 },
        )
        .toMatch(new RegExp(`^(?:${RATIO_PATTERN.source.slice(1, -1)}|${LOCKED_VALUE})$`));

    return raw;
}

/**
 * The expanded ChartWidget's Chart.js instance, reached through the Alpine
 * component `chart()` registers (`window.Alpine.$data(el).getChart()`).
 * x-load lazily fetches the chart Alpine bundle, so this waits for that
 * component to actually mount rather than reading the canvas the instant
 * the expanded detail region appears.
 */
async function waitForExpandedChartData(page: Page): Promise<{ labels: string[]; dataLength: number }> {
    const container = expandedDetail(page).locator('.fi-wi-chart-canvas-ctn');
    await expect(container).toBeVisible();

    const handle = await container.elementHandle();

    if (handle === null) {
        throw new Error('Expanded chart container did not resolve to an element.');
    }

    await page.waitForFunction(
        (el) => {
            try {
                const data = (window as unknown as { Alpine?: { $data: (el: Element) => unknown } }).Alpine?.$data(
                    el as Element,
                ) as { getChart?: () => unknown } | undefined;

                return typeof data?.getChart === 'function' && data.getChart() != null;
            } catch {
                return false;
            }
        },
        handle,
        { timeout: 15_000 },
    );

    return container.evaluate((el) => {
        const data = (window as unknown as { Alpine: { $data: (el: Element) => { getChart: () => any } } }).Alpine.$data(
            el,
        );
        const chart = data.getChart();

        return {
            labels: chart.data.labels as string[],
            dataLength: (chart.data.datasets?.[0]?.data ?? []).length as number,
        };
    });
}

test.describe('DEV-2469 Unique Scans to LPR Repossession Company Metrics', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('Pro org (loginAsAdmin)', () => {
        const proStorageState = '/tmp/e2e-unique-scans-to-lpr-repossession-pro.json';
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

        test('A. Pro — grid slot after Unique Scans to Case Claims', async ({ page }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
            await expect(card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY)).toContainText(
                UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING,
            );
            await expect(compact).toContainText(UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING);

            const keys = await dashboardCardKeys(page);
            const precedingAt = keys.indexOf(UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            const ourAt = keys.indexOf(UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
            const followingAt = keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

            expect(precedingAt, 'unique-scans-to-case-claims card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(ourAt, 'unique-scans-to-lpr-repossession card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(followingAt, 'unique-scans-leaderboard card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(ourAt, 'Unique Scans to LPR Repossession must sit directly after Unique Scans to Case Claims').toBe(
                precedingAt + 1,
            );
            expect(followingAt, 'unique-scans-leaderboard must sit directly after this card').toBe(ourAt + 1);
        });

        test('B. Pro — This Month compact value is a one-decimal ratio (seeded 5.0)', async ({ page }) => {
            await openCompanyMetrics(page);
            await selectDateFilter(page, 'This Month');

            const compact = card(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING);
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');

            const raw = await pollCompactValue(page, 'B This Month');

            expect(raw, 'B compact value must be a one-decimal ratio with no % suffix, e.g. "5.0"').toMatch(
                RATIO_PATTERN,
            );
            expect(raw, 'B compact value must not carry a percent suffix').not.toMatch(/%/);

            const ratio = parseRatio(raw, 'B This Month');
            expect(ratio, 'B ratio must not be negative').toBeGreaterThanOrEqual(0);

            if (raw !== '5.0') {
                throw new Error(
                    `B This Month should be 5.0 when the DEV-2469 seed is applied (got "${raw}"). ${SEED_HINT}`,
                );
            }
        });

        test('C. Pro — date filter cycle: Today / Last 7d / This Month / Last Month each render a settled ratio', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const observed: Partial<Record<(typeof DATE_FILTERS)[number]['key'], number>> = {};

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);

                const compact = card(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
                await expect(compact).toBeVisible();
                await expect(compact).toContainText(UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING);
                await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                const raw = await pollCompactValue(page, `C ${filter.label}`);
                expect(raw, `C ${filter.label} must be a one-decimal ratio with no %`).toMatch(RATIO_PATTERN);
                observed[filter.key] = parseRatio(raw, `C ${filter.label}`);
            }

            expect(observed.today, 'C Today produced no numeric ratio').toEqual(expect.any(Number));
            expect(observed.last_7d, 'C Last 7d produced no numeric ratio').toEqual(expect.any(Number));
            expect(observed.this_month, 'C This Month produced no numeric ratio').toEqual(expect.any(Number));
            expect(observed.last_month, 'C Last Month produced no numeric ratio').toEqual(expect.any(Number));
        });

        test('D. Pro — expanded chart shows 13 UTC month labels aligned with 13 data points', async ({
            page,
        }) => {
            await openCompanyMetrics(page);
            await pollCompactValue(page, 'D pre-expand compact value');

            await expandCard(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(
                detail.getByText(UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING, { exact: true }).first(),
            ).toBeVisible();

            const { labels, dataLength } = await waitForExpandedChartData(page);

            expect(labels, 'expanded chart must plot 13 UTC month labels').toHaveLength(13);
            expect(dataLength, 'expanded chart data points must align 1:1 with the 13 month labels').toBe(13);

            await expect(card(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY)).toHaveAttribute(
                'data-dashboard-open',
                'true',
            );
        });

        test('H. Pro — zero-denominator window (Last Month) settles at 0.0 and stays unlocked', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const scanOrder = [
                ...DATE_FILTERS.filter((filter) => filter.key === 'last_month'),
                ...DATE_FILTERS.filter((filter) => filter.key !== 'last_month'),
            ];

            let zeroLabel: string | undefined;

            for (const filter of scanOrder) {
                await selectDateFilter(page, filter.label);
                const raw = await pollCompactValue(page, `H scan ${filter.label}`);

                if (parseRatio(raw, `H scan ${filter.label}`) === 0) {
                    zeroLabel = filter.label;
                    break;
                }
            }

            if (zeroLabel === undefined) {
                throw new Error(
                    `H needs a Pro window that settles at 0.0 (zero-denominator guard). Last Month is 0.0 when the DEV-2469 seed is applied. ${SEED_HINT}`,
                );
            }

            const compact = card(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING);
            await expect(await cardValueText(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY)).toBe('0.0');
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact.getByText(UPSELL_COPY)).toHaveCount(0);

            const keys = await dashboardCardKeys(page);
            expect(keys.indexOf(UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY)).toBe(
                keys.indexOf(UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY) + 1,
            );
        });
    });

    test.describe('Non-Pro org (OTP only — no QA login bypass)', () => {
        const nonProStorageState = '/tmp/e2e-unique-scans-to-lpr-repossession-nonpro.json';
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

            const compact = card(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING);
            await expect(await cardValueText(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY)).toBe(LOCKED_VALUE);
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
            const precedingAt = keys.indexOf(UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            const ourAt = keys.indexOf(UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);
            const followingAt = keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

            expect(ourAt, 'Unique Scans to LPR Repossession must sit directly after Unique Scans to Case Claims').toBe(
                precedingAt + 1,
            );
            expect(followingAt, 'unique-scans-leaderboard must sit directly after this card').toBe(ourAt + 1);

            await compact.click();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toHaveCount(0);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(await cardValueText(page, UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY)).toBe(LOCKED_VALUE);
        });
    });
});
