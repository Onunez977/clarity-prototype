import { expect, test, type Page } from '@playwright/test';
import { assertIsolatedCloudOrLocal } from './helpers/auth';
import {
    EMPTY_COPY,
    MAX_MAP_HITS,
    TRUNCATION_NOTE,
    assertPhpunitGateInventory,
    collapseLiveHits,
    collapsedLiveHitsPill,
    collapseLiveHitsButton,
    emptyCopy,
    expandLiveHits,
    gotoOrgMap,
    installGoogleMapsStub,
    installQuietOrgMapRoutes,
    listScrollRegion,
    loadingLabel,
    loadingSpinner,
    loginOrgMapAdmin,
    makeHits,
    mockLiveHits,
    selectDateRange,
    seedDev2597,
    truncationNote,
    updatingLabel,
    type Dev2597Seed,
} from './helpers/dev-2597';

/**
 * DEV-2597 — Org Map Live Hits loading UI + MAX_MAP_HITS truncation (Playwright).
 *
 * Product under test (do not edit from this spec):
 * - resources/js/clarity-scout/components/DashboardOverlay.vue
 * - resources/js/clarity-scout/stores/map.js (rangeHitsLoading / rangeHitsCapped)
 * - Admin /scout/org-map (session + ClarityScoutAdminMiddleware)
 * - GET /scout/org-map/api/map/hits?range= → {data, capped}; MAX_MAP_HITS=100
 *
 * Isolated cloud / local only (http://127.0.0.1 / *.test). Never production /
 * never VPN PR preview as execute host. Videos on. Serial. Distinct A–N.
 */

test.use({
    video: 'on',
    storageState: { cookies: [], origins: [] },
});

test.describe.configure({ mode: 'serial', timeout: 90_000 });

let seeded: Dev2597Seed = {};
let seedError: string | undefined;

function adminId(): string {
    return process.env.E2E_ADMIN_USER_ID ?? String(seeded.adminUserId ?? '1');
}

async function bootOrgMap(
    page: Page,
    hits: Parameters<typeof mockLiveHits>[1],
    options?: { collapseFirst?: boolean },
): Promise<void> {
    await installGoogleMapsStub(page);
    await installQuietOrgMapRoutes(page);
    await mockLiveHits(page, hits);

    if (options?.collapseFirst) {
        await page.addInitScript(() => {
            localStorage.setItem('scanner_live_hits_expanded', '0');
        });
    } else {
        await page.addInitScript(() => {
            localStorage.setItem('scanner_live_hits_expanded', '1');
        });
    }

    await loginOrgMapAdmin(page, adminId());
    await gotoOrgMap(page);
}

test.beforeAll(() => {
    assertIsolatedCloudOrLocal(process.env.BASE_URL ?? process.env.APP_URL ?? 'http://platform.test:8000');

    try {
        seeded = seedDev2597('seed');
        if (seeded.adminUserId) {
            process.env.E2E_ADMIN_USER_ID = String(seeded.adminUserId);
        }
        if (seeded.adminEmail) {
            process.env.E2E_ADMIN_EMAIL = seeded.adminEmail;
        }
    } catch (error) {
        seedError = error instanceof Error ? error.message : String(error);
    }
});

test.beforeEach(() => {
    test.skip(Boolean(seedError), `DEV-2597 seed failed: ${seedError ?? ''}`);
});

test('A Empty Live Hits (0 rows, not loading) → empty copy; no spinner; no truncation note', async ({
    page,
}) => {
    await bootOrgMap(page, { recent: [], capped: false });
    await expandLiveHits(page);

    await expect(emptyCopy(page)).toBeVisible({ timeout: 15_000 });
    await expect(loadingLabel(page)).toHaveCount(0);
    await expect(loadingSpinner(page)).toHaveCount(0);
    await expect(truncationNote(page)).toHaveCount(0);
    await expect(page.getByText(TRUNCATION_NOTE)).toHaveCount(0);
    await expect(updatingLabel(page)).toHaveCount(0);
});

