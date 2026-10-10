import { expect, type Page } from '@playwright/test';

export const COMPANY_METRICS_PATH = '/app';

export const MISSED_OPP_CARD_KEY = 'missed-opportunities';
export const RECOVERY_COUNTS_CARD_KEY = 'recovery-counts';
export const UNIQUE_LPR_CASE_CLAIMS_CARD_KEY = 'unique-lpr-case-claims';
export const LPR_REPOSSESSIONS_CARD_KEY = 'lpr-repossessions';
export const RECOVERY_LEADER_CARD_KEY = 'recovery-leader';
export const SCAN_COUNT_CARD_KEY = 'scan-count';
export const UNIQUE_SCAN_COUNTS_CARD_KEY = 'unique-scan-counts';
export const UNIQUE_SCANS_LEADERBOARD_CARD_KEY = 'unique-scans-leaderboard';
export const STAGING_LIVE_HIT_LEADER_CARD_KEY = 'staging-live-hit-leader';
export const STAGING_LIVE_HITS_CARD_KEY = 'staging-live-hits';
export const LIVE_HITS_CARD_KEY = 'live-hits';
export const HISTORICAL_HITS_CARD_KEY = 'historical-hits';
export const STAGING_HISTORICAL_HITS_CARD_KEY = 'staging-historical-hits';
export const UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY = 'unique-scans-to-case-claims';
export const UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY = 'unique-scans-to-lpr-repossession';

export const MISSED_OPP_HEADING = 'Missed Opportunities';
export const RECOVERY_COUNTS_HEADING = 'Total Repossessions';
export const UNIQUE_LPR_CASE_CLAIMS_HEADING = 'LPR Case Claims';
export const LPR_REPOSSESSIONS_HEADING = 'LPR Repossessions';
export const RECOVERY_LEADER_HEADING = 'LPR Recovery Leaderboard';
export const STAGING_LIVE_HIT_LEADER_HEADING = 'Staging Live Hit Leaderboard';
export const STAGING_LIVE_HITS_HEADING = 'Staging Live Hits';
export const SCAN_COUNT_HEADING = 'Scan Count';
export const UNIQUE_SCAN_COUNTS_HEADING = 'Scan Counts';
export const UNIQUE_SCANS_LEADERBOARD_HEADING = 'Unique Scans Leaderboard';
export const LIVE_HITS_HEADING = 'Direct Live Hits';
export const HISTORICAL_HITS_HEADING = 'Direct Historical Hits';
export const STAGING_HISTORICAL_HITS_HEADING = 'Staging Historical Hits';
export const UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING = 'Unique Scans to Case Claims';
export const UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING = 'Unique Scans to LPR Repossession';

export const COMPANY_METRICS_CARD_HEADINGS: ReadonlyArray<{ key: string; heading: string }> = [
    { key: SCAN_COUNT_CARD_KEY, heading: SCAN_COUNT_HEADING },
    { key: RECOVERY_COUNTS_CARD_KEY, heading: RECOVERY_COUNTS_HEADING },
    { key: UNIQUE_LPR_CASE_CLAIMS_CARD_KEY, heading: UNIQUE_LPR_CASE_CLAIMS_HEADING },
    { key: LPR_REPOSSESSIONS_CARD_KEY, heading: LPR_REPOSSESSIONS_HEADING },
    { key: UNIQUE_SCAN_COUNTS_CARD_KEY, heading: UNIQUE_SCAN_COUNTS_HEADING },
    { key: UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY, heading: UNIQUE_SCANS_TO_CASE_CLAIMS_HEADING },
    { key: UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY, heading: UNIQUE_SCANS_TO_LPR_REPOSSESSION_HEADING },
    { key: UNIQUE_SCANS_LEADERBOARD_CARD_KEY, heading: UNIQUE_SCANS_LEADERBOARD_HEADING },
    { key: STAGING_LIVE_HIT_LEADER_CARD_KEY, heading: STAGING_LIVE_HIT_LEADER_HEADING },
    { key: MISSED_OPP_CARD_KEY, heading: MISSED_OPP_HEADING },
    { key: RECOVERY_LEADER_CARD_KEY, heading: RECOVERY_LEADER_HEADING },
    { key: LIVE_HITS_CARD_KEY, heading: LIVE_HITS_HEADING },
    { key: HISTORICAL_HITS_CARD_KEY, heading: HISTORICAL_HITS_HEADING },
    { key: STAGING_LIVE_HITS_CARD_KEY, heading: STAGING_LIVE_HITS_HEADING },
    { key: STAGING_HISTORICAL_HITS_CARD_KEY, heading: STAGING_HISTORICAL_HITS_HEADING },
];

