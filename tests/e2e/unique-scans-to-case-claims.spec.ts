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
    dateFilterFromEnv,
    expandCard,
    EXPANDED_DETAIL_ID,
    expandedDetail,
    LOCKED_VALUE,
    openCompanyMetrics,
    selectDateFilter,
    UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING,
    UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY,
    UPSELL_COPY,
} from './helpers/company-metrics';

/**
 * DEV-2584 — Company Metrics "Unique Scans to Case Claims" card (Playwright).
 * The card renders unique_scans / deduped (a RATIO), not the earlier
 * pre_claim / deduped * 100 share-of-claims reading.
 *
 * Product under test:
 * - Compact card key `unique-scans-to-case-claims`, heading "Unique Scans to Case Claims".
 * - Slot: mockup row 2 col 3, between "Unique Scans to Repo" (stub) and
 *   "Unique Scans to LPR Repossession" (stub).
 * - Value shown is a RATIO -- unique_scans / deduped, both already triple-grained by
 *   UniqueLprCaseClaimsQuery for the denominator -- rendered `number_format(..., 1)`, no
 *   percent suffix. This is an UNBOUNDED, non-negative value (unlike the share-of-claims
 *   reading it replaced): a bare zero denominator legitimately renders "0.0" (most orgs
 *   have no case-claim activity), so 0.0 is a VALID reading, not a failure. Scenario E
 *   asserts that compact "0.0" when a zero-claims window is available.
 * - Date toggles on the dashboard page: Today / Last 7d / This Month / Last Month.
 * - Pro gate: canViewCardData() mirrors UniqueLprCaseClaims -- role check ANDed with
 *   (Feature::active(COMPANY_METRICS) OR session admin_impersonator). Non-Pro keeps the
 *   grid slot, shows LOCKED_VALUE em dash, no sparkline. Bare lock -- no
 *   lockedStatDescription/lockedStatUrl override, so this card must NOT show
 *   "Upgrade to Pro" (same asymmetry as unique-lpr-case-claims / staging-live-hit-leader).
 * - Expanded chart: a 13-point UTC-bucketed monthly RATIO series (getMonthlyData() stores
 *   the ratio itself, not the raw `deduped` count) with a co-varying 13-label UTC axis
 *   (getGraphLabels()) -- see UniqueScansToCaseClaims's docblock. Compact sparkline is
 *   a separate SVG (scenario H), not that expanded Chart.js canvas (scenario D).
 *
 * CRITICAL login trap:
 *   GET /__qa/login/{id} sets admin_impersonator unconditionally and BYPASSES the Pro
 *   gate. Pro scenarios may use loginAsAdmin (QA login or OTP). Non-Pro locked-card
 *   scenarios MUST authenticate without that bypass: OTP at /app/login with
 *   E2E_NON_PRO_EMAIL (+ E2E_OTP only in production) (loginAsNonProOrgAdmin). If only the QA login is
 *   available, those tests FAIL with the trap message -- they do not skip and they do
 *   not use the bypass.
 *
 * deferLoading is ON outside the `testing` env (FilamentServiceProvider), so a card's
 * first paint can carry no value yet -- every compact-value read below polls for the
 * settled render rather than asserting the first paint.
 *
 * Isolated cloud / local only (127.0.0.1 / *.test). assertNotProduction runs in
 * beforeAll. Never target *.insightlpr.io.
 */
test.use({ video: 'on' });

// number_format($ratio, 1) emits a thousands separator above 999 -- the card's own
// docblock names 3295.6 as a representative value (number_format(3295.6, 1) === "3,295.6"),
// so the pattern must admit commas or it rejects the very magnitude this card documents.
const RATIO_PATTERN = /^\d{1,3}(?:,\d{3})*\.\d$/;
const PERCENT_LANGUAGE = /(?:%|\bpercent(?:age)?s?\b|\bpct\b)/i;
const RATIO_WORDING = /scans per case claim|unique (?:plates )?scanned per case claim|unique scans divided by/i;
const ZERO_WINDOW_ENV = 'E2E_UNIQUE_SCANS_TO_CASE_CLAIMS_ZERO_WINDOW';
const ZERO_WINDOW_HINT =
    `Set ${ZERO_WINDOW_ENV}=today|last_7d|this_month|last_month on a Pro org whose ` +
    'selected window has zero case claims (zero denominator).';

