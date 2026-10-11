import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { expect, type Page, test } from '@playwright/test';
import { assertNotProduction, loginViaOtp, rewriteForcedHttpsForLocalHttp } from './helpers/auth';
import {
    ABSORBED_CARD_KEYS,
    card,
    cardDescriptionText,
    cardSparklineLinePath,
    cardValueText,
    DEV2651_CARD_ORDER,
    DEV2651_HIT_CARD_KEYS,
    DEV2651_RATIO_CARD_KEYS,
    dashboardCardKeys,
    dateFilterControl,
    expandCard,
    expandedDetail,
    HISTORICAL_HITS_CARD_KEY,
    LIVE_HITS_CARD_KEY,
    LOCKED_VALUE,
    LPR_REPOSSESSIONS_CARD_KEY,
    openCompanyMetrics,
    readExpandedChart,
    RECOVERY_COUNTS_CARD_KEY,
    SCANS_LEADERBOARD_HEADING,
    selectDateFilter,
    sparklinePathHasVariance,
    STAGING_HISTORICAL_HITS_CARD_KEY,
    STAGING_LIVE_HIT_LEADER_CARD_KEY,
    STAGING_LIVE_HITS_CARD_KEY,
    UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
    UNIQUE_SCAN_COUNTS_CARD_KEY,
    UNIQUE_SCANS_LEADERBOARD_CARD_KEY,
    waitForSettledCardValue,
    type ExpandedChartSnapshot,
} from './helpers/company-metrics';

/**
 * DEV-2651 Company Metrics registry (16→12), dual-axis charts, label scrub,
 * and the recovery-counts partial gate. Real /app/login only — never /__qa/login.
 */

const BASE_URL = process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000';
const PRO_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'test@tester.com';
const EMPTY_EMAIL = process.env.E2E_EMPTY_EMAIL ?? 'qa-empty@example.test';
const NON_PRO_EMAIL = process.env.E2E_NON_PRO_EMAIL ?? 'qa-nonpro@example.test';
const PRO_ORG_ID = process.env.E2E_PRO_ORG_ID ?? '2';
const RATIO_AMBER = [245, 158, 11];

const pageProblems: string[] = [];

function remember(kind: string, detail: string): void {
    pageProblems.push(`${kind}: ${detail}`);
}

function attachWatchers(page: Page): void {
    page.on('pageerror', (error) => {
        if (error.message.includes('status code 401')) {
            return;
        }

        remember('pageerror', error.message);
    });
    page.on('console', (message) => {
        if (message.type() !== 'error') {
            return;
        }

        const text = message.text();

        if (text.includes('/user/dti') || text.includes('status of 401')) {
            return;
        }

        remember('console', text);
    });
    page.on('response', (response) => {
        const status = response.status();
        const url = response.url();

        if (status >= 500) {
            remember('http', `${status} ${url}`);
        }
    });
}

function deletedKeyMentions(): string[] {
    return pageProblems.filter((line) =>
        ABSORBED_CARD_KEYS.some((key) => line.toLowerCase().includes(key)),
    );
}

function setNumeratorFailureMarker(orgId: string, present: boolean): void {
    const script = '/tmp/qa-2651-marker.php';
    const marker = present ? 'cache()->put($key, true, 120);' : 'cache()->forget($key);';
    writeFileSync(
        script,
        [
            '<?php',
            "require '" + process.cwd() + "/vendor/autoload.php';",
            "$app = require '" + process.cwd() + "/bootstrap/app.php';",
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            '$key = App\\Clickhouse\\Queries\\ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey(' + Number(orgId) + ');',
            marker,
            'echo $key, PHP_EOL;',
        ].join('\n'),
    );
    execSync(`php ${script}`, { cwd: process.cwd(), stdio: 'pipe' });
}

function clearAppCache(): void {
    execSync('php artisan cache:clear --no-interaction', { cwd: process.cwd(), stdio: 'pipe' });
}

