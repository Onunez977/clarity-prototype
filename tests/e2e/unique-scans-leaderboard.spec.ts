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
    cardSparkline,
    cardValueText,
    DATE_FILTERS,
    dashboardCardKeys,
    expandCard,
    EXPANDED_DETAIL_ID,
    expandedDetail,
    LOCKED_VALUE,
    MISSED_OPP_CARD_KEY,
    openCompanyMetrics,
    parseCount,
    SCAN_COUNT_CARD_KEY,
    selectDateFilter,
    STAGING_LIVE_HIT_LEADER_CARD_KEY,
    STAGING_LIVE_HIT_LEADER_HEADING,
    UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCAN_COUNTS_CARD_KEY,
    UNIQUE_SCAN_COUNTS_HEADING,
    UNIQUE_SCANS_LEADERBOARD_CARD_KEY,
    UNIQUE_SCANS_LEADERBOARD_HEADING,
    UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY,
    UPSELL_COPY,
} from './helpers/company-metrics';

/**
 * DEV-2468 — Company Metrics "Unique Scans Leaderboard" card (Playwright).
 *
 * Product under test:
 * - Compact card key `unique-scans-leaderboard`, heading "Unique Scans Leaderboard".
 * - Slot: mockup row 3 col 1, after unique-scans-to-lpr-repossession and before
 *   staging-live-hit-leader.
 * - Compact value is the LEADING KIT's distinct-plate count for the selected
 *   window (`number_format` integer). 0 is a valid reading. The card must name
 *   that kit ("Top Kit: …") so the headline is not mistaken for the org total.
 * - Sparkline is the shared Unique Scan Counts org-total 13-month series, not a
 *   sum of per-kit ranks (plates seen by two kits count in both).
 * - Expanded chart is a SINGLE-SERIES kit ranking (one bar per kit, top 10),
 *   not a 13-month series. Canvas subtitle: "Top 10 kits by unique plates".
 *   Description: "Leader for {window}:" / "Runner-up for {window}:".
 * - The `kit_id = 0` WITH ROLLUP row is the org total and must never appear as
 *   a ranked kit in the compact description or expanded labels.
 * - Date toggles: Today / Last 7d / This Month / Last Month.
 * - Pro gate: canViewCardData() is role check ANDed with
 *   (Feature::active(COMPANY_METRICS) OR session admin_impersonator). Non-Pro
 *   keeps the grid slot, shows LOCKED_VALUE em dash, no sparkline. Bare lock --
 *   no lockedStatDescription/lockedStatUrl override, so this card must NOT show
 *   "Upgrade to Pro" (same asymmetry as unique-lpr-case-claims /
 *   unique-scans-to-case-claims / staging-live-hit-leader; Missed Opportunities
 *   is the sibling that does carry the upsell).
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
 */
test.use({ video: 'on' });

const INTEGER_PATTERN = /^\d{1,3}(?:,\d{3})*$/;

/** Labels the WITH ROLLUP org-total row would leak as if it were a kit. */
const ROLLUP_KIT_NAME = /^(?:total|org(?:[\s_-]*total)?|all(?:[\s_-]*kits)?|rollup|grand[\s_-]*total|0|kit[\s_-]*0)$/i;

const PRECEDING_STUB_KEY = UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY;

const BARE_LOCK_SIBLING_KEYS = [
    UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCAN_COUNTS_CARD_KEY,
    UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY,
    STAGING_LIVE_HIT_LEADER_CARD_KEY,
] as const;

