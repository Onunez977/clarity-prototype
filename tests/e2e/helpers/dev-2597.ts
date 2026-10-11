import { execSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Page, type Route } from '@playwright/test';
import {
    adminUserId,
    loginAsAdmin,
    rewriteForcedHttpsForLocalHttp,
} from './auth';

/**
 * DEV-2597 Org Map Live Hits Playwright helpers.
 *
 * Product under test (do not edit from this spec):
 * - DashboardOverlay.vue Live Hits card (adminMode on /scout/org-map)
 * - map.js fetchRangeHits / rangeHitsLoading / rangeHitsCapped
 * - BirdsEye GET /scout/org-map/api/map/hits → {data, capped} MAX_MAP_HITS=100
 */

export const ORG_MAP_PATH = '/scout/org-map';
export const MAP_HITS_PATH = '/scout/org-map/api/map/hits';
export const RECENT_HITS_PATH = '/scout/org-map/api/dashboard/recent-hits';
export const STATS_PATH = '/scout/org-map/api/dashboard/stats';
export const TRUNCATION_NOTE = 'Map shows the 100 most recent hits for this range.';
export const EMPTY_COPY = 'No live hits pending repossession.';
export const MAX_MAP_HITS = 100;

export type Dev2597Seed = {
    adminEmail?: string;
    adminUserId?: number;
    orgId?: number;
    kitId?: number;
    hotlistId?: number;
    hitCount?: number | null;
    platePrefix?: string;
    experimentalClarityScout?: boolean;
    maxMapHits?: number;
};

export type LiveHitFixture = {
    id: number;
    plate: string;
    hit_time: string;
    hit_type: 'L' | 'S';
    lat: number;
    lng: number;
    scan_id: number;
    status?: string;
};

const SEED_SCRIPT = resolve(process.cwd(), 'tests/e2e/helpers/seed-dev-2597-org-map-hits.php');

export function runSeedScript(args: string[] = []): string {
    const cmd = ['php', JSON.stringify(SEED_SCRIPT), ...args.map((arg) => JSON.stringify(arg))].join(
        ' ',
    );

    return execSync(cmd, { encoding: 'utf8', timeout: 120_000 });
}

export function seedDev2597(mode: 'seed' | 'users' = 'seed', hitCount?: number): Dev2597Seed {
    const args = mode === 'users' ? ['users'] : hitCount === undefined ? ['seed'] : ['hits', String(hitCount)];
    const out = runSeedScript(args);
    const jsonLine = out
        .trim()
        .split('\n')
        .filter((line) => line.startsWith('{'))
        .at(-1);

    return JSON.parse(jsonLine ?? '{}') as Dev2597Seed;
}

export function makeHits(count: number, prefix = 'QA'): LiveHitFixture[] {
    const now = Date.now();

    return Array.from({ length: count }, (_, index) => ({
        id: 260000 + index,
        plate: `${prefix}${String(index).padStart(3, '0')}`,
        hit_time: new Date(now - index * 60_000).toISOString(),
        hit_type: index % 5 === 0 ? 'S' : 'L',
        lat: 32.7767 + index * 0.0001,
        lng: -96.797 - index * 0.0001,
        scan_id: 360000 + index,
        status: 'Open',
    }));
}

export function mapHitsPayload(hits: LiveHitFixture[], capped: boolean): {
    data: Array<Record<string, unknown>>;
    capped: boolean;
} {
    return {
        capped,
        data: hits.map((hit) => ({
            kit_id: 1,
            plate: hit.plate,
            scan_id: hit.scan_id,
            time: hit.hit_time,
            status: hit.status ?? 'Open',
            hit_type: hit.hit_type,
            disposition: null,
            lat: hit.lat,
            lng: hit.lng,
            plate_image: null,
            vehicle_image: null,
            vehicle_year: '2021',
            vehicle_make: 'Toyota',
            vehicle_model: 'Camry',
            hotlist_us_state: 'TX',
        })),
    };
}

export function recentHitsPayload(hits: LiveHitFixture[]): { data: LiveHitFixture[] } {
    return { data: hits };
}

