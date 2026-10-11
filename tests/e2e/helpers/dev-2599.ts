import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Page } from '@playwright/test';
import { loginViaOtp, rewriteForcedHttpsForLocalHttp } from './auth';
import {
    fulfillJson,
    gotoOrgMap,
    installGoogleMapsStub,
    installQuietOrgMapRoutes,
    mockLiveHits,
    selectDateRange,
    type LiveHitFixture,
} from './dev-2597';

/**
 * DEV-2599 Hit Details enlarge helpers.
 *
 * Admin org map signs in through the real /app/login form (OTP path; outside
 * production the panel completes that submit without rendering the OTP field).
 * Recent-hits is mocked with stable capture URLs so each A–N fixture is
 * deterministic. The driver case uses the real /scout login form.
 */

export const HIT_PLATE = 'ENL2599';
export const SECOND_PLATE = 'ENL2598';

export const PLATE_IMAGE_URL =
    'data:image/gif;base64,R0lGODlhAQABAIABAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

export const VEHICLE_IMAGE_URL =
    'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/** Non-empty capture URL that does not resolve to an image. */
export const BROKEN_IMAGE_URL = 'http://127.0.0.1:8000/__qa/dev-2599-missing-scan.png';

export const DRIVER_EMAIL = 'qa-dev-2597-driver@tester.com';
export const DRIVER_PASSWORD = 'password';

export const ADMIN_STORAGE_STATE = '/tmp/qa-2599-admin.json';

export type ScanImageHit = LiveHitFixture & {
    plate_image: string | null;
    vehicle_image: string | null;
};

let adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'qa-dev-2597-admin@tester.com';

export function useAdminEmail(email: string): void {
    adminEmail = email;
}

export function makeScanImageHit(overrides: Partial<ScanImageHit> = {}): ScanImageHit {
    return {
        id: 2599001,
        plate: HIT_PLATE,
        hit_time: '2026-09-22T15:00:00.000Z',
        hit_type: 'L',
        lat: 32.7767,
        lng: -96.797,
        scan_id: 2599001,
        status: 'Open',
        plate_image: PLATE_IMAGE_URL,
        vehicle_image: VEHICLE_IMAGE_URL,
        ...overrides,
    };
}

export function grantDriverScoutAccess(): void {
    const script = resolve(process.cwd(), 'tests/e2e/helpers/seed-dev-2599-driver.php');
    execSync(`php ${JSON.stringify(script)}`, { encoding: 'utf8', timeout: 60_000 });
}

export async function ensureAdminFormSession(page: Page): Promise<void> {
    await rewriteForcedHttpsForLocalHttp(page);

    await page.goto('/app', { waitUntil: 'domcontentloaded' });

    if (page.url().includes('/login')) {
        await loginViaOtp(page, adminEmail);
    }
}

export async function bootOrgMap(page: Page, hits: ScanImageHit[]): Promise<void> {
    await installGoogleMapsStub(page);
    await installQuietOrgMapRoutes(page);
    await mockLiveHits(page, { recent: hits, capped: false });
    await page.addInitScript(() => {
        localStorage.setItem('scanner_live_hits_expanded', '1');
    });
    await ensureAdminFormSession(page);
    await gotoOrgMap(page);
}

export async function bootOrgMapWithScanImages(page: Page): Promise<void> {
    await bootOrgMap(page, [makeScanImageHit()]);
}