async function pollCompactValue(page: Page, context: string): Promise<string> {
    let raw = '';

    await expect
        .poll(
            async () => {
                raw = await cardValueText(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

                return raw;
            },
            { timeout: 15_000 },
        )
        .toMatch(new RegExp(`^(?:${INTEGER_PATTERN.source.slice(1, -1)}|${LOCKED_VALUE})$`));

    return raw;
}

function looksLikeMonthSeries(labels: string[]): boolean {
    if (labels.length !== 13) {
        return false;
    }

    return labels.every(
        (label) =>
            /^(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b/i.test(label) ||
            /^\d{4}-\d{2}$/.test(label),
    );
}

/**
 * The expanded ChartWidget's Chart.js instance, reached through the Alpine
 * component `chart()` registers (`window.Alpine.$data(el).getChart()`). x-load
 * lazily fetches the chart Alpine bundle, so this waits for that component to
 * actually mount rather than reading the canvas the instant the expanded
 * detail region appears.
 */
async function waitForExpandedChartData(
    page: Page,
): Promise<{ labels: string[]; data: Array<number | null>; dataLength: number }> {
    const container = expandedDetail(page).locator('.fi-wi-chart-canvas-ctn');
    await expect(container).toBeVisible();

    const handle = await container.elementHandle();

    if (handle === null) {
        throw new Error('Expanded Unique Scans Leaderboard chart container did not resolve to an element.');
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
        const series = (chart.data.datasets?.[0]?.data ?? []) as Array<number | null>;

        return {
            labels: chart.data.labels as string[],
            data: series,
            dataLength: series.length,
        };
    });
}

function assertNoRollupKitRows(labels: string[], context: string): void {
    for (const label of labels) {
        const name = String(label ?? '').trim();

        expect(name.length, `${context}: kit row must have a name (empty is the ROLLUP leak)`).toBeGreaterThan(0);
        expect(name, `${context}: org-total ROLLUP must not appear as a kit row`).not.toMatch(ROLLUP_KIT_NAME);
    }
}

test.describe('DEV-2468 Unique Scans Leaderboard Company Metrics', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('Pro org (loginAsAdmin)', () => {
        const proStorageState = '/tmp/e2e-unique-scans-leaderboard-pro.json';
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

        test('A. Pro — card in mockup slot, heading Unique Scans Leaderboard', async ({ page }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_LEADERBOARD_HEADING);
            await expect(card(page, STAGING_LIVE_HIT_LEADER_CARD_KEY)).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);

            const keys = await dashboardCardKeys(page);
            const precedingAt = keys.indexOf(PRECEDING_STUB_KEY);
            const ourAt = keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            const followingAt = keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY);

            expect(precedingAt, 'unique-scans-to-lpr-repossession card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(ourAt, 'unique-scans-leaderboard card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(followingAt, 'staging-live-hit-leader card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(ourAt, 'Unique Scans Leaderboard occupies mockup row 3 col 1').toBe(precedingAt + 1);
            expect(followingAt, 'Staging Live Hit Leaderboard must sit directly after Unique Scans Leaderboard').toBe(
                ourAt + 1,
            );
        });

        test('B. Pro — compact value is an integer count (0 valid)', async ({ page }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            await expect(compact).toContainText(UNIQUE_SCANS_LEADERBOARD_HEADING);
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');

            const raw = await pollCompactValue(page, 'B compact value');

            expect(raw, 'B compact value must be an integer count (0 is a valid reading)').toMatch(INTEGER_PATTERN);

            const count = parseCount(raw, 'B compact value');
            expect(count, 'B count must not be negative').toBeGreaterThanOrEqual(0);

            await expect(compact).toContainText(/Top Kit:/);
        });

        test('C. Pro — date filter cycle Today / Last 7d / This Month / Last Month each settle', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const observed: Partial<Record<(typeof DATE_FILTERS)[number]['key'], number>> = {};

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);

                const compact = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
                await expect(compact).toBeVisible();
                await expect(compact).toContainText(UNIQUE_SCANS_LEADERBOARD_HEADING);
                await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                const raw = await pollCompactValue(page, `C ${filter.label}`);
                observed[filter.key] = parseCount(raw, `C ${filter.label}`);
            }

            expect(observed.today, 'C Today produced no integer count').toEqual(expect.any(Number));
            expect(observed.last_7d, 'C Last 7d produced no integer count').toEqual(expect.any(Number));
            expect(observed.this_month, 'C This Month produced no integer count').toEqual(expect.any(Number));
            expect(observed.last_month, 'C Last Month produced no integer count').toEqual(expect.any(Number));
        });

        test('D. Pro — expand chart: series present (13-point if applicable); not single happy-path only', async ({
            page,
        }) => {
            await openCompanyMetrics(page);
            await pollCompactValue(page, 'D pre-expand compact value');

            const sparkline = cardSparkline(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

            if ((await sparkline.count()) > 0) {
                await expect(
                    sparkline.locator('svg'),
                    'compact sparkline is the shared 13-point org-total series when drawn',
                ).toBeVisible();
            }

            await expandCard(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(detail.getByText(UNIQUE_SCANS_LEADERBOARD_HEADING, { exact: true }).first()).toBeVisible();
            await expect(detail.getByText(/Leader for (?:Today|Last 7 Days|This Month|Last Month):/)).toBeVisible();

            const first = await waitForExpandedChartData(page);

            expect(first.labels, 'expanded chart must expose a labels series').toEqual(expect.any(Array));
            expect(first.dataLength, 'expanded chart dataset must be present (empty window may be 0 bars)').toBe(
                first.labels.length,
            );

            if (looksLikeMonthSeries(first.labels)) {
                expect(first.labels, 'expanded month series must plot 13 UTC month labels').toHaveLength(13);
                expect(first.dataLength, 'expanded month series must align 1:1 with the 13 labels').toBe(13);
            } else {
                expect(
                    first.dataLength,
                    'expanded ranking is top-10 kits (not a 13-month series); empty window may be 0',
                ).toBeLessThanOrEqual(10);
            }

            await expect(card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toHaveAttribute('data-dashboard-open', 'true');

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);
                await expect(detail).toBeVisible();
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                const next = await waitForExpandedChartData(page);

                expect(next.labels, `D ${filter.label} expanded chart must keep a labels series`).toEqual(
                    expect.any(Array),
                );
                expect(next.dataLength, `D ${filter.label} dataset length must match labels`).toBe(next.labels.length);

                if (looksLikeMonthSeries(next.labels)) {
                    expect(next.labels, `D ${filter.label} month series must stay 13 points`).toHaveLength(13);
                } else {
                    expect(next.dataLength, `D ${filter.label} ranking must stay within top-10`).toBeLessThanOrEqual(10);
                }
            }
        });

        test('E. Pro — leaderboard UI does not present org total ROLLUP as a kit row (if rows visible)', async ({
            page,
        }) => {
            await openCompanyMetrics(page);
            await pollCompactValue(page, 'E pre-expand compact value');

            const compact = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            const compactText = await compact.innerText();
            const topKit = compactText.match(/Top Kit:\s*([^\n]+)/);
            const topKitName = topKit?.[1]?.trim();

            if (topKitName !== undefined && topKitName !== 'N/A') {
                expect(topKitName, 'E compact Top Kit must not be the org-total ROLLUP').not.toMatch(ROLLUP_KIT_NAME);
            }

            await expandCard(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

            const { labels, data } = await waitForExpandedChartData(page);

            if (labels.length > 0) {
                assertNoRollupKitRows(labels, 'E expanded ranking');
            }

            const keys = await dashboardCardKeys(page);

            if (keys.includes(UNIQUE_SCAN_COUNTS_CARD_KEY)) {
                const siblingRaw = await cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY);

                if (siblingRaw !== LOCKED_VALUE && siblingRaw !== '-' && siblingRaw !== '–') {
                    const orgTotal = parseCount(siblingRaw, 'E sibling Unique Scan Counts');
                    const leader = parseCount(
                        await cardValueText(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY),
                        'E compact leader',
                    );

                    expect(
                        leader,
                        'E leading kit cannot exceed the Unique Scan Counts org total (ROLLUP)',
                    ).toBeLessThanOrEqual(orgTotal);

                    for (const value of data) {
                        if (typeof value === 'number') {
                            expect(
                                value,
                                'E a ranked kit bar cannot exceed the Unique Scan Counts org total',
                            ).toBeLessThanOrEqual(orgTotal);
                        }
                    }
                }
            }
        });

        test('G. Empty/fail: cold or empty still no crash', async ({ page }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_LEADERBOARD_HEADING);
            await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

            let emptyLabel: string | undefined;

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);
                await expect(compact).toBeVisible();
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                const raw = await pollCompactValue(page, `G scan ${filter.label}`);

                if (raw === '0') {
                    emptyLabel = filter.label;
                    break;
                }
            }

            await expandCard(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            await expect(expandedDetail(page)).toBeVisible();
            await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

            const series = await waitForExpandedChartData(page);
            expect(series.labels, 'G expanded chart must still mount a series (may be empty)').toEqual(expect.any(Array));
            expect(series.dataLength).toBe(series.labels.length);

            if (emptyLabel !== undefined) {
                await expect(await cardValueText(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toBe('0');
                await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            } else {
                test.info().annotations.push({
                    type: 'note',
                    description:
                        'G: no zero window on this org; still required the card and expanded chart to mount without a crash.',
                });
            }
        });

        test('H. Related control: another Company Metrics card still on grid (no regression)', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

            const sibling = card(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
            await expect(sibling).toBeVisible();
            await expect(sibling).toContainText(UNIQUE_SCAN_COUNTS_HEADING);

            const neighbor = card(page, STAGING_LIVE_HIT_LEADER_CARD_KEY);
            await expect(neighbor).toBeVisible();
            await expect(neighbor).toContainText(STAGING_LIVE_HIT_LEADER_HEADING);

            const keys = await dashboardCardKeys(page);
            expect(keys, 'unique-scan-counts missing from the Pro grid').toContain(UNIQUE_SCAN_COUNTS_CARD_KEY);
            expect(keys, 'staging-live-hit-leader missing from the Pro grid').toContain(
                STAGING_LIVE_HIT_LEADER_CARD_KEY,
            );
            expect(keys, 'scan-count missing from the Pro grid').toContain(SCAN_COUNT_CARD_KEY);
            expect(keys, 'unique-scans-leaderboard missing from the Pro grid').toContain(
                UNIQUE_SCANS_LEADERBOARD_CARD_KEY,
            );

            const siblingRaw = await cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
            expect(siblingRaw.length, 'H Unique Scan Counts rendered an empty value').toBeGreaterThan(0);
        });
    });

    test.describe('Non-Pro org (OTP only — no QA login bypass)', () => {
        const nonProStorageState = '/tmp/e2e-unique-scans-leaderboard-nonpro.json';
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

        test('F. Non-Pro — locked bare (OTP loginAsNonProOrgAdmin): slot kept, locked placeholder; Upgrade to Pro vs bare-lock siblings', async ({
            page,
        }) => {
            if (!process.env.E2E_NON_PRO_EMAIL) {
                throw new Error(QA_LOGIN_PRO_GATE_BYPASS);
            }

            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_LEADERBOARD_HEADING);
            await expect(await cardValueText(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toBe(LOCKED_VALUE);
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
            expect(keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toBe(keys.indexOf(PRECEDING_STUB_KEY) + 1);
            expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(
                keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY) + 1,
            );

            for (const siblingKey of BARE_LOCK_SIBLING_KEYS) {
                if (!keys.includes(siblingKey)) {
                    continue;
                }

                const sibling = card(page, siblingKey);

                if ((await sibling.getAttribute('data-dashboard-locked')) === 'true') {
                    await expect(
                        sibling.getByText(UPSELL_COPY),
                        `${siblingKey} is a bare-lock sibling and must not show Upgrade to Pro`,
                    ).toHaveCount(0);
                }
            }

            if (keys.includes(MISSED_OPP_CARD_KEY)) {
                const missed = card(page, MISSED_OPP_CARD_KEY);

                if ((await missed.getAttribute('data-dashboard-locked')) === 'true') {
                    await expect(
                        missed.getByText(UPSELL_COPY),
                        'Missed Opportunities is the upsell sibling: Upgrade to Pro must remain on that card',
                    ).toHaveCount(1);
                }
            }

            await compact.click();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toHaveCount(0);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(await cardValueText(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toBe(LOCKED_VALUE);

            await expandCard(page, SCAN_COUNT_CARD_KEY);
            await expect(expandedDetail(page)).toBeVisible();
            await compact.click();
            await expect(expandedDetail(page)).toBeVisible();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).not.toContainText(
                new RegExp(`${UNIQUE_SCANS_LEADERBOARD_HEADING}[\\s\\S]*Leader for`),
            );
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
        });
    });
});