export async function fulfillJson(
    route: Route,
    body: unknown,
    status = 200,
    delayMs = 0,
): Promise<void> {
    if (delayMs > 0) {
        await new Promise((resolveDelay) => setTimeout(resolveDelay, delayMs));
    }

    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

export async function installGoogleMapsStub(page: Page): Promise<void> {
    await page.route('https://maps.googleapis.com/**', async (route) => {
        await route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* maps stub */' });
    });
    await page.route('https://maps.gstatic.com/**', async (route) => {
        await route.fulfill({ status: 200, body: '' });
    });
}

export async function installQuietOrgMapRoutes(page: Page): Promise<void> {
    const emptyStats = {
        data: {
            scans_today: 0,
            direct_hits: 0,
            staging_hits: 0,
            repos_affected: 0,
            trip_count: 0,
        },
    };

    await page.route(`**${STATS_PATH}**`, (route) => fulfillJson(route, emptyStats));
    await page.route('**/scout/org-map/api/drivers**', (route) => fulfillJson(route, { data: [] }));
    await page.route('**/scout/org-map/api/map/driver-positions**', (route) =>
        fulfillJson(route, { data: [] }),
    );
    await page.route('**/scout/org-map/api/map/driver-paths**', (route) =>
        fulfillJson(route, { drivers: [] }),
    );
    await page.route('**/scout/org-map/api/map/heatmap/**', (route) =>
        fulfillJson(route, { type: 'FeatureCollection', features: [] }),
    );
    await page.route('**/scout/org-map/api/map/zones**', (route) =>
        fulfillJson(route, { assigned: [] }),
    );
    await page.route('**/scout/org-map/api/map/scan-paths**', (route) => fulfillJson(route, []));
    await page.route('**/scout/org-map/api/settings/map-layers**', (route) =>
        fulfillJson(route, { enabled: ['hits', 'hotlist', 'staging'] }),
    );
}

export async function mockLiveHits(
    page: Page,
    options: {
        recent: LiveHitFixture[];
        map?: LiveHitFixture[];
        capped?: boolean;
        delayMs?: number;
        mapStatus?: number;
        abortMap?: boolean;
    },
): Promise<void> {
    const delayMs = options.delayMs ?? 0;
    const mapHits = options.map ?? options.recent;
    const capped = options.capped ?? false;

    await page.unroute(`**${RECENT_HITS_PATH}**`).catch(() => undefined);
    await page.unroute(`**${MAP_HITS_PATH}**`).catch(() => undefined);

    await page.route(`**${RECENT_HITS_PATH}**`, (route) =>
        fulfillJson(route, recentHitsPayload(options.recent), 200, delayMs),
    );

    await page.route(`**${MAP_HITS_PATH}**`, async (route) => {
        if (options.abortMap) {
            if (delayMs > 0) {
                await new Promise((resolveDelay) => setTimeout(resolveDelay, delayMs));
            }
            await route.abort();

            return;
        }

        await fulfillJson(
            route,
            mapHitsPayload(mapHits, capped),
            options.mapStatus ?? 200,
            delayMs,
        );
    });
}

export function liveHitsCard(page: Page) {
    return page.locator('div').filter({ hasText: 'Live Hits Pending Repossession' }).last();
}

export function liveHitsHeading(page: Page) {
    return page.getByText('Live Hits Pending Repossession', { exact: false }).first();
}

export function truncationNote(page: Page) {
    return page.getByRole('status').filter({ hasText: TRUNCATION_NOTE });
}

export function loadingLabel(page: Page) {
    return page.getByText('Loading…', { exact: true });
}

export function updatingLabel(page: Page) {
    return page.getByText('Updating…', { exact: true });
}

export function emptyCopy(page: Page) {
    return page.getByText(EMPTY_COPY, { exact: true });
}

export function loadingSpinner(page: Page) {
    return page.getByLabel('Loading live hits');
}

export function collapsedLiveHitsPill(page: Page) {
    return page.getByRole('button', { name: 'Show live hits pending repossession' });
}

export function collapseLiveHitsButton(page: Page) {
    return page.getByRole('button', { name: 'Collapse live hits' });
}

export async function expandLiveHits(page: Page): Promise<void> {
    const pill = collapsedLiveHitsPill(page);

    if (await pill.isVisible().catch(() => false)) {
        await pill.click();
    }

    await expect(collapseLiveHitsButton(page)).toBeVisible({ timeout: 10_000 });
}

export async function collapseLiveHits(page: Page): Promise<void> {
    const collapse = collapseLiveHitsButton(page);

    if (await collapse.isVisible().catch(() => false)) {
        await collapse.click();
    }

    await expect(collapsedLiveHitsPill(page)).toBeVisible({ timeout: 10_000 });
}

export async function loginOrgMapAdmin(page: Page, userId?: string): Promise<void> {
    await rewriteForcedHttpsForLocalHttp(page);

    if (userId) {
        process.env.E2E_ADMIN_USER_ID = userId;
    }

    await loginAsAdmin(page);
}

export async function gotoOrgMap(page: Page): Promise<void> {
    const response = await page.goto(ORG_MAP_PATH, { waitUntil: 'domcontentloaded' });

    expect(response, 'Org Map navigation must return a response').not.toBeNull();
    expect(response?.status() ?? 0, 'Org Map must not 500').toBeLessThan(500);
    await expect(page).toHaveURL(/\/scout\/org-map/);
    await expect(liveHitsHeading(page)).toBeVisible({ timeout: 20_000 });
}

export async function selectDateRange(page: Page, label: '7 Days' | '15 Days' | '30 Days' | 'Today'): Promise<void> {
    await page.getByRole('button', { name: label, exact: true }).click();
}

export function listScrollRegion(page: Page) {
    return page.locator('div.max-h-\\[40vh\\].overflow-y-auto');
}

export function assertPhpunitGateInventory(): void {
    const birdsEye = resolve(process.cwd(), 'tests/Feature/Http/Controllers/Api/ClarityScoutBirdsEyeControllerTest.php');
    const map = resolve(process.cwd(), 'tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php');

    expect(existsSync(birdsEye), 'ClarityScoutBirdsEyeControllerTest.php must remain on disk').toBe(true);
    expect(existsSync(map), 'ClarityScoutMapControllerTest.php must remain on disk').toBe(true);

    const birdsEyeSrc = readFileSync(birdsEye, 'utf8');
    const mapSrc = readFileSync(map, 'utf8');

    expect(birdsEyeSrc).toContain('test_recent_hits_multi_day_ranges_are_not_capped_to_todays_newest_twenty');
    expect(mapSrc).toContain("'capped'");
    expect(adminUserId().length).toBeGreaterThan(0);
}