async function signIn(page: Page, email: string): Promise<void> {
    attachWatchers(page);
    await loginViaOtp(page, email);
}

async function settledGrid(page: Page): Promise<string[]> {
    await openCompanyMetrics(page);
    const keys = await dashboardCardKeys(page);

    for (const { key } of DEV2651_CARD_ORDER) {
        if (keys.includes(key)) {
            await waitForSettledCardValue(page, key);
        }
    }

    return dashboardCardKeys(page);
}

function parseCount(raw: string): number {
    return Number(raw.replace(/,/g, ''));
}

function rgbaChannels(colour: string | null): number[] | null {
    if (colour === null) {
        return null;
    }

    const match = colour.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);

    return match === null ? null : [Number(match[1]), Number(match[2]), Number(match[3])];
}

function channelsClose(left: number[] | null, right: number[] | null): boolean {
    if (left === null || right === null || left.length < 3 || right.length < 3) {
        return false;
    }

    return left.slice(0, 3).every((channel, index) => Math.abs(channel - right[index]) <= 2);
}

function legendMatchesDataset(chart: ExpandedChartSnapshot): boolean {
    if (chart.legendColors.length !== chart.datasets.length) {
        return false;
    }

    const seriesColours = chart.datasets.flatMap((dataset) => [dataset.backgroundColor, dataset.borderColor]);

    return chart.legendColors.every((legend) =>
        seriesColours.some((colour) => channelsClose(rgbaChannels(legend), rgbaChannels(colour))),
    );
}

test.describe.configure({ timeout: 120_000 });
test.use({
    video: 'on',
    viewport: { width: 1280, height: 900 },
    baseURL: BASE_URL,
});

test.beforeAll(() => {
    assertNotProduction(BASE_URL);
});

test.describe('empty Pro org', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, EMPTY_EMAIL);
    });

    test('A empty org shows safe zeros and ratio 0.0', async ({ page }) => {
        await settledGrid(page);

        const scans = await cardDescriptionText(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const claims = await cardDescriptionText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
        const repos = await cardDescriptionText(page, LPR_REPOSSESSIONS_CARD_KEY);
        const recovery = await cardDescriptionText(page, RECOVERY_COUNTS_CARD_KEY);

        expect(await cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY)).toBe('0');
        expect(scans).toBe('0 total scans');
        expect(claims).toBe('0.0 scans per case claim');
        expect(repos).toBe('0.0 scans per LPR repo');
        expect(recovery).toBe('0.0 scans per repo');
        expect(pageProblems.filter((line) => line.startsWith('pageerror') || line.startsWith('http'))).toEqual([]);

        await expandCard(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const chart = await readExpandedChart(page);
        expect(chart.datasets.length).toBeGreaterThan(0);
        expect(chart.y1Present).toBe(false);
    });
});