export const LOCKED_VALUE = '—';
export const UPSELL_COPY = 'Upgrade to Pro';

export const EXPANDED_DETAIL_ID = 'dashboard-expanded-detail';

export const DATE_FILTERS = [
    { key: 'today', label: 'Today' },
    { key: 'last_7d', label: 'Last 7d' },
    { key: 'this_month', label: 'This Month' },
    { key: 'last_month', label: 'Last Month' },
] as const;

export type DateFilterKey = (typeof DATE_FILTERS)[number]['key'];

/**
 * Resolve a Company Metrics date-filter window from an E2E_* env key
 * (`today` / `last_7d` / `this_month` / `last_month`). Undefined when unset
 * or not one of DATE_FILTERS.
 */
export function dateFilterFromEnv(name: string): (typeof DATE_FILTERS)[number] | undefined {
    const raw = process.env[name];

    if (raw === undefined || raw === '') {
        return undefined;
    }

    return DATE_FILTERS.find((filter) => filter.key === raw);
}

export function card(page: Page, key: string) {
    return page.locator(`[data-dashboard-card="${key}"]`);
}

export function missedOppCard(page: Page) {
    return card(page, MISSED_OPP_CARD_KEY);
}

export async function openCompanyMetrics(page: Page): Promise<void> {
    await page.goto(COMPANY_METRICS_PATH, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Company Metrics' })).toBeVisible();
    await expect(missedOppCard(page)).toBeVisible();
}

export function dateFilterControl(page: Page, label: string) {
    return page.locator('.fi-fo-toggle-buttons').getByText(label, { exact: true });
}

export async function selectDateFilter(page: Page, label: string): Promise<void> {
    const filter = DATE_FILTERS.find((item) => item.label === label);

    if (filter === undefined) {
        throw new Error(`Unknown Company Metrics date filter label: ${label}`);
    }

    const radio = page.locator(`.fi-fo-toggle-buttons input[type="radio"][value="${filter.key}"]`);
    await expect(radio).toBeAttached();

    const livewire = page.waitForResponse(
        (response) => response.request().method() === 'POST' && response.url().includes('livewire'),
        { timeout: 10_000 },
    );

    await radio.check({ force: true });
    await livewire.catch(() => undefined);
    await expect(radio).toBeChecked();
    await expect(missedOppCard(page)).toBeVisible();
}

/**
 * Re-read a compact card value until two consecutive reads agree after the
 * Livewire date-filter POST has settled (selectDateFilter already waits for
 * that POST). A single matching pair immediately after the click can still
 * be the prior window.
 */
export async function waitForCardValueStable(page: Page, key: string): Promise<string> {
    let previous = await cardValueText(page, key);
    let consecutiveMatches = 0;

    for (let attempt = 0; attempt < 8; attempt++) {
        await page.waitForTimeout(250);
        const current = await cardValueText(page, key);

        if (current === previous) {
            consecutiveMatches += 1;

            if (consecutiveMatches >= 2) {
                return current;
            }
        } else {
            consecutiveMatches = 0;
            previous = current;
        }
    }

    return previous;
}

export async function cardValueText(page: Page, key: string = MISSED_OPP_CARD_KEY): Promise<string> {
    const raw = await card(page, key).locator('.fi-wi-stats-overview-stat-value').innerText();

    return raw.replace(/\s+/g, ' ').trim();
}

export function parseCount(raw: string, context: string): number {
    if (raw === LOCKED_VALUE || raw === '-' || raw === '–') {
        throw new Error(`${context}: card is locked (${raw}); expected a numeric total.`);
    }

    const parsed = Number(raw.replace(/,/g, ''));

    if (!Number.isFinite(parsed)) {
        throw new Error(`${context}: could not parse card value "${raw}" as a number.`);
    }

    return parsed;
}

export async function missedOppCount(page: Page, context: string): Promise<number> {
    return parseCount(await cardValueText(page), context);
}

export function dashboardCardKeys(page: Page) {
    return page.locator('[data-dashboard-card]').evaluateAll((nodes) =>
        nodes
            .map((node) => node.getAttribute('data-dashboard-card'))
            .filter((key): key is string => typeof key === 'string' && key.length > 0),
    );
}

export function envInt(name: string): number | undefined {
    const raw = process.env[name];

    if (raw === undefined || raw === '') {
        return undefined;
    }

    const parsed = Number(raw);

    return Number.isFinite(parsed) ? parsed : undefined;
}

export function expectedDistinctCount(): { distinct: number; raw: number } | undefined {
    const distinct = envInt('E2E_MISSED_OPP_DISTINCT_COUNT');
    const raw = envInt('E2E_MISSED_OPP_RAW_COUNT');

    if (distinct === undefined || raw === undefined) {
        return undefined;
    }

    return { distinct, raw };
}

export function expectedWindowCounts(): Record<DateFilterKey, number> | undefined {
    return expectedWindowCountsFromEnv([
        'E2E_MISSED_OPP_TODAY',
        'E2E_MISSED_OPP_LAST_7D',
        'E2E_MISSED_OPP_THIS_MONTH',
        'E2E_MISSED_OPP_LAST_MONTH',
    ]);
}

/**
 * Compact Staging Live Hit Leaderboard value is the max kit in the top-10 for
 * the selected window. Sparkline is the shared Staging Live Hits org-total
 * series, not the sum of LIMIT-10 buckets.
 */
export function expectedStagingLiveHitLeaderWindowCounts(): Record<DateFilterKey, number> | undefined {
    return expectedWindowCountsFromEnv([
        'E2E_STAGING_LIVE_HIT_LEADER_TODAY',
        'E2E_STAGING_LIVE_HIT_LEADER_LAST_7D',
        'E2E_STAGING_LIVE_HIT_LEADER_THIS_MONTH',
        'E2E_STAGING_LIVE_HIT_LEADER_LAST_MONTH',
    ]);
}

function expectedWindowCountsFromEnv(
    names: [string, string, string, string],
): Record<DateFilterKey, number> | undefined {
    const today = envInt(names[0]);
    const last7d = envInt(names[1]);
    const thisMonth = envInt(names[2]);
    const lastMonth = envInt(names[3]);

    if (
        today === undefined ||
        last7d === undefined ||
        thisMonth === undefined ||
        lastMonth === undefined
    ) {
        return undefined;
    }

    return {
        today,
        last_7d: last7d,
        this_month: thisMonth,
        last_month: lastMonth,
    };
}

export function expectedStagingLiveSiblingAgreement():
    | { orgTotal: number; top10Sum: number }
    | undefined {
    const orgTotal = envInt('E2E_STAGING_LIVE_HITS_ORG_TOTAL');
    const top10Sum = envInt('E2E_STAGING_LIVE_HIT_LEADER_TOP10_SUM');

    if (orgTotal === undefined || top10Sum === undefined) {
        return undefined;
    }

    return { orgTotal, top10Sum };
}

export function stagingLiveHitLeaderCard(page: Page) {
    return card(page, STAGING_LIVE_HIT_LEADER_CARD_KEY);
}

export async function stagingLiveHitLeaderCount(page: Page, context: string): Promise<number> {
    return parseCount(await cardValueText(page, STAGING_LIVE_HIT_LEADER_CARD_KEY), context);
}

export function cardSparkline(page: Page, key: string) {
    return card(page, key).locator('.fi-wi-stats-overview-stat-chart');
}

export async function cardSparklineLinePath(page: Page, key: string): Promise<string> {
    const line = cardSparkline(page, key).locator('svg path.fi-wi-stats-overview-stat-chart-border-color');
    await expect(line).toBeAttached();

    const d = await line.getAttribute('d');

    if (d === null || d.trim() === '') {
        throw new Error(`Company Metrics card "${key}" has no sparkline line path.`);
    }

    return d;
}

export async function expandCard(page: Page, key: string): Promise<void> {
    await card(page, key).click();
    await expect(page.locator(`#${EXPANDED_DETAIL_ID}`)).toBeVisible();
}

export function expandedDetail(page: Page) {
    return page.locator(`#${EXPANDED_DETAIL_ID}`);
}

/** Absorbed by the 12-card registry. Must not appear in the grid. */
export const UNIQUE_SCANS_TO_REPO_CARD_KEY = 'unique-scans-to-repo';

export const ABSORBED_CARD_KEYS = [
    SCAN_COUNT_CARD_KEY,
    UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY,
    UNIQUE_SCANS_TO_REPO_CARD_KEY,
] as const;

/** User-facing heading after the label scrub. The card key stays unique-scans-leaderboard. */
export const SCANS_LEADERBOARD_HEADING = 'Scans Leaderboard';

/**
 * 12-card layout (registry order). Leaderboards are the last three.
 * Hit cards occupy four contiguous slots. unique-scan-counts precedes the scans leaderboard.
 */
export const DEV2651_CARD_ORDER: ReadonlyArray<{ key: string; heading: string }> = [
    { key: UNIQUE_SCAN_COUNTS_CARD_KEY, heading: UNIQUE_SCAN_COUNTS_HEADING },
    { key: UNIQUE_LPR_CASE_CLAIMS_CARD_KEY, heading: UNIQUE_LPR_CASE_CLAIMS_HEADING },
    { key: LPR_REPOSSESSIONS_CARD_KEY, heading: LPR_REPOSSESSIONS_HEADING },
    { key: RECOVERY_COUNTS_CARD_KEY, heading: RECOVERY_COUNTS_HEADING },
    { key: LIVE_HITS_CARD_KEY, heading: LIVE_HITS_HEADING },
    { key: HISTORICAL_HITS_CARD_KEY, heading: HISTORICAL_HITS_HEADING },
    { key: STAGING_LIVE_HITS_CARD_KEY, heading: STAGING_LIVE_HITS_HEADING },
    { key: STAGING_HISTORICAL_HITS_CARD_KEY, heading: STAGING_HISTORICAL_HITS_HEADING },
    { key: MISSED_OPP_CARD_KEY, heading: MISSED_OPP_HEADING },
    { key: UNIQUE_SCANS_LEADERBOARD_CARD_KEY, heading: SCANS_LEADERBOARD_HEADING },
    { key: RECOVERY_LEADER_CARD_KEY, heading: RECOVERY_LEADER_HEADING },
    { key: STAGING_LIVE_HIT_LEADER_CARD_KEY, heading: STAGING_LIVE_HIT_LEADER_HEADING },
];

export const DEV2651_HIT_CARD_KEYS = [
    LIVE_HITS_CARD_KEY,
    HISTORICAL_HITS_CARD_KEY,
    STAGING_LIVE_HITS_CARD_KEY,
    STAGING_HISTORICAL_HITS_CARD_KEY,
] as const;

export const DEV2651_RATIO_CARD_KEYS = [
    UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
    LPR_REPOSSESSIONS_CARD_KEY,
    RECOVERY_COUNTS_CARD_KEY,
] as const;

export async function cardDescriptionText(page: Page, key: string): Promise<string> {
    const description = card(page, key).locator('.fi-wi-stats-overview-stat-description');

    if ((await description.count()) === 0) {
        return '';
    }

    const raw = await description.first().innerText();

    return raw.replace(/\s+/g, ' ').trim();
}

export async function waitForSettledCardValue(page: Page, key: string): Promise<string> {
    let settled = '';

    await expect
        .poll(
            async () => {
                settled = await cardValueText(page, key);

                return settled;
            },
            { timeout: 30_000 },
        )
        .toMatch(/\S/);

    return settled;
}

/** True when the compact sparkline path is not a single horizontal stroke. */
export function sparklinePathHasVariance(path: string): boolean {
    const numbers = path.match(/-?\d+(?:\.\d+)?/g)?.map(Number) ?? [];
    const ys: number[] = [];

    for (let index = 1; index < numbers.length; index += 2) {
        ys.push(numbers[index]);
    }

    if (ys.length < 2) {
        return false;
    }

    const min = Math.min(...ys);
    const max = Math.max(...ys);

    return max - min > 0.5;
}

export type ExpandedChartSnapshot = {
    labels: string[];
    datasets: Array<{
        type: string | null;
        label: string;
        yAxisID: string | null;
        backgroundColor: string | null;
        borderColor: string | null;
        backgroundIsArray: boolean;
    }>;
    yTitle: string | null;
    y1Present: boolean;
    y1Title: string | null;
    y1DrawOnChartArea: boolean | null;
    legendDisplay: boolean | null;
    legendColors: string[];
};

export async function readExpandedChart(page: Page): Promise<ExpandedChartSnapshot> {
    const container = expandedDetail(page).locator('.fi-wi-chart-canvas-ctn');
    await expect(container).toBeVisible();

    const handle = await container.elementHandle();

    if (handle === null) {
        throw new Error('Expanded chart container did not resolve to an element.');
    }

    await page.waitForFunction(
        (el) => {
            try {
                const data = (
                    window as unknown as { Alpine?: { $data: (node: Element) => unknown } }
                ).Alpine?.$data(el as Element) as { getChart?: () => unknown } | undefined;

                return typeof data?.getChart === 'function' && data.getChart() != null;
            } catch {
                return false;
            }
        },
        handle,
        { timeout: 20_000 },
    );

    return container.evaluate((el) => {
        const colour = (value: unknown): string | null => {
            if (typeof value === 'string') {
                return value;
            }

            if (Array.isArray(value) && typeof value[0] === 'string') {
                return value[0];
            }

            return null;
        };

        const data = (
            window as unknown as {
                Alpine: { $data: (node: Element) => { getChart: () => {
                    data: { labels: string[]; datasets: Array<Record<string, unknown>> };
                    options: {
                        scales?: Record<string, { title?: { text?: string }; grid?: { drawOnChartArea?: boolean } }>;
                        plugins?: { legend?: { display?: boolean } };
                    };
                    legend?: { legendItems?: Array<{ fillStyle?: string; strokeStyle?: string }> };
                } } };
            }
        ).Alpine.$data(el);
        const chart = data.getChart();
        const scales = chart.options.scales ?? {};
        const y1 = scales.y1;

        return {
            labels: chart.data.labels ?? [],
            datasets: (chart.data.datasets ?? []).map((dataset) => ({
                type: typeof dataset.type === 'string' ? dataset.type : null,
                label: typeof dataset.label === 'string' ? dataset.label : '',
                yAxisID: typeof dataset.yAxisID === 'string' ? dataset.yAxisID : null,
                backgroundColor: colour(dataset.backgroundColor),
                borderColor: colour(dataset.borderColor),
                backgroundIsArray: Array.isArray(dataset.backgroundColor),
            })),
            yTitle: scales.y?.title?.text ?? null,
            y1Present: y1 !== undefined,
            y1Title: y1?.title?.text ?? null,
            y1DrawOnChartArea: y1?.grid?.drawOnChartArea ?? null,
            legendDisplay: chart.options.plugins?.legend?.display ?? null,
            legendColors: (chart.legend?.legendItems ?? []).map(
                (item) => item.fillStyle || item.strokeStyle || '',
            ),
        };
    });
}
