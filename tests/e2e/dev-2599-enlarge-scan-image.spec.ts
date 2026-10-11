import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { assertIsolatedCloudOrLocal, loginViaOtp, rewriteForcedHttpsForLocalHttp } from './helpers/auth';
import { seedDev2597 } from './helpers/dev-2597';
import {
    ADMIN_STORAGE_STATE,
    BROKEN_IMAGE_URL,
    HIT_PLATE,
    PLATE_IMAGE_URL,
    SECOND_PLATE,
    VEHICLE_IMAGE_URL,
    assertHitAndMapPreserved,
    assertMapShellEnlargeHasNoAdminFork,
    assertSevenDaysStillSelected,
    bootDriverMap,
    bootOrgMap,
    closeLightbox,
    enlarge,
    grantDriverScoutAccess,
    makeScanImageHit,
    mapCanvas,
    openHit,
    readScoutState,
    selectDifferentHitWhileLightboxOpen,
    useAdminEmail,
} from './helpers/dev-2599';

/**
 * DEV-2599 — Enlarge the live-hit scan image on shared MapShell Hit Details.
 *
 * Product under test (read-only):
 * - resources/js/clarity-scout/components/ScanImageLightbox.vue
 * - resources/js/clarity-scout/components/MapShell.vue
 *
 * Isolated cloud / local only. Cases A–N from the QAM freeze.
 */

const ARTIFACTS = '/opt/cursor/artifacts';

test.use({
    video: 'on',
    storageState: ADMIN_STORAGE_STATE,
    serviceWorkers: 'block',
});

test.describe.configure({ mode: 'serial', timeout: 90_000 });

test.beforeAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(120_000);
    assertIsolatedCloudOrLocal(process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000');
    mkdirSync(ARTIFACTS, { recursive: true });

    const seed = seedDev2597('users');
    useAdminEmail(seed.adminEmail ?? 'qa-dev-2597-admin@tester.com');
    grantDriverScoutAccess();

    const context = await browser.newContext({
        baseURL: process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000',
        ignoreHTTPSErrors: true,
    });
    const page = await context.newPage();
    await rewriteForcedHttpsForLocalHttp(page);
    await loginViaOtp(page, seed.adminEmail ?? 'qa-dev-2597-admin@tester.com');
    await context.storageState({ path: ADMIN_STORAGE_STATE });
    await context.close();
});

test('A Admin org map — enlarge vehicle opens dialog', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);

    const dialog = await enlarge(page, 'vehicle');
    const enlarged = dialog.locator('img');

    await expect(enlarged).toHaveAttribute('src', VEHICLE_IMAGE_URL);
    await expect(enlarged).toHaveAttribute('alt', 'Vehicle');
    await expect(enlarged).not.toHaveClass(/\bh-48\b/);
    await expect(enlarged).toHaveClass(/object-contain/);
    await page.screenshot({ path: `${ARTIFACTS}/qa-2599-lightbox-vehicle.png` });
});

test('B Close via Close button preserves hit, 7 Days, and map', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);
    await enlarge(page, 'vehicle');
    await closeLightbox(page, 'button');
    await assertHitAndMapPreserved(page, HIT_PLATE);
});

test('C Enlarge plate image shows plate src and object-contain', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);

    const dialog = await enlarge(page, 'plate');
    const enlarged = dialog.locator('img');

    await expect(enlarged).toHaveAttribute('src', PLATE_IMAGE_URL);
    await expect(enlarged).toHaveAttribute('alt', 'Plate');
    await expect(enlarged).toHaveClass(/object-contain/);
    await expect(enlarged).not.toHaveClass(/\bh-48\b/);
    await page.screenshot({ path: `${ARTIFACTS}/qa-2599-lightbox-plate.png` });
});

test('D Close via Escape preserves hit, range, and map', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);
    await enlarge(page, 'plate');
    await closeLightbox(page, 'escape');
    await assertHitAndMapPreserved(page, HIT_PLATE);
});

test('E Close via backdrop click preserves hit, range, and map', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);
    await enlarge(page, 'vehicle');
    await closeLightbox(page, 'backdrop');
    await assertHitAndMapPreserved(page, HIT_PLATE);
});

test('F Plate then vehicle sequence does not keep a stale src', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);

    const plateDialog = await enlarge(page, 'plate');
    await expect(plateDialog.locator('img')).toHaveAttribute('src', PLATE_IMAGE_URL);
    await expect(plateDialog.locator('img')).toHaveAttribute('alt', 'Plate');
    await closeLightbox(page, 'button');

    const vehicleDialog = await enlarge(page, 'vehicle');
    const enlarged = vehicleDialog.locator('img');
    await expect(enlarged).toHaveAttribute('src', VEHICLE_IMAGE_URL);
    await expect(enlarged).toHaveAttribute('alt', 'Vehicle');
    await expect(enlarged).not.toHaveAttribute('src', PLATE_IMAGE_URL);
});