export async function openHit(page: Page, plate: string): Promise<void> {
    await selectDateRange(page, '7 Days');
    await expect(page.getByRole('button', { name: '7 Days', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const row = page.getByRole('button', { name: new RegExp(`\\b${plate}\\b`) });
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.click();
    await expect(page.getByText(plate, { exact: true })).toBeVisible({ timeout: 15_000 });
}

export async function enlarge(page: Page, which: 'plate' | 'vehicle') {
    const label = which === 'plate' ? 'Enlarge plate image' : 'Enlarge vehicle image';
    await page.getByRole('button', { name: label, exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();

    return dialog;
}

export async function closeLightbox(page: Page, how: 'button' | 'escape' | 'backdrop'): Promise<void> {
    if (how === 'button') {
        await page.getByRole('button', { name: 'Close enlarged image', exact: true }).click();
    } else if (how === 'escape') {
        await page.keyboard.press('Escape');
    } else {
        await page.getByRole('dialog').click({ position: { x: 8, y: 8 } });
    }

    await expect(page.getByRole('dialog')).toHaveCount(0);
}

export function mapCanvas(page: Page) {
    return page.locator('div.h-screen.w-screen.overflow-hidden');
}

type ScoutPiniaState = {
    range: string | null;
    liveMode: boolean | null;
    adminMode: boolean | null;
    plate: string | null;
};

export async function readScoutState(page: Page): Promise<ScoutPiniaState> {
    return page.evaluate(() => {
        const root = document.querySelector('#clarity-scout-app') as HTMLElement & {
            __vue_app__?: {
                config: {
                    globalProperties: {
                        $pinia?: {
                            state: {
                                value: {
                                    dashboard?: { range?: string };
                                    map?: {
                                        liveMode?: boolean;
                                        adminMode?: boolean;
                                        selectedHits?: Array<{ plate?: string }>;
                                        selectedHitIndex?: number;
                                    };
                                };
                            };
                        };
                    };
                };
            };
        };
        const stores = root?.__vue_app__?.config.globalProperties.$pinia?.state.value;
        const map = stores?.map;
        const index = map?.selectedHitIndex ?? 0;
        const hit = map?.selectedHits?.[index] ?? null;

        return {
            range: stores?.dashboard?.range ?? null,
            liveMode: map?.liveMode ?? null,
            adminMode: map?.adminMode ?? null,
            plate: hit?.plate ?? null,
        };
    });
}

/**
 * DateRangeControl lives in the stat bar, and App.vue unmounts that bar while
 * a hit is selected (`showStatBar` requires `!selectedHit`). The pressed-pill
 * condition is `!liveMode && range === 'last_7'`.
 */
export async function assertSevenDaysStillSelected(page: Page): Promise<void> {
    const state = await readScoutState(page);

    expect(state.range).toBe('last_7');
    expect(state.liveMode).toBe(false);
}

export async function assertHitAndMapPreserved(page: Page, plate: string): Promise<void> {
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByText(plate, { exact: true })).toBeVisible();
    await assertSevenDaysStillSelected(page);
    await expect(mapCanvas(page)).toBeAttached();
    const state = await readScoutState(page);
    expect(state.plate).toBe(plate);
}

/**
 * The lightbox is a full-viewport overlay, so the Live Hits row underneath
 * cannot be clicked. Selecting another hit goes through the same Pinia action
 * the row uses (`focusHit` → `setSelectedHit`). MapShell watches that identity
 * and closes the lightbox.
 */
export async function selectDifferentHitWhileLightboxOpen(page: Page, hit: ScanImageHit): Promise<void> {
    await page.evaluate((next) => {
        const root = document.querySelector('#clarity-scout-app') as HTMLElement & {
            __vue_app__?: {
                config: {
                    globalProperties: {
                        $pinia?: {
                            _s: Map<string, { setSelectedHit: (value: ScanImageHit) => void }>;
                        };
                    };
                };
            };
        };
        const mapStore = root.__vue_app__?.config.globalProperties.$pinia?._s.get('map');

        if (!mapStore) {
            throw new Error('map store is not mounted');
        }

        mapStore.setSelectedHit(next);
    }, hit);
}

export function assertMapShellEnlargeHasNoAdminFork(): void {
    const shell = readFileSync(
        resolve(process.cwd(), 'resources/js/clarity-scout/components/MapShell.vue'),
        'utf8',
    );
    const start = shell.indexOf('mapStore.selectedHit.plate_image || mapStore.selectedHit.vehicle_image');
    const end = shell.indexOf('DEV-2059: driver-set disposition');
    const block = shell.slice(start, end);

    expect(start, 'MapShell image row must be present').toBeGreaterThan(-1);
    expect(end, 'MapShell image row must end before disposition').toBeGreaterThan(start);
    expect(block).toContain('Enlarge plate image');
    expect(block).toContain('Enlarge vehicle image');
    expect(block).not.toContain('adminMode');
}

async function dismissScoutUpdate(page: Page): Promise<void> {
    const dismissUpdate = page.getByRole('button', { name: 'Dismiss', exact: true });

    try {
        await dismissUpdate.waitFor({ state: 'visible', timeout: 3_000 });
        await dismissUpdate.click();
    } catch {
        // The service-worker update modal is not on this load.
    }
}

export async function bootDriverMap(page: Page, hits: ScanImageHit[]): Promise<void> {
    await rewriteForcedHttpsForLocalHttp(page);
    await installGoogleMapsStub(page);
    await page.route('**/api/scout/dashboard/**', (route) => {
        const url = route.request().url();

        if (url.includes('/recent-hits')) {
            return fulfillJson(route, { data: hits });
        }

        return fulfillJson(route, {
            data: {
                scans_today: 0,
                direct_hits: 0,
                staging_hits: 0,
                repos_affected: 0,
                trip_count: 0,
            },
        });
    });
    await page.route('**/api/scout/map/**', (route) => fulfillJson(route, { data: [], capped: false }));
    await page.route('**/api/scout/notifications**', (route) => fulfillJson(route, { notifications: [] }));
    await page.route('**/api/scout/shift/**', (route) =>
        fulfillJson(route, { data: { shift_id: null, open: false } }),
    );
    await page.route('**/api/scout/track', (route) => fulfillJson(route, { ok: true }));
    await page.addInitScript(() => {
        localStorage.setItem('scanner_live_hits_expanded', '1');
    });

    await page.goto('/scout/login', { waitUntil: 'domcontentloaded' });
    await dismissScoutUpdate(page);
    await page.getByLabel('Email').fill(DRIVER_EMAIL);
    await page.getByLabel('Password').fill(DRIVER_PASSWORD);
    await page.getByRole('button', { name: 'Sign In' }).click({ force: true });
    await expect(page).toHaveURL(/\/scout\/?$/, { timeout: 20_000 });
    await dismissScoutUpdate(page);
    await expect(page.getByText('Live Hits Pending Repossession', { exact: false }).first()).toBeVisible({
        timeout: 20_000,
    });
}

export { gotoOrgMap, selectDateRange };