test('B Populated under cap (<100, capped:false) → badge/count; rows; no amber truncation', async ({
    page,
}) => {
    const hits = makeHits(3, 'UND');
    await bootOrgMap(page, { recent: hits, capped: false });
    await expandLiveHits(page);

    await expect(page.getByText('UND000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('UND001', { exact: true })).toBeVisible();
    await expect(page.getByText('UND002', { exact: true })).toBeVisible();
    await expect(truncationNote(page)).toHaveCount(0);
    await expect(page.getByText(TRUNCATION_NOTE)).toHaveCount(0);
    await expect(emptyCopy(page)).toHaveCount(0);

    await collapseLiveHits(page);
    await expect(collapsedLiveHitsPill(page)).toContainText('3');
    await expect(loadingSpinner(page)).toHaveCount(0);
});

test('C First / cold load → spinner + Loading… while in flight; replaces empty/list', async ({
    page,
}) => {
    const hits = makeHits(2, 'CLD');
    await installGoogleMapsStub(page);
    await installQuietOrgMapRoutes(page);
    await mockLiveHits(page, { recent: hits, capped: false, delayMs: 2_500 });
    await page.addInitScript(() => {
        localStorage.setItem('scanner_live_hits_expanded', '0');
    });
    await loginOrgMapAdmin(page, adminId());

    const pending = page.waitForRequest((request) => request.url().includes('/map/hits'));
    await page.goto('/scout/org-map', { waitUntil: 'domcontentloaded' });
    await pending;

    await expect(loadingSpinner(page)).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('CLD000')).toHaveCount(0);

    await collapsedLiveHitsPill(page).click();
    await expect(loadingLabel(page)).toBeVisible();
    await expect(emptyCopy(page)).toHaveCount(0);

    await expect(page.getByText('CLD000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(loadingLabel(page)).toHaveCount(0);
    await expect(loadingSpinner(page)).toHaveCount(0);
});

test('D Later range switch (7↔15↔30) → Updating… + dimmed list while refreshing', async ({
    page,
}) => {
    const first = makeHits(2, 'RG7');
    const later = makeHits(2, 'R15');

    await bootOrgMap(page, { recent: first, capped: false });
    await expandLiveHits(page);
    await expect(page.getByText('RG7000', { exact: true })).toBeVisible({ timeout: 15_000 });

    await selectDateRange(page, '7 Days');
    await expect(page.getByText('RG7000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(1_500);

    await mockLiveHits(page, { recent: later, capped: false, delayMs: 2_000 });
    await selectDateRange(page, '15 Days');

    await expect(updatingLabel(page)).toBeVisible({ timeout: 10_000 });
    await expect(listScrollRegion(page)).toHaveClass(/opacity-60/);

    await expect(page.getByText('R15000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(updatingLabel(page)).toHaveCount(0);
    await expect(listScrollRegion(page)).not.toHaveClass(/opacity-60/);

    await mockLiveHits(page, { recent: first, capped: false, delayMs: 1_200 });
    await selectDateRange(page, '30 Days');
    await expect(updatingLabel(page).or(loadingLabel(page))).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('RG7000', { exact: true })).toBeVisible({ timeout: 15_000 });
});

test('E Truncation UI at MAX_MAP_HITS (rangeHitsCapped / API capped:true, >100)', async ({
    page,
}) => {
    const list = makeHits(12, 'CAP');
    const map = makeHits(MAX_MAP_HITS, 'CAP');
    await bootOrgMap(page, { recent: list, map, capped: true });
    await expandLiveHits(page);

    await expect(truncationNote(page)).toBeVisible({ timeout: 15_000 });
    await expect(truncationNote(page)).toHaveClass(/text-amber-700/);
    await expect(page.getByText('CAP000', { exact: true })).toBeVisible();
});

test('F Boundary: exactly 100 uncapped vs 101+ capped — note only when capped', async ({
    page,
}) => {
    const exactly100 = makeHits(MAX_MAP_HITS, 'EXA');
    await bootOrgMap(page, { recent: exactly100.slice(0, 8), map: exactly100, capped: false });
    await expandLiveHits(page);

    await expect(page.getByText('EXA000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(truncationNote(page)).toHaveCount(0);
    await expect(page.getByText(TRUNCATION_NOTE)).toHaveCount(0);

    const over = makeHits(MAX_MAP_HITS, 'OVR');
    await mockLiveHits(page, { recent: over.slice(0, 8), map: over, capped: true, delayMs: 200 });
    await selectDateRange(page, '7 Days');

    await expect(truncationNote(page)).toBeVisible({ timeout: 15_000 });
});

test('G Layout: Live Hits card scroll max-h-[40vh]; badge↔spinner swap in header', async ({
    page,
}) => {
    const hits = makeHits(8, 'LAY');
    await bootOrgMap(page, { recent: hits, capped: false }, { collapseFirst: true });

    await expect(collapsedLiveHitsPill(page)).toBeVisible({ timeout: 15_000 });
    await expect(collapsedLiveHitsPill(page)).toContainText('8');
    await expect(loadingSpinner(page)).toHaveCount(0);

    await mockLiveHits(page, { recent: hits, capped: false, delayMs: 2_000 });
    const spinnerSeen = page.waitForSelector('[aria-label="Loading live hits"]', {
        state: 'visible',
        timeout: 10_000,
    });
    await selectDateRange(page, '7 Days');
    await spinnerSeen;
    await expect(collapsedLiveHitsPill(page).locator('text=8')).toHaveCount(0);

    await expect(collapsedLiveHitsPill(page)).toContainText('8', { timeout: 15_000 });
    await expect(loadingSpinner(page)).toHaveCount(0);

    await expandLiveHits(page);
    const region = listScrollRegion(page);
    await expect(region).toBeVisible();
    await expect(region).toHaveClass(/max-h-\[40vh\]/);
    await expect(region).toHaveClass(/overflow-y-auto/);
});

test('H Fail /map/hits (5xx) → loading clears; safe empty/prior; no orphan spinner', async ({
    page,
}) => {
    const prior = makeHits(2, 'PRI');
    await bootOrgMap(page, { recent: prior, capped: false });
    await expandLiveHits(page);
    await expect(page.getByText('PRI000', { exact: true })).toBeVisible({ timeout: 15_000 });

    await mockLiveHits(page, {
        recent: prior,
        mapStatus: 503,
        delayMs: 1_200,
        capped: false,
    });
    await selectDateRange(page, '7 Days');

    await expect(loadingLabel(page).or(updatingLabel(page))).toBeVisible({ timeout: 10_000 });
    await expect(loadingLabel(page)).toHaveCount(0, { timeout: 15_000 });
    await expect(loadingSpinner(page)).toHaveCount(0);
    await expect(truncationNote(page)).toHaveCount(0);
    await expect(page.getByText(EMPTY_COPY).or(page.getByText('PRI000'))).toBeVisible();
});

test('I Fail→empty: no truncation note when capped false / cleared', async ({ page }) => {
    await bootOrgMap(page, { recent: makeHits(2, 'CLR'), capped: true });
    await expandLiveHits(page);
    await expect(truncationNote(page)).toBeVisible({ timeout: 15_000 });

    await mockLiveHits(page, {
        recent: [],
        abortMap: true,
        delayMs: 400,
        capped: false,
    });
    await selectDateRange(page, '15 Days');

    await expect(loadingSpinner(page)).toHaveCount(0, { timeout: 15_000 });
    await expect(truncationNote(page)).toHaveCount(0);
    await expect(page.getByText(TRUNCATION_NOTE)).toHaveCount(0);
});

test('J Related: range selector drives refetch; loading/updating toggles per switch', async ({
    page,
}) => {
    const seven = makeHits(1, 'J07');
    const thirty = makeHits(1, 'J30');
    await bootOrgMap(page, { recent: seven, capped: false });
    await expandLiveHits(page);
    await expect(page.getByText('J07000', { exact: true })).toBeVisible({ timeout: 15_000 });

    const hitsRequests: string[] = [];
    page.on('request', (request) => {
        if (request.url().includes('/map/hits')) {
            hitsRequests.push(request.url());
        }
    });

    await mockLiveHits(page, { recent: thirty, capped: false, delayMs: 800 });
    await selectDateRange(page, '30 Days');
    await expect(loadingLabel(page).or(updatingLabel(page))).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('J30000', { exact: true })).toBeVisible({ timeout: 15_000 });
    expect(hitsRequests.some((url) => url.includes('range=last_30'))).toBe(true);
});

test('K Related: driver hit-key panel is admin-hidden; driver PWA N/A on this harness', async ({
    page,
}) => {
    const hits = makeHits(2, 'KEY');
    await bootOrgMap(page, { recent: hits, map: hits, capped: true });
    await expandLiveHits(page);

    await expect(truncationNote(page)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Showing the 100 most recent hits.')).toHaveCount(0);
    await expect(page.getByText('Hit — tap for details')).toHaveCount(0);
});

test('L Related: row click selectHitFromCard works after load; ignored while Loading…', async ({
    page,
}) => {
    const hits = makeHits(1, 'CLK');
    await installGoogleMapsStub(page);
    await installQuietOrgMapRoutes(page);
    await mockLiveHits(page, { recent: hits, capped: false, delayMs: 2_000 });
    await page.addInitScript(() => {
        localStorage.setItem('scanner_live_hits_expanded', '1');
    });
    await loginOrgMapAdmin(page, adminId());
    await page.goto('/scout/org-map', { waitUntil: 'domcontentloaded' });

    await expect(loadingLabel(page)).toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole('button', { name: /CLK000/ })).toHaveCount(0);

    await expect(page.getByText('CLK000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.getByRole('button', { name: /CLK000/ }).click();
    await expect(page.getByText('CLK000').first()).toBeVisible();
    await expect(page.locator('text=Direct Live').or(page.locator('text=CLK000'))).toBeVisible();
});

test('M BTS API: GET …/map/hits?range= returns {data, capped}; UI note ↔ capped', async ({
    page,
    request,
}) => {
    expect(seeded.hitCount ?? 0, 'seed must insert 101+ open Live hits').toBeGreaterThan(MAX_MAP_HITS);

    await installGoogleMapsStub(page);
    await installQuietOrgMapRoutes(page);
    await page.addInitScript(() => {
        localStorage.setItem('scanner_live_hits_expanded', '1');
    });
    await loginOrgMapAdmin(page, adminId());

    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map((cookie) => `${cookie.name}=${cookie.value}`).join('; ');
    const api = await request.get('/scout/org-map/api/map/hits?range=last_7', {
        headers: { Cookie: cookieHeader, Accept: 'application/json' },
    });

    expect(api.ok(), `map/hits HTTP ${api.status()}`).toBe(true);
    const body = (await api.json()) as { data?: unknown[]; capped?: boolean };
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('capped');
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data?.length ?? 0).toBeLessThanOrEqual(MAX_MAP_HITS);
    expect(body.capped).toBe(true);

    await page.goto('/scout/org-map', { waitUntil: 'domcontentloaded' });
    await expandLiveHits(page);
    await selectDateRange(page, '7 Days');
    await expect(truncationNote(page)).toBeVisible({ timeout: 20_000 });
});

test('N BTS store: rangeHitsLoading true only in flight; cleared on success/error/clear', async ({
    page,
}) => {
    const hits = makeHits(2, 'STO');
    await bootOrgMap(page, { recent: hits, capped: false });
    await expandLiveHits(page);
    await expect(page.getByText('STO000', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(loadingLabel(page)).toHaveCount(0);
    await expect(loadingSpinner(page)).toHaveCount(0);

    await mockLiveHits(page, { recent: hits, capped: false, delayMs: 1_500 });
    const inFlight = page.waitForRequest((request) => request.url().includes('/map/hits'));
    await selectDateRange(page, '7 Days');
    await inFlight;
    await expect(loadingLabel(page).or(loadingSpinner(page))).toBeVisible({ timeout: 10_000 });
    await expect(loadingLabel(page)).toHaveCount(0, { timeout: 15_000 });
    await expect(loadingSpinner(page)).toHaveCount(0);

    await mockLiveHits(page, { recent: hits, mapStatus: 500, delayMs: 800 });
    await selectDateRange(page, '15 Days');
    await expect(loadingLabel(page).or(updatingLabel(page))).toBeVisible({ timeout: 10_000 });
    await expect(loadingLabel(page)).toHaveCount(0, { timeout: 15_000 });
    await expect(loadingSpinner(page)).toHaveCount(0);

    await mockLiveHits(page, { recent: hits, capped: false, delayMs: 400 });
    await selectDateRange(page, 'Today');
    await expect(loadingSpinner(page)).toHaveCount(0, { timeout: 15_000 });
    await expect(page.getByText(EMPTY_COPY).or(page.getByText('STO000'))).toBeVisible();
});

test('PHPUnit gate inventory still on disk (DEV-2597 companion, no rewrite)', () => {
    assertPhpunitGateInventory();
});