test('G Hit with only plate_image shows plate enlarge only', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit({ vehicle_image: null })]);
    await openHit(page, HIT_PLATE);

    await expect(page.getByRole('button', { name: 'Enlarge plate image', exact: true })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Plate', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enlarge vehicle image', exact: true })).toHaveCount(0);
    await expect(page.getByRole('img', { name: 'Vehicle', exact: true })).toHaveCount(0);
});

test('H Hit with only vehicle_image shows vehicle enlarge only', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit({ plate_image: null })]);
    await openHit(page, HIT_PLATE);

    await expect(page.getByRole('button', { name: 'Enlarge vehicle image', exact: true })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Vehicle', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enlarge plate image', exact: true })).toHaveCount(0);
    await expect(page.getByRole('img', { name: 'Plate', exact: true })).toHaveCount(0);
});

test('I Hit with neither image does not render enlarge controls', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit({ plate_image: null, vehicle_image: null })]);
    await openHit(page, HIT_PLATE);

    await expect(page.getByText(HIT_PLATE, { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enlarge plate image', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Enlarge vehicle image', exact: true })).toHaveCount(0);
    await expect(page.getByRole('img', { name: 'Plate', exact: true })).toHaveCount(0);
    await expect(page.getByRole('img', { name: 'Vehicle', exact: true })).toHaveCount(0);
    await expect(mapCanvas(page)).toBeAttached();
});

test('J Selecting a different hit closes the open lightbox', async ({ page }) => {
    await bootOrgMap(page, [
        makeScanImageHit(),
        makeScanImageHit({
            id: 2599002,
            plate: SECOND_PLATE,
            scan_id: 2599002,
            lat: 32.78,
            lng: -96.8,
        }),
    ]);
    await openHit(page, HIT_PLATE);
    await enlarge(page, 'vehicle');
    await expect(page.getByRole('dialog')).toBeVisible();

    await selectDifferentHitWhileLightboxOpen(
        page,
        makeScanImageHit({
            id: 2599002,
            plate: SECOND_PLATE,
            scan_id: 2599002,
            lat: 32.78,
            lng: -96.8,
        }),
    );

    await expect(page.getByRole('dialog')).toHaveCount(0);
    const state = await readScoutState(page);
    expect(state.plate).toBe(SECOND_PLATE);
    await expect(page.getByText(SECOND_PLATE, { exact: true })).toBeVisible();
    await expect(mapCanvas(page)).toBeAttached();
});

test('K Driver MapShell shows the same enlarge controls', async ({ page }) => {
    assertMapShellEnlargeHasNoAdminFork();
    await bootDriverMap(page, [makeScanImageHit()]);

    const before = await readScoutState(page);
    expect(before.adminMode).toBe(false);

    await openHit(page, HIT_PLATE);
    await expect(page.getByRole('button', { name: 'Enlarge plate image', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enlarge vehicle image', exact: true })).toBeVisible();

    const dialog = await enlarge(page, 'vehicle');
    await expect(dialog.locator('img')).toHaveAttribute('src', VEHICLE_IMAGE_URL);
    await expect(dialog.locator('img')).toHaveClass(/object-contain/);

    const during = await readScoutState(page);
    expect(during.adminMode).toBe(false);
    expect(during.plate).toBe(HIT_PLATE);
});

test('L Enlarge open does not clear the hit or change the date range', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);
    await enlarge(page, 'plate');

    await expect(page.getByRole('dialog')).toBeVisible();
    const state = await readScoutState(page);
    expect(state.plate).toBe(HIT_PLATE);
    expect(state.range).toBe('last_7');
    expect(state.liveMode).toBe(false);
    await assertSevenDaysStillSelected(page);
    await expect(page.getByText(HIT_PLATE, { exact: true })).toBeVisible();
    await expect(mapCanvas(page)).toBeAttached();
});

test('M Dialog a11y shell focuses Close and exposes dialog semantics', async ({ page }) => {
    await bootOrgMap(page, [makeScanImageHit()]);
    await openHit(page, HIT_PLATE);
    const dialog = await enlarge(page, 'vehicle');
    const close = page.getByRole('button', { name: 'Close enlarged image', exact: true });

    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect(dialog).toHaveAttribute('role', 'dialog');
    await expect(close).toBeFocused();
    await page.screenshot({ path: `${ARTIFACTS}/qa-2599-a11y-dialog.png` });
});

test('N Broken image src opens the dialog and close restores the sheet', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => {
        pageErrors.push(error.message);
    });

    await bootOrgMap(page, [makeScanImageHit({ vehicle_image: BROKEN_IMAGE_URL })]);
    await openHit(page, HIT_PLATE);
    const dialog = await enlarge(page, 'vehicle');
    const enlarged = dialog.locator('img');

    await expect(enlarged).toHaveAttribute('src', BROKEN_IMAGE_URL);
    await expect(mapCanvas(page)).toBeAttached();
    const during = await readScoutState(page);
    expect(during.plate).toBe(HIT_PLATE);

    await closeLightbox(page, 'button');
    await assertHitAndMapPreserved(page, HIT_PLATE);
    expect(pageErrors).toEqual([]);
});