function parseRatio(raw: string, context: string): number {
    if (raw.includes('%')) {
        throw new Error(`${context}: compact value "${raw}" has a % suffix; this card is a ratio, not a percentage.`);
    }

    const match = raw.match(RATIO_PATTERN);

    if (match === null) {
        throw new Error(`${context}: could not parse card value "${raw}" as a one-decimal ratio (e.g. "5.0").`);
    }

    return Number(match[0].replace(/,/g, ''));
}

async function pollCompactValue(page: Page, context: string): Promise<string> {
    let raw = '';

    await expect
        .poll(
            async () => {
                raw = await cardValueText(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);

                return raw;
            },
            { timeout: 15_000 },
        )
        .toMatch(new RegExp(`^(?:${RATIO_PATTERN.source.slice(1, -1)}|${LOCKED_VALUE})$`));

    return raw;
}

/**
 * The expanded ChartWidget's Chart.js instance, reached through the Alpine component
 * `chart()` registers (`window.Alpine.$data(el).getChart()`). x-load lazily fetches the
 * chart Alpine bundle, so this waits for that component to actually mount rather than
 * reading the canvas the instant the expanded detail region appears.
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

test.describe('DEV-2584 Unique Scans to Case Claims Company Metrics', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('Pro org (loginAsAdmin)', () => {
        const proStorageState = '/tmp/e2e-unique-scans-to-case-claims-pro.json';
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

        test('A. Pro — mockup slot between LPR Repossessions and Unique Scans to LPR Repossession', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const compact = card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toContainText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING);

            const keys = await dashboardCardKeys(page);
            const precedingAt = keys.indexOf('unique-scans-to-repo');
            const ourAt = keys.indexOf(UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            const followingAt = keys.indexOf(UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);

            expect(precedingAt, 'unique-scans-to-repo card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(ourAt, 'unique-scans-to-case-claims card missing from the grid').toBeGreaterThanOrEqual(0);
            expect(followingAt, 'unique-scans-to-lpr-repossession card missing from the grid').toBeGreaterThanOrEqual(
                0,
            );
            expect(ourAt, 'Unique Scans to Case Claims must sit directly after Unique Scans to Repo').toBe(
                precedingAt + 1,
            );
            expect(followingAt, 'Unique Scans to LPR Repossession must sit directly after this card').toBe(
                ourAt + 1,
            );
        });

        test('B. Pro — renders an unbounded ratio with exactly one decimal place, no % suffix', async ({ page }) => {
            await openCompanyMetrics(page);

            const raw = await pollCompactValue(page, 'B compact value');

            expect(raw, 'B compact value must be a one-decimal ratio with no % suffix, e.g. "5.0"').toMatch(
                RATIO_PATTERN,
            );
            expect(raw, 'B compact value must not carry a percent suffix').not.toMatch(/%/);

            const ratio = parseRatio(raw, 'B compact value');
            expect(ratio, 'B ratio must not be negative').toBeGreaterThanOrEqual(0);
        });

        test('C. Pro — date filter cycle: Today / Last 7d / This Month / Last Month each render their own ratio', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const observed: Partial<Record<(typeof DATE_FILTERS)[number]['key'], number>> = {};

            for (const filter of DATE_FILTERS) {
                await selectDateFilter(page, filter.label);

                const compact = card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
                await expect(compact).toBeVisible();
                await expect(compact).toContainText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING);
                await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
                await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

                const raw = await pollCompactValue(page, `C ${filter.label}`);
                observed[filter.key] = parseRatio(raw, `C ${filter.label}`);
            }

            expect(observed.today, 'C Today produced no numeric ratio').toEqual(expect.any(Number));
            expect(observed.last_7d, 'C Last 7d produced no numeric ratio').toEqual(expect.any(Number));
            expect(observed.this_month, 'C This Month produced no numeric ratio').toEqual(expect.any(Number));
            expect(observed.last_month, 'C Last Month produced no numeric ratio').toEqual(expect.any(Number));
        });

        test('D. Pro — expanded chart shows 13 month labels aligned with 13 data points', async ({ page }) => {
            await openCompanyMetrics(page);
            await pollCompactValue(page, 'D pre-expand compact value');

            await expandCard(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(detail.getByText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING, { exact: true }).first()).toBeVisible();

            const { labels, dataLength } = await waitForExpandedChartData(page);

            expect(labels, 'expanded chart must plot 13 UTC month labels').toHaveLength(13);
            expect(dataLength, 'expanded chart data points must align 1:1 with the 13 month labels').toBe(13);

            await expect(card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY)).toHaveAttribute(
                'data-dashboard-open',
                'true',
            );
        });

        test('E. Pro — zero case-claims / zero denominator settles at 0.0 (valid ratio, not error)', async ({
            page,
        }) => {
            await openCompanyMetrics(page);

            const windowFromEnv = dateFilterFromEnv(ZERO_WINDOW_ENV);
            let zeroLabel: string | undefined;

            if (windowFromEnv !== undefined) {
                await selectDateFilter(page, windowFromEnv.label);
                const raw = await pollCompactValue(page, `E ${windowFromEnv.label}`);
                expect(raw, `${ZERO_WINDOW_ENV}=${windowFromEnv.key} must render 0.0`).toBe('0.0');
                expect(raw, 'E compact 0.0 must not carry a percent suffix').not.toMatch(PERCENT_LANGUAGE);
                zeroLabel = windowFromEnv.label;
            } else {
                for (const filter of DATE_FILTERS) {
                    await selectDateFilter(page, filter.label);
                    const raw = await pollCompactValue(page, `E scan ${filter.label}`);

                    if (raw === '0.0') {
                        zeroLabel = filter.label;
                        break;
                    }
                }
            }

            const compact = card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING);
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            await expect(page.getByText(/Server Error|ErrorException/i)).toHaveCount(0);

            expect('0.0', 'RATIO_PATTERN must treat 0.0 as a valid one-decimal ratio').toMatch(RATIO_PATTERN);
            expect(parseRatio('0.0', 'E zero-denominator fixture')).toBe(0);

            if (zeroLabel !== undefined) {
                const raw = await cardValueText(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
                expect(raw, `E ${zeroLabel} compact value`).toBe('0.0');
                expect(raw, 'E live 0.0 must not carry a percent suffix').not.toMatch(PERCENT_LANGUAGE);
                expect(parseRatio(raw, `E ${zeroLabel}`)).toBe(0);
            } else {
                const raw = await pollCompactValue(page, 'E live compact');
                expect(raw, 'E live compact must still be a one-decimal ratio').toMatch(RATIO_PATTERN);
                expect(raw, 'E live compact must not carry a percent suffix').not.toMatch(PERCENT_LANGUAGE);
                expect(parseRatio(raw, 'E live compact')).toBeGreaterThanOrEqual(0);
                test.info().annotations.push({
                    type: 'note',
                    description:
                        'E: no zero-claims window on this org. Helper DATE_FILTERS + RATIO_PATTERN still pin 0.0 as a valid ratio (not an error, not percent). ' +
                        ZERO_WINDOW_HINT,
                });
            }
        });

        test('G. Pro — tooltip, description, and expanded copy use ratio wording (no percent language)', async ({
            page,
        }) => {
            await openCompanyMetrics(page);
            await pollCompactValue(page, 'G compact value');

            const compact = card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING);

            const tooltip = await compact.getAttribute('data-description');
            expect(tooltip, 'G compact data-description must be present').toBeTruthy();
            expect(tooltip, 'G tooltip must not use percent language').not.toMatch(PERCENT_LANGUAGE);
            expect(tooltip, 'G tooltip must use ratio wording').toMatch(RATIO_WORDING);

            const tooltipPanel = compact.locator('.absolute.right-0.top-full');
            if ((await tooltipPanel.count()) > 0) {
                const tooltipCopy = (
                    await tooltipPanel.evaluate((el) => (el.textContent ?? '').replace(/\s+/g, ' ').trim())
                );
                expect(tooltipCopy, 'G tooltip panel must not use percent language').not.toMatch(PERCENT_LANGUAGE);
                expect(tooltipCopy, 'G tooltip panel must use ratio wording').toMatch(RATIO_WORDING);
            }

            const description = compact.locator('.fi-wi-stats-overview-stat-description');
            await expect(description).toBeVisible();
            const descriptionCopy = (await description.innerText()).trim();
            expect(descriptionCopy, 'G compact description must not use percent language').not.toMatch(
                PERCENT_LANGUAGE,
            );
            expect(descriptionCopy, 'G compact description must use ratio wording').toMatch(RATIO_WORDING);

            await expandCard(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);

            const detail = expandedDetail(page);
            await expect(detail).toBeVisible();
            await expect(detail.getByText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING, { exact: true }).first()).toBeVisible();

            const expandedCopy = await detail.evaluate((root) => {
                const clone = root.cloneNode(true) as HTMLElement;
                clone
                    .querySelectorAll('pre, code, canvas, .fi-wi-chart-canvas-ctn')
                    .forEach((node) => node.remove());

                return (clone.textContent ?? '').replace(/\s+/g, ' ').trim();
            });
            expect(expandedCopy, 'G expanded copy must not use percent language').not.toMatch(PERCENT_LANGUAGE);
            expect(expandedCopy, 'G expanded copy must use ratio wording').toMatch(RATIO_WORDING);
        });

        test('H. Pro — unlocked compact card shows a sparkline for the ratio series', async ({ page }) => {
            await openCompanyMetrics(page);
            await pollCompactValue(page, 'H compact value');

            const compact = card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING);
            await expect(compact).not.toHaveAttribute('data-dashboard-locked', 'true');
            await expect(compact).not.toHaveAttribute('data-dashboard-open', 'true');

            const sparkline = cardSparkline(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            await expect(sparkline, 'H compact sparkline is the unlocked ratio series, not the expanded chart').toBeVisible();
            await expect(sparkline.locator('svg')).toBeVisible();
            await expect(sparkline.locator('svg path.fi-wi-stats-overview-stat-chart-border-color')).toBeVisible();

            await expect(
                compact.locator('.fi-wi-chart-canvas-ctn'),
                'H must not treat the expanded Chart.js canvas as the compact sparkline',
            ).toHaveCount(0);
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toHaveCount(0);
        });
    });

    test.describe('Non-Pro org (OTP only — no QA login bypass)', () => {
        const nonProStorageState = '/tmp/e2e-unique-scans-to-case-claims-nonpro.json';
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

            const compact = card(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            await expect(compact).toBeVisible();
            await expect(compact).toContainText(UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING);
            await expect(await cardValueText(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY)).toBe(LOCKED_VALUE);
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
            const precedingAt = keys.indexOf('unique-scans-to-repo');
            const ourAt = keys.indexOf(UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY);
            const followingAt = keys.indexOf(UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY);

            expect(ourAt, 'Unique Scans to Case Claims must sit directly after Unique Scans to Repo').toBe(
                precedingAt + 1,
            );
            expect(followingAt, 'Unique Scans to LPR Repossession must sit directly after this card').toBe(
                ourAt + 1,
            );

            await compact.click();
            await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toHaveCount(0);
            await expect(compact).toHaveAttribute('data-dashboard-locked', 'true');
            await expect(await cardValueText(page, UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY)).toBe(LOCKED_VALUE);
        });
    });
});