test.describe('populated Pro org', () => {
    const proStorage = '/tmp/e2e-2651-pro.json';
    writeFileSync(proStorage, JSON.stringify({ cookies: [], origins: [] }));

    test.use({ storageState: proStorage });

    test.beforeAll(async ({ browser }) => {
        clearAppCache();
        const context = await browser.newContext({
            baseURL: BASE_URL,
            ignoreHTTPSErrors: true,
            storageState: { cookies: [], origins: [] },
        });
        const page = await context.newPage();
        await loginViaOtp(page, PRO_EMAIL);
        await context.storageState({ path: proStorage });
        await context.close();
    });

    test.beforeEach(async ({ page }) => {
        await rewriteForcedHttpsForLocalHttp(page);
        attachWatchers(page);
    });

    test('B populated descriptions and total >= distinct', async ({ page }) => {
        await settledGrid(page);

        const distinct = parseCount(await cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY));
        const scans = await cardDescriptionText(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const total = parseCount(scans.replace(' total scans', ''));

        expect(scans).toMatch(/^\d{1,3}(?:,\d{3})* total scans$/);
        expect(await cardDescriptionText(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toMatch(
            /^\d{1,3}(?:,\d{3})*\.\d scans per case claim$/,
        );
        expect(await cardDescriptionText(page, LPR_REPOSSESSIONS_CARD_KEY)).toMatch(
            /^\d{1,3}(?:,\d{3})*\.\d scans per LPR repo$/,
        );
        expect(await cardDescriptionText(page, RECOVERY_COUNTS_CARD_KEY)).toMatch(
            /^\d{1,3}(?:,\d{3})*\.\d scans per repo$/,
        );
        expect(total).toBeGreaterThan(0);
        expect(distinct).toBeGreaterThan(0);
        expect(total).toBeGreaterThanOrEqual(distinct);

        for (const key of [
            UNIQUE_SCAN_COUNTS_CARD_KEY,
            UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
            LPR_REPOSSESSIONS_CARD_KEY,
            RECOVERY_COUNTS_CARD_KEY,
        ]) {
            await expandCard(page, key);
            await expect(expandedDetail(page)).toBeVisible();
            await card(page, key).click();
            await expect(expandedDetail(page)).toBeHidden();
        }
    });

    test('C cold cache then warm reload keeps leaderboard after unique-scan-counts', async ({ page }) => {
        clearAppCache();
        const keys = await settledGrid(page);
        expect(keys.indexOf(UNIQUE_SCAN_COUNTS_CARD_KEY)).toBeLessThan(
            keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY),
        );

        const coldSpark = await cardSparklineLinePath(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
        expect(sparklinePathHasVariance(coldSpark)).toBe(true);

        const coldValues: Record<string, string> = {};

        for (const { key } of DEV2651_CARD_ORDER) {
            coldValues[key] = await cardValueText(page, key);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await settledGrid(page);

        for (const { key } of DEV2651_CARD_ORDER) {
            expect(await cardValueText(page, key), key).toBe(coldValues[key]);
        }

        const warmSpark = await cardSparklineLinePath(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
        expect(sparklinePathHasVariance(warmSpark)).toBe(true);
        await expect(card(page, UNIQUE_SCAN_COUNTS_CARD_KEY)).not.toContainText(LOCKED_VALUE);
    });

    test('D exactly 12 cards, absorbed keys absent, order and contiguous hits', async ({ page }) => {
        const keys = await settledGrid(page);

        expect(keys).toEqual(DEV2651_CARD_ORDER.map((item) => item.key));
        expect(keys).toHaveLength(12);

        for (const absorbed of ABSORBED_CARD_KEYS) {
            expect(keys).not.toContain(absorbed);
            await expect(card(page, absorbed)).toHaveCount(0);
        }

        expect(keys.slice(-3)).toEqual([
            UNIQUE_SCANS_LEADERBOARD_CARD_KEY,
            'recovery-leader',
            STAGING_LIVE_HIT_LEADER_CARD_KEY,
        ]);

        const hitPositions = DEV2651_HIT_CARD_KEYS.map((key) => keys.indexOf(key)).sort((a, b) => a - b);
        expect(hitPositions).toEqual([
            hitPositions[0],
            hitPositions[0] + 1,
            hitPositions[0] + 2,
            hitPositions[0] + 3,
        ]);

        for (const { key, heading } of DEV2651_CARD_ORDER) {
            await expect(card(page, key)).toContainText(heading);
        }
    });

    test('E responsive full-width grid at default, md, and lg', async ({ page }) => {
        const viewports = [
            { name: 'default', width: 390, height: 844, columns: 1 },
            { name: 'md', width: 768, height: 900, columns: 2 },
            { name: 'lg', width: 1280, height: 900, columns: 4 },
        ];

        for (const viewport of viewports) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await settledGrid(page);

            const metrics = await page.locator('[data-dashboard-card="unique-scan-counts"]').evaluate((node) => {
                const grid = node.closest('.fi-grid');
                const widget = node.closest('.fi-wi-stats-overview') ?? grid;
                const main = document.querySelector('main') ?? document.body;
                const template = grid ? getComputedStyle(grid).gridTemplateColumns : '';
                const columns = template.split(' ').filter((part) => part.trim() !== '').length;
                const cards = [...(grid?.querySelectorAll(':scope > *') ?? [])].map((cardNode) => {
                    const box = cardNode.getBoundingClientRect();

                    return { top: Math.round(box.top), width: Math.round(box.width) };
                });

                return {
                    gridWidth: widget?.getBoundingClientRect().width ?? 0,
                    sectionWidth: main.getBoundingClientRect().width,
                    columns,
                    template,
                    cardCount: grid?.querySelectorAll('[data-dashboard-card]').length ?? 0,
                    cards,
                };
            });

            expect(metrics.gridWidth / metrics.sectionWidth, `${viewport.name} full-width ${metrics.gridWidth}/${metrics.sectionWidth}`).toBeGreaterThan(0.85);
            expect(metrics.columns, `${viewport.name} ${metrics.template}`).toBe(viewport.columns);
            expect(metrics.cardCount, viewport.name).toBe(12);

            const rows = new Map<number, number>();

            for (const item of metrics.cards) {
                const bucket = [...rows.keys()].find((top) => Math.abs(top - item.top) < 12) ?? item.top;
                rows.set(bucket, (rows.get(bucket) ?? 0) + 1);
            }

            const counts = [...rows.values()];
            expect(Math.max(...counts), viewport.name).toBe(viewport.columns);
        }

        await page.setViewportSize({ width: 768, height: 900 });
        await expandCard(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
        const chart = await readExpandedChart(page);
        const canvasBox = await expandedDetail(page).locator('canvas').boundingBox();
        expect(canvasBox).not.toBeNull();
        expect(canvasBox!.width).toBeGreaterThan(200);
        expect(chart.legendDisplay).toBe(true);
        await page.screenshot({
            path: '/opt/cursor/artifacts/qa-2651-responsive-md.png',
            fullPage: true,
        });
    });

    test('F expanded ratio cards use a labelled y1 line and matching swatches', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await settledGrid(page);
        await page.screenshot({
            path: '/opt/cursor/artifacts/qa-2651-grid-12.png',
            fullPage: true,
        });

        const expected: Record<string, { file: string; y: string; y1: string; line: string }> = {
            [UNIQUE_LPR_CASE_CLAIMS_CARD_KEY]: {
                file: '/opt/cursor/artifacts/qa-2651-expand-unique-lpr-case-claims.png',
                y: 'Case Claims',
                y1: 'Scans per Case Claim',
                line: 'Scans per Case Claim',
            },
            [LPR_REPOSSESSIONS_CARD_KEY]: {
                file: '/opt/cursor/artifacts/qa-2651-expand-lpr-repossessions.png',
                y: 'LPR Repossessions',
                y1: 'Scans per LPR Repo',
                line: 'Scans per LPR Repo',
            },
            [RECOVERY_COUNTS_CARD_KEY]: {
                file: '/opt/cursor/artifacts/qa-2651-expand-recovery-counts.png',
                y: 'Total Repossessions',
                y1: 'Scans per Repo',
                line: 'Scans per Repo',
            },
        };

        for (const key of DEV2651_RATIO_CARD_KEYS) {
            await expandCard(page, key);
            const chart = await readExpandedChart(page);
            const spec = expected[key];
            const line = chart.datasets.find((dataset) => dataset.label === spec.line);
            const bars = chart.datasets.find((dataset) => dataset.label !== spec.line);

            expect(chart.datasets, key).toHaveLength(2);
            expect(line?.type, key).toBe('line');
            expect(line?.yAxisID, key).toBe('y1');
            expect(bars?.yAxisID ?? 'y', key).toBe('y');
            expect(chart.yTitle, key).toBe(spec.y);
            expect(chart.y1Present, key).toBe(true);
            expect(chart.y1Title, key).toBe(spec.y1);
            expect(chart.y1DrawOnChartArea, key).toBe(false);
            expect(chart.legendDisplay, key).toBe(true);
            expect(line?.backgroundIsArray, key).toBe(false);
            expect(bars?.backgroundIsArray, key).toBe(false);
            expect(channelsClose(rgbaChannels(line?.borderColor ?? null), RATIO_AMBER), key).toBe(true);
            expect(legendMatchesDataset(chart), `${key} ${JSON.stringify(chart.legendColors)}`).toBe(true);

            await expandedDetail(page).screenshot({ path: spec.file });
            await card(page, key).click();
            await expect(expandedDetail(page)).toBeHidden();
        }
    });

    test('G expanded unique-scan-counts is a single axis with two count series', async ({ page }) => {
        await settledGrid(page);
        await expandCard(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const chart = await readExpandedChart(page);

        expect(chart.y1Present).toBe(false);
        expect(chart.datasets.map((dataset) => dataset.label).sort()).toEqual(['Total Scans', 'Unique Scans']);
        expect(chart.datasets.every((dataset) => (dataset.yAxisID ?? 'y') === 'y')).toBe(true);
        expect(chart.datasets.some((dataset) => dataset.type === 'line' && dataset.yAxisID === 'y1')).toBe(false);
        expect(chart.legendDisplay).toBe(true);
        expect(legendMatchesDataset(chart)).toBe(true);

        await expandedDetail(page).screenshot({
            path: '/opt/cursor/artifacts/qa-2651-expand-unique-scan-counts.png',
        });
    });

    test('K this month and last month refresh compact values and the open chart', async ({ page }) => {
        await settledGrid(page);
        await expandCard(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const thisMonthValue = await cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const thisMonthRecovery = await cardValueText(page, RECOVERY_COUNTS_CARD_KEY);
        const thisMonthChart = await readExpandedChart(page);

        await selectDateFilter(page, 'Last Month');
        await expect
            .poll(async () => cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY), { timeout: 20_000 })
            .not.toBe(thisMonthValue);

        const lastMonthValue = await cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        const lastMonthRecovery = await cardValueText(page, RECOVERY_COUNTS_CARD_KEY);
        const lastMonthChart = await readExpandedChart(page);

        expect(lastMonthValue).not.toBe(thisMonthValue);
        expect(lastMonthRecovery).not.toBe(thisMonthRecovery);
        expect(lastMonthChart.datasets).toHaveLength(thisMonthChart.datasets.length);
        expect(lastMonthChart.y1Present).toBe(thisMonthChart.y1Present);
        await expect(expandedDetail(page)).toBeVisible();

        await selectDateFilter(page, 'This Month');
        await expect
            .poll(async () => cardValueText(page, UNIQUE_SCAN_COUNTS_CARD_KEY), { timeout: 20_000 })
            .toBe(thisMonthValue);
        await expect(dateFilterControl(page, 'This Month')).toBeVisible();
    });

    test('L scans leaderboard drops user-facing unique and keeps its key', async ({ page }) => {
        await settledGrid(page);
        const leader = card(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);

        await expect(leader).toContainText(SCANS_LEADERBOARD_HEADING);
        await expect(leader).toHaveAttribute('data-dashboard-card', UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
        expect((await cardDescriptionText(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toLowerCase()).not.toContain(
            'unique',
        );
        expect((await leader.innerText()).toLowerCase()).not.toContain('unique');

        await expandCard(page, UNIQUE_SCANS_LEADERBOARD_CARD_KEY);
        const chart = await readExpandedChart(page);
        const detailText = (await expandedDetail(page).innerText()).toLowerCase();

        expect(detailText).not.toContain('unique');
        expect(chart.datasets.map((dataset) => dataset.label.toLowerCase()).join(' ')).not.toContain('unique');
        expect((chart.yTitle ?? '').toLowerCase()).not.toContain('unique');
        expect(detailText).toContain('distinct plates');
        expect(chart.yTitle).toBe('Distinct plates');
    });

    test('M expand, switch, collapse, and no stranded deleted-key errors', async ({ page }) => {
        const before = pageProblems.length;
        await settledGrid(page);
        await expandCard(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        await expect(page.locator('[data-dashboard-open="true"]')).toHaveCount(1);

        await expandCard(page, RECOVERY_COUNTS_CARD_KEY);
        await expect(page.locator('[data-dashboard-open="true"]')).toHaveCount(1);
        await expect(card(page, RECOVERY_COUNTS_CARD_KEY)).toHaveAttribute('data-dashboard-open', 'true');
        await expect(card(page, UNIQUE_SCAN_COUNTS_CARD_KEY)).not.toHaveAttribute('data-dashboard-open', 'true');

        const value = await cardValueText(page, RECOVERY_COUNTS_CARD_KEY);
        await selectDateFilter(page, 'Last Month');
        await expect(expandedDetail(page)).toBeVisible();
        await expect(card(page, RECOVERY_COUNTS_CARD_KEY)).toHaveAttribute('data-dashboard-open', 'true');
        await expect.poll(async () => cardValueText(page, RECOVERY_COUNTS_CARD_KEY)).not.toBe(value);

        await card(page, RECOVERY_COUNTS_CARD_KEY).click();
        await expect(expandedDetail(page)).toBeHidden();
        await expect(page.locator('[data-dashboard-open="true"]')).toHaveCount(0);

        const fresh = pageProblems.slice(before);
        const stranded = fresh.filter((line) =>
            ABSORBED_CARD_KEYS.some((key) => line.toLowerCase().includes(key)),
        );
        expect(stranded).toEqual([]);
        expect(deletedKeyMentions().filter((line) => fresh.includes(line))).toEqual([]);
    });

    test('H unavailable numerator renders -- and drops the ratio axis', async ({ page }) => {
        setNumeratorFailureMarker(PRO_ORG_ID, true);

        try {
            await settledGrid(page);

            for (const [key, suffix] of [
                [UNIQUE_LPR_CASE_CLAIMS_CARD_KEY, 'scans per case claim'],
                [LPR_REPOSSESSIONS_CARD_KEY, 'scans per LPR repo'],
                [RECOVERY_COUNTS_CARD_KEY, 'scans per repo'],
            ] as const) {
                const description = await cardDescriptionText(page, key);
                expect(description, key).toBe(`-- ${suffix}`);
                expect(description, key).not.toMatch(/^0(\.0)? /);
                expect(await cardValueText(page, key), key).not.toBe('--');
            }

            await expandCard(page, UNIQUE_LPR_CASE_CLAIMS_CARD_KEY);
            const chart = await readExpandedChart(page);
            expect(chart.datasets).toHaveLength(1);
            expect(chart.datasets[0]?.type).not.toBe('line');
            expect(chart.y1Present).toBe(false);
            expect(chart.legendDisplay).toBe(false);
            expect(pageProblems.filter((line) => line.startsWith('pageerror'))).toEqual([]);
        } finally {
            setNumeratorFailureMarker(PRO_ORG_ID, false);
        }
    });

    test('N 12-card adjacency stand-in and expanded chart screenshots', async ({ page }) => {
        const keys = await settledGrid(page);

        expect(keys.indexOf(UNIQUE_LPR_CASE_CLAIMS_CARD_KEY)).toBe(1);
        expect(keys.indexOf(LPR_REPOSSESSIONS_CARD_KEY)).toBe(2);
        expect(keys.indexOf(STAGING_LIVE_HIT_LEADER_CARD_KEY)).toBe(keys.length - 1);
        expect(keys.indexOf(UNIQUE_SCANS_LEADERBOARD_CARD_KEY)).toBe(9);
        for (const absorbed of ABSORBED_CARD_KEYS) {
            expect(keys).not.toContain(absorbed);
        }

        await page.screenshot({
            path: '/opt/cursor/artifacts/qa-2651-grid-compact.png',
            fullPage: true,
        });

        const shots: Record<string, string> = {
            [UNIQUE_SCAN_COUNTS_CARD_KEY]: '/opt/cursor/artifacts/qa-2651-n-unique-scan-counts.png',
            [UNIQUE_LPR_CASE_CLAIMS_CARD_KEY]: '/opt/cursor/artifacts/qa-2651-n-unique-lpr-case-claims.png',
            [LPR_REPOSSESSIONS_CARD_KEY]: '/opt/cursor/artifacts/qa-2651-n-lpr-repossessions.png',
            [RECOVERY_COUNTS_CARD_KEY]: '/opt/cursor/artifacts/qa-2651-n-recovery-counts.png',
        };

        for (const [key, file] of Object.entries(shots)) {
            await expandCard(page, key);
            await readExpandedChart(page);
            await page.waitForTimeout(600);
            await expandedDetail(page).screenshot({ path: file });
            await card(page, key).click();
        }

        expect([
            LIVE_HITS_CARD_KEY,
            HISTORICAL_HITS_CARD_KEY,
            STAGING_LIVE_HITS_CARD_KEY,
            STAGING_HISTORICAL_HITS_CARD_KEY,
        ].every((key) => keys.includes(key))).toBe(true);
    });
});

test.describe('non-Pro org', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, NON_PRO_EMAIL);
    });

    test('I recovery-counts headline stays open and the ratio is withheld', async ({ page }) => {
        await settledGrid(page);

        const recoveryValue = await cardValueText(page, RECOVERY_COUNTS_CARD_KEY);
        expect(recoveryValue).not.toBe(LOCKED_VALUE);
        expect(recoveryValue).toMatch(/^\d/);
        const recoveryDescription = await cardDescriptionText(page, RECOVERY_COUNTS_CARD_KEY);
        expect(recoveryDescription.toLowerCase()).not.toContain('scans per repo');
        expect(recoveryDescription).not.toContain('--');

        expect((await cardDescriptionText(page, UNIQUE_SCAN_COUNTS_CARD_KEY)).toLowerCase()).toContain('total scans');

        await expandCard(page, RECOVERY_COUNTS_CARD_KEY);
        const chart = await readExpandedChart(page);
        expect(chart.y1Present).toBe(false);
        expect(chart.datasets.some((dataset) => /scans per repo/i.test(dataset.label))).toBe(false);
        expect(chart.legendDisplay).not.toBe(true);
    });

    test('J locked cards keep their slot and do not expand', async ({ page }) => {
        const keys = await settledGrid(page);
        expect(keys).toHaveLength(12);

        const locked = [
            'missed-opportunities',
            STAGING_LIVE_HIT_LEADER_CARD_KEY,
            UNIQUE_LPR_CASE_CLAIMS_CARD_KEY,
            UNIQUE_SCANS_LEADERBOARD_CARD_KEY,
            LPR_REPOSSESSIONS_CARD_KEY,
        ];

        for (const key of locked) {
            expect(keys).toContain(key);
            expect(await cardValueText(page, key), key).toBe(LOCKED_VALUE);
            await expect(card(page, key)).not.toHaveAttribute('wire:click', /.+/);
            await card(page, key).click({ force: true });
            await expect(expandedDetail(page)).toBeHidden();
        }

        await expect(card(page, UNIQUE_SCAN_COUNTS_CARD_KEY)).toHaveAttribute('wire:click', /toggleCard/);
        await expandCard(page, UNIQUE_SCAN_COUNTS_CARD_KEY);
        await expect(expandedDetail(page)).toBeVisible();
    });
});
